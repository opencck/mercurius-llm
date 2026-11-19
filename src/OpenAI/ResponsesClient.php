<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenAI;

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
 * The OpenAI Responses API (`POST /v1/responses`): the API of the codex models and of the models that always reason,
 * which take function tools here and not in Chat Completions. Nothing is stored on the provider side (`store: false`),
 * so every request carries the whole conversation, as with Chat Completions. The API reports no cost:
 * `Usage::$costUsd` stays null and the caller estimates it from prices.
 */
final class ResponsesClient implements LlmClient
{
    private readonly HttpTransport $transport;

    /**
     * @param float $firstEventTimeout Seconds a stream may go without its first event.
     * @param float $eventTimeout Seconds a stream may go between events.
     */
    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = OpenAIClient::DEFAULT_BASE_URL,
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
        private readonly float $firstEventTimeout = 240.0,
        private readonly float $eventTimeout = 120.0,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenAI API key must not be empty');
        }
        $this->transport = new HttpTransport($http, $apiKey, $baseUrl, null, null, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes);
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        try {
            $response = $this->transport->send('POST', '/responses', ResponsesMapper::toPayload($request, false), 'application/json', $cancellation);

            return ResponsesMapper::toResponse($this->transport->decodeJson($response, $cancellation));
        } catch (LlmException $e) {
            throw EventStream::named('OpenAI', $e);
        }
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        try {
            $response = $this->transport->send('POST', '/responses', ResponsesMapper::toPayload($request, true), 'text/event-stream', $cancellation);
        } catch (LlmException $e) {
            throw EventStream::named('OpenAI', $e);
        }

        $accumulator = new ResponsesAccumulator();
        foreach (EventStream::events($response, 'OpenAI', $this->firstEventTimeout, $this->eventTimeout, $cancellation) as $data) {
            try {
                $events = $accumulator->push($data);
            } catch (LlmException $e) {
                throw EventStream::named('OpenAI', $e);
            }
            foreach ($events as $event) {
                yield $event;
            }
            if ($accumulator->finished()) {
                break;
            }
        }

        if (!$accumulator->finished()) {
            throw new LlmException(ErrorKind::Network, 'OpenAI: stream ended before the answer was complete');
        }

        yield new StreamEnd($accumulator->response());
    }
}
