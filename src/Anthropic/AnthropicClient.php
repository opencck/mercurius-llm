<?php

declare(strict_types=1);

namespace Mercurius\Llm\Anthropic;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpClient;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\ErrorClassifier;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\SseParser;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\StreamEvent;

/**
 * Claude through the Anthropic Messages API (`POST /v1/messages`).
 *
 * Plain HTTP over the AMPHP client rather than the official SDK: the SDK's transport blocks the event loop, and the
 * agent's calls must not. The key goes in `x-api-key`, the API version is pinned in `anthropic-version`. That
 * version refuses sampling fields, so the temperature of a request is not sent; the reasoning effort goes in
 * `output_config.effort`. Thinking is returned as reasoning text and not sent back with the history. The API reports
 * no cost: `Usage::$costUsd` stays null and the caller estimates it from prices.
 */
final class AnthropicClient implements LlmClient
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    public const API_VERSION = '2026-10-21';

    private readonly HttpTransport $transport;

    /**
     * @param float $connectTimeout Seconds for TCP connect and for the TLS handshake.
     * @param float $inactivityTimeout Maximum silence on the connection, in seconds.
     * @param float $transferTimeout Maximum duration of the whole request including the body, in seconds.
     * @param int $maxResponseBytes Maximum response body size.
     * @param float $firstEventTimeout Seconds a stream may go without its first data event. A stalled stream is a
     *                                   retryable Timeout.
     * @param float $eventTimeout Seconds a stream may go between data events; `ping` events count, as the API sends
     *                              them only while the model works.
     */
    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
        private readonly float $firstEventTimeout = 240.0,
        private readonly float $eventTimeout = 120.0,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Anthropic API key must not be empty');
        }
        $this->transport = new HttpTransport(
            $http,
            null,
            $baseUrl,
            null,
            null,
            $connectTimeout,
            $inactivityTimeout,
            $transferTimeout,
            $maxResponseBytes,
            ['x-api-key' => $apiKey, 'anthropic-version' => self::API_VERSION],
        );
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        try {
            $response = $this->transport->send('POST', '/messages', MessagesMapper::toPayload($request, false), 'application/json', $cancellation);

            return MessagesMapper::toResponse($this->transport->decodeJson($response, $cancellation));
        } catch (LlmException $e) {
            throw self::named($e);
        }
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        try {
            $response = $this->transport->send('POST', '/messages', MessagesMapper::toPayload($request, true), 'text/event-stream', $cancellation);
        } catch (LlmException $e) {
            throw self::named($e);
        }

        $body = $response->getBody();
        $parser = new SseParser();
        $accumulator = new MessagesAccumulator();
        // ref: references/hermes-agent/agent/chat_completion_helpers.py@e96221fa:L2835-L2842 (_count_chunk) — only
        //      events re-arm the stall timer; the same loop as the Chat Completions stream.
        $deadline = \microtime(true) + $this->firstEventTimeout;
        $limit = $this->firstEventTimeout;

        try {
            while (!$accumulator->finished()) {
                $left = $deadline - \microtime(true);
                if ($left <= 0) {
                    throw self::stalled($limit);
                }
                $timeout = new TimeoutCancellation($left);
                try {
                    $chunk = $body->read($cancellation === null ? $timeout : new CompositeCancellation($cancellation, $timeout));
                } catch (StreamException $e) {
                    throw self::named(ErrorClassifier::fromStreamException($e));
                } catch (CancelledException $e) {
                    if (self::requested($cancellation)) {
                        throw $e;
                    }
                    throw self::stalled($limit);
                }

                try {
                    $payloads = $chunk === null ? \array_filter([$parser->finish()], static fn(?string $p): bool => $p !== null) : $parser->push($chunk);
                } catch (LlmException $e) {
                    throw self::named($e);
                }
                if ($payloads !== []) {
                    $deadline = \microtime(true) + $this->eventTimeout;
                    $limit = $this->eventTimeout;
                }
                foreach ($payloads as $payload) {
                    try {
                        $events = $accumulator->push(HttpTransport::decode($payload));
                    } catch (LlmException $e) {
                        throw self::named($e);
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

        if (!$accumulator->finished()) {
            throw new LlmException(ErrorKind::Network, 'Anthropic: stream ended before the answer was complete');
        }

        yield new StreamEnd($accumulator->response());
    }

    /** Whether the caller (not the stall timer) cancelled. */
    private static function requested(?Cancellation $cancellation): bool
    {
        return $cancellation !== null && $cancellation->isRequested();
    }

    private static function stalled(float $seconds): LlmException
    {
        return new LlmException(ErrorKind::Timeout, \sprintf('Anthropic: no stream events for %d s', (int) \ceil($seconds)));
    }

    /** The shared transport says "OpenRouter" in its errors; here it is Anthropic. */
    private static function named(LlmException $e): LlmException
    {
        if (!\str_starts_with($e->getMessage(), 'OpenRouter')) {
            return $e;
        }

        return new LlmException($e->kind, 'Anthropic' . \substr($e->getMessage(), \strlen('OpenRouter')), $e->status, $e->retryAfter, $e);
    }
}
