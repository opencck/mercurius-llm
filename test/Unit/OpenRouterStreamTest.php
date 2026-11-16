<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\Http\Client\SocketException;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\StreamEvent;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCallDelta;

final class OpenRouterStreamTest extends AsyncTestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private static function chunk(array $data): string
    {
        return 'data: ' . \json_encode($data, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    private static function request(): ChatRequest
    {
        return new ChatRequest('openai/gpt-4o-mini', [Message::user('Hi')]);
    }

    /**
     * @param list<string> $chunks
     * @return list<StreamEvent>
     */
    private static function collect(FakeHttp $http, array $chunks): array
    {
        $http->respond(200, $chunks, ['content-type' => 'text/event-stream']);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        $events = [];
        foreach ($client->stream(self::request()) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    public function testTextStreamWithCommentsUsageChunkAndDone(): void
    {
        $http = new FakeHttp();
        $events = self::collect($http, [
            ": OPENROUTER PROCESSING\n\n",
            self::chunk(['id' => 'gen-1', 'model' => 'openai/gpt-4o-mini', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning' => 'hmm']]]]),
            self::chunk(['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]]),
            ": OPENROUTER PROCESSING\n\n" . self::chunk(['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]]),
            // OpenRouter repeats finish_reason on the usage chunk: it must not produce a second end.
            self::chunk(['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => 'length']], 'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'prompt_tokens_details' => ['cached_tokens' => 4],
                'completion_tokens_details' => ['reasoning_tokens' => 1],
                'cost' => 0.00012,
            ]]),
            "data: [DONE]\n\n",
        ]);

        self::assertCount(4, $events);
        self::assertInstanceOf(ReasoningDelta::class, $events[0]);
        self::assertInstanceOf(TextDelta::class, $events[1]);
        self::assertSame('Hel', $events[1]->text);
        self::assertInstanceOf(TextDelta::class, $events[2]);
        $end = $events[3];
        self::assertInstanceOf(StreamEnd::class, $end);

        $response = $end->response;
        self::assertSame('Hello', $response->text);
        self::assertSame('hmm', $response->reasoning);
        self::assertSame(FinishReason::Stop, $response->finishReason);
        self::assertSame('gen-1', $response->id);
        self::assertSame('openai/gpt-4o-mini', $response->model);
        self::assertSame(6, $response->usage->inputTokens);
        self::assertSame(4, $response->usage->cacheReadTokens);
        self::assertSame(10, $response->usage->promptTokens());
        self::assertSame(5, $response->usage->outputTokens);
        self::assertSame(1, $response->usage->reasoningTokens);
        self::assertSame(0.00012, $response->usage->costUsd);

        self::assertTrue($http->lastPayload()['stream']);
        self::assertSame(['include_usage' => true], $http->lastPayload()['stream_options']);
        self::assertSame('text/event-stream', $http->requests[0]->getHeader('accept'));
    }

    public function testFragmentedToolCallsAreAssembledByIndex(): void
    {
        $http = new FakeHttp();
        $raw = self::chunk(['id' => 'gen-2', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '']],
        ]]]]])
            . self::chunk(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"city":']],
                ['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'time', 'arguments' => '{}']],
            ]]]]])
            . self::chunk(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"Paris"}']],
            ]], 'finish_reason' => 'tool_calls']]])
            . "data: [DONE]\n\n";
        // Split at arbitrary byte boundaries to exercise buffering.
        $events = self::collect($http, \str_split($raw, 7));

        $deltas = \array_values(\array_filter($events, static fn (StreamEvent $e): bool => $e instanceof ToolCallDelta));
        self::assertCount(4, $deltas);
        self::assertInstanceOf(ToolCallDelta::class, $deltas[0]);
        self::assertSame('call_a', $deltas[0]->id);
        self::assertSame('weather', $deltas[0]->name);
        self::assertInstanceOf(ToolCallDelta::class, $deltas[1]);
        self::assertNull($deltas[1]->id);
        self::assertSame('{"city":', $deltas[1]->argumentsFragment);

        $end = \end($events);
        self::assertInstanceOf(StreamEnd::class, $end);
        $calls = $end->response->toolCalls;
        self::assertCount(2, $calls);
        self::assertSame('call_a', $calls[0]->id);
        self::assertSame('weather', $calls[0]->name);
        self::assertSame(['city' => 'Paris'], $calls[0]->arguments());
        self::assertSame('call_b', $calls[1]->id);
        self::assertSame([], $calls[1]->arguments());
        self::assertSame(FinishReason::ToolCalls, $end->response->finishReason);
    }

    public function testMidStreamErrorChunkThrows(): void
    {
        $http = new FakeHttp();
        $http->respond(200, [
            self::chunk(['id' => 'gen-3', 'choices' => [['index' => 0, 'delta' => ['content' => 'partial']]]]),
            self::chunk([
                'id' => 'gen-3',
                'error' => ['code' => 502, 'message' => 'Provider disconnected unexpectedly'],
                'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => 'error']],
            ]),
        ]);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        $received = [];
        try {
            foreach ($client->stream(self::request()) as $event) {
                $received[] = $event;
            }
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
            self::assertSame(502, $e->status);
            self::assertTrue($e->retryable());
            self::assertStringContainsString('Provider disconnected', $e->getMessage());
        }
        self::assertCount(1, $received);
    }

    public function testStreamEndingWithoutFinishIsNetworkError(): void
    {
        $http = new FakeHttp();
        $http->respond(200, [self::chunk(['id' => 'gen-4', 'choices' => [['index' => 0, 'delta' => ['content' => 'cut']]]])]);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        $this->expectException(LlmException::class);
        foreach ($client->stream(self::request()) as $_) {
            // drain
        }
    }

    public function testStreamWithoutDoneButWithFinishReasonSucceeds(): void
    {
        $http = new FakeHttp();
        $events = self::collect($http, [
            self::chunk(['id' => 'gen-5', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]),
        ]);

        self::assertInstanceOf(StreamEnd::class, $events[1]);
        self::assertSame('ok', $events[1]->response->text);
    }

    public function testAbandonedStreamClosesTheBody(): void
    {
        $http = new FakeHttp();
        $http->respond(200, [
            self::chunk(['id' => 'gen-6', 'choices' => [['index' => 0, 'delta' => ['content' => 'a']]]]),
            self::chunk(['id' => 'gen-6', 'choices' => [['index' => 0, 'delta' => ['content' => 'b']]]]),
        ]);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        $stream = $client->stream(self::request());
        foreach ($stream as $_) {
            break;
        }
        self::assertFalse($http->streams[0]->isClosed());
        unset($stream);

        self::assertTrue($http->streams[0]->isClosed());
    }

    public function testMidStreamErrorClosesTheBody(): void
    {
        $http = new FakeHttp();
        $http->respond(200, [self::chunk(['error' => ['code' => 429, 'message' => 'Rate limited upstream']]), self::chunk(['x' => 1])]);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        try {
            foreach ($client->stream(self::request()) as $_) {
                // drain
            }
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
        }
        self::assertTrue($http->streams[0]->isClosed());
    }

    public function testHttpErrorBeforeStreamIsClassified(): void
    {
        $http = new FakeHttp();
        $http->respond(429, '{"error":{"code":429,"message":"Rate limit exceeded"}}', ['retry-after' => '7']);
        $client = new OpenRouterClient($http->client(), 'sk-test');

        try {
            foreach ($client->stream(self::request()) as $_) {
                // drain
            }
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertSame(7.0, $e->retryAfter);
        }
    }

    public function testNetworkFailureIsClassified(): void
    {
        $http = new FakeHttp();
        $http->fail(new SocketException('Connection refused'));
        $client = new OpenRouterClient($http->client(), 'sk-test');

        try {
            $client->complete(self::request());
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Network, $e->kind);
            self::assertTrue($e->retryable());
        }
    }
}
