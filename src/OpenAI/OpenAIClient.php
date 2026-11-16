<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenAI;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\OpenRouter\ChatCompletions;
use Mercurius\Llm\OpenRouter\Dialect;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\StreamEvent;

/**
 * Chat Completions of the OpenAI API (`POST /v1/chat/completions`): the same protocol as OpenRouter, with
 * `max_completion_tokens`, a `reasoning_effort` string and no temperature for reasoning models. The API reports no
 * cost: `Usage::$costUsd` stays null and the caller estimates it from prices.
 */
final class OpenAIClient implements LlmClient
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private readonly ChatCompletions $completions;

    /**
     * @param float $connectTimeout Seconds for TCP connect and for the TLS handshake.
     * @param float $inactivityTimeout Maximum silence on the connection, in seconds.
     * @param float $transferTimeout Maximum duration of the whole request including the body, in seconds.
     * @param int $maxResponseBytes Maximum response body size.
     * @param float $firstEventTimeout Seconds a stream may go without its first data event; keep-alive comments do
     *                                   not count. A stalled stream is a retryable Timeout.
     * @param float $eventTimeout Seconds a stream may go between data events.
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
        float $firstEventTimeout = 240.0,
        float $eventTimeout = 120.0,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenAI API key must not be empty');
        }
        $this->completions = new ChatCompletions(
            new HttpTransport($http, $apiKey, $baseUrl, null, null, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes),
            Dialect::OpenAI,
            'OpenAI',
            $firstEventTimeout,
            $eventTimeout,
        );
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        return $this->completions->complete($request, $cancellation);
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        return $this->completions->stream($request, $cancellation);
    }
}
