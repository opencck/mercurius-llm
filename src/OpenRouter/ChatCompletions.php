<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\StreamEvent;

/**
 * The Chat Completions protocol shared by OpenRouter and the OpenAI API: one request, or a stream of server-sent
 * events folded into deltas and a final answer. Errors name the provider.
 *
 * @internal
 */
final class ChatCompletions
{
    /**
     * @param float $firstEventTimeout seconds a stream may go without its first data event
     * @param float $eventTimeout seconds a stream may go between data events; keep-alive comments do not count
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly Dialect $dialect,
        private readonly string $provider,
        private readonly float $firstEventTimeout = 240.0,
        private readonly float $eventTimeout = 120.0,
    ) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        try {
            $response = $this->transport->send(
                'POST',
                '/chat/completions',
                PayloadMapper::toPayload($request, false, $this->dialect),
                'application/json',
                $cancellation,
            );

            return PayloadMapper::toResponse($this->transport->decodeJson($response, $cancellation));
        } catch (LlmException $e) {
            throw $this->named($e);
        }
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        try {
            $response = $this->transport->send(
                'POST',
                '/chat/completions',
                PayloadMapper::toPayload($request, true, $this->dialect),
                'text/event-stream',
                $cancellation,
            );
        } catch (LlmException $e) {
            throw $this->named($e);
        }

        $body = $response->getBody();
        $parser = new SseParser();
        $accumulator = new StreamAccumulator();
        $done = false;
        // ref: references/hermes-agent/agent/chat_completion_helpers.py@e96221fa:L2835-L2842 (_count_chunk) — only real
        //      chunks re-arm the stale timer; OpenRouter's keep-alive comments keep the socket busy but say nothing.
        //      The HTTP inactivity timeout counts bytes, so it never fires on them.
        $deadline = \microtime(true) + $this->firstEventTimeout;
        $limit = $this->firstEventTimeout;

        try {
            while (!$done) {
                $left = $deadline - \microtime(true);
                if ($left <= 0) {
                    throw $this->stalled($limit);
                }
                $timeout = new TimeoutCancellation($left);
                try {
                    $chunk = $body->read($cancellation === null ? $timeout : new CompositeCancellation($cancellation, $timeout));
                } catch (StreamException $e) {
                    throw $this->named(ErrorClassifier::fromStreamException($e));
                } catch (CancelledException $e) {
                    if (self::requested($cancellation)) {
                        throw $e;
                    }
                    throw $this->stalled($limit);
                }

                $payloads = $chunk === null ? [$parser->finish()] : $parser->push($chunk);
                if (\array_filter($payloads, static fn(?string $p): bool => $p !== null) !== []) {
                    $deadline = \microtime(true) + $this->eventTimeout;
                    $limit = $this->eventTimeout;
                }
                foreach ($payloads as $payload) {
                    if ($payload === null) {
                        continue;
                    }
                    if (\trim($payload) === '[DONE]') {
                        $done = true;
                        break;
                    }
                    try {
                        $events = $accumulator->push(HttpTransport::decode($payload));
                    } catch (LlmException $e) {
                        throw $this->named($e);
                    }
                    foreach ($events as $event) {
                        yield $event;
                    }
                }
                if ($chunk === null) {
                    break;
                }
            }
        } finally {
            $body->close();
        }

        if (!$done && !$accumulator->finished()) {
            throw new LlmException(ErrorKind::Network, $this->provider . ': stream ended before the answer was complete');
        }

        yield new StreamEnd($accumulator->response());
    }

    /** Whether the caller (not the stall timer) cancelled. */
    private static function requested(?Cancellation $cancellation): bool
    {
        return $cancellation !== null && $cancellation->isRequested();
    }

    /** A retryable timeout: before the first event a retry is safe, after it the caller decides. */
    private function stalled(float $seconds): LlmException
    {
        return new LlmException(ErrorKind::Timeout, \sprintf('%s: no stream events for %d s', $this->provider, (int) \ceil($seconds)));
    }

    /** The shared parts say "OpenRouter"; another provider gets its own name in the message. */
    private function named(LlmException $e): LlmException
    {
        if ($this->provider === 'OpenRouter' || !\str_starts_with($e->getMessage(), 'OpenRouter')) {
            return $e;
        }

        return new LlmException(
            $e->kind,
            $this->provider . \substr($e->getMessage(), \strlen('OpenRouter')),
            $e->status,
            $e->retryAfter,
            $e,
            $e->resetsAt,
            $e->requestId,
        );
    }
}
