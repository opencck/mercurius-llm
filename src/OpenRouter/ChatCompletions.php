<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
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
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly Dialect $dialect,
        private readonly string $provider,
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

        try {
            while (!$done) {
                try {
                    $chunk = $body->read($cancellation);
                } catch (StreamException $e) {
                    throw $this->named(ErrorClassifier::fromStreamException($e));
                }

                $payloads = $chunk === null ? [$parser->finish()] : $parser->push($chunk);
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

    /** The shared parts say "OpenRouter"; another provider gets its own name in the message. */
    private function named(LlmException $e): LlmException
    {
        if ($this->provider === 'OpenRouter' || !\str_starts_with($e->getMessage(), 'OpenRouter')) {
            return $e;
        }

        return new LlmException($e->kind, $this->provider . \substr($e->getMessage(), \strlen('OpenRouter')), $e->status, $e->retryAfter, $e);
    }
}
