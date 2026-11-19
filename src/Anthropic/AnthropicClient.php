<?php

declare(strict_types=1);

namespace Mercurius\Llm\Anthropic;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\EventStream;
use Mercurius\Llm\OpenRouter\HttpTransport;
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

    /**
     * Claude in Amazon Bedrock: the same Messages API at `https://bedrock-mantle.{region}.api.aws/anthropic`, with a
     * Bedrock API key (a bearer token) in `x-api-key` instead of SigV4 signing, and model ids like
     * `anthropic.claude-opus-5-5`.
     *
     * ref: references/hermes-agent/agent/bedrock_adapter.py@e96221fa:L239-L274 (bedrock_openai_base_url,
     *      resolve_bedrock_bearer_token) — the Mantle host by region and the bearer token of AWS_BEARER_TOKEN_BEDROCK;
     *      hermes also signs with SigV4 through boto3, which needs the AWS SDK and is left out.
     */
    public static function bedrock(
        HttpClient $http,
        #[\SensitiveParameter]
        string $token,
        string $region,
        float $firstEventTimeout = 240.0,
        float $eventTimeout = 120.0,
    ): self {
        // The region becomes a host name: only a region name may go there.
        if (\preg_match('/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/', $region) !== 1) {
            throw new \InvalidArgumentException('Bedrock region must look like us-east-1');
        }

        return new self($http, $token, \sprintf('https://bedrock-mantle.%s.api.aws/anthropic/v1', $region), firstEventTimeout: $firstEventTimeout, eventTimeout: $eventTimeout);
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

        $accumulator = new MessagesAccumulator();
        foreach (EventStream::events($response, 'Anthropic', $this->firstEventTimeout, $this->eventTimeout, $cancellation) as $data) {
            foreach ($accumulator->push($data) as $event) {
                yield $event;
            }
            if ($accumulator->finished()) {
                break;
            }
        }

        if (!$accumulator->finished()) {
            throw new LlmException(ErrorKind::Network, 'Anthropic: stream ended before the answer was complete');
        }

        yield new StreamEnd($accumulator->response());
    }

    private static function named(LlmException $e): LlmException
    {
        return EventStream::named('Anthropic', $e);
    }
}
