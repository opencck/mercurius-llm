<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\StreamEvent;

/**
 * {@see LlmClient} for OpenRouter's OpenAI-compatible Chat Completions API.
 *
 * Any {@see HttpClient} is accepted, so the caller decides how connections are made (direct, proxy, VLESS...).
 * The client does not retry: wrap it in {@see \Mercurius\Llm\RetryingClient} for that.
 */
final class OpenRouterClient implements LlmClient
{
    public const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

    private readonly HttpTransport $transport;

    /**
     * @param string|null $appName Sent as `X-Title` (app attribution on openrouter.ai).
     * @param string|null $appUrl Sent as `HTTP-Referer`.
     * @param float $connectTimeout Seconds for TCP connect and for the TLS handshake.
     * @param float $inactivityTimeout Maximum silence on the connection, in seconds. OpenRouter sends keep-alive
     *                                 comments while a model is thinking in streaming mode; a non-streaming call is
     *                                 silent until the whole answer is ready, so keep this generous.
     * @param float $transferTimeout Maximum duration of the whole request including the body, in seconds.
     * @param int $maxResponseBytes Maximum response body size.
     */
    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $appName = null,
        ?string $appUrl = null,
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenRouter API key must not be empty');
        }
        $this->transport = new HttpTransport(
            $http,
            $apiKey,
            $baseUrl,
            $appName,
            $appUrl,
            $connectTimeout,
            $inactivityTimeout,
            $transferTimeout,
            $maxResponseBytes,
        );
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        $response = $this->transport->send(
            'POST',
            '/chat/completions',
            PayloadMapper::toPayload($request, false),
            'application/json',
            $cancellation,
        );

        return PayloadMapper::toResponse($this->transport->decodeJson($response, $cancellation));
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        $response = $this->transport->send(
            'POST',
            '/chat/completions',
            PayloadMapper::toPayload($request, true),
            'text/event-stream',
            $cancellation,
        );

        $body = $response->getBody();
        $parser = new SseParser();
        $accumulator = new StreamAccumulator();
        $done = false;

        try {
            while (!$done) {
                try {
                    $chunk = $body->read($cancellation);
                } catch (StreamException $e) {
                    throw ErrorClassifier::fromStreamException($e);
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
                    foreach ($accumulator->push(HttpTransport::decode($payload)) as $event) {
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
            throw new LlmException(ErrorKind::Network, 'OpenRouter: stream ended before the answer was complete');
        }

        yield new StreamEnd($accumulator->response());
    }
}
