<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenAI\OpenAIClient;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\TextDelta;

final class OpenAIClientTest extends AsyncTestCase
{
    private const OK_BODY = '{"id":"chatcmpl-1","model":"gpt-6-luna","choices":[{"index":0,"finish_reason":"stop",'
        . '"message":{"role":"assistant","content":"Hello"}}],'
        . '"usage":{"prompt_tokens":50,"completion_tokens":5,"prompt_tokens_details":{"cached_tokens":20}}}';

    public function testRequestBodyFollowsTheOpenAiApi(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenAIClient($http->client(), 'sk-proj-secret');

        $response = $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi')], maxTokens: 256, temperature: 0.2, reasoningEffort: 'low'));

        $sent = $http->requests[0];
        self::assertSame('https://api.openai.com/v1/chat/completions', (string) $sent->getUri());
        self::assertSame('Bearer sk-proj-secret', $sent->getHeader('authorization'));
        self::assertNull($sent->getHeader('x-title'), 'no OpenRouter attribution');
        self::assertNull($sent->getHeader('http-referer'));
        $body = $http->lastPayload();
        self::assertSame(256, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame('low', $body['reasoning_effort']);
        self::assertArrayNotHasKey('reasoning', $body);
        self::assertArrayNotHasKey('temperature', $body, 'reasoning models refuse a temperature');
        self::assertSame('Hello', $response->text);
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(20, $response->usage->cacheReadTokens);
        self::assertNull($response->usage->costUsd, 'the OpenAI API reports no cost');
    }

    public function testMaxEffortBecomesXhighAndToolsAreSent(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenAIClient($http->client(), 'sk-proj-secret');

        $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi')], [new \Mercurius\Llm\ToolDefinition('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]])], reasoningEffort: 'max'));

        $body = $http->lastPayload();
        self::assertSame('none', $body['reasoning_effort'], 'tools in Chat Completions only with reasoning off');
        self::assertSame([['type' => 'function', 'function' => ['name' => 'read', 'description' => 'Read a file', 'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]]]], $body['tools']);
    }

    public function testQuotaIsNotARateLimit(): void
    {
        $http = (new FakeHttp())->respond(429, '{"error":{"message":"You exceeded your current quota, please check your plan and billing details.","type":"insufficient_quota","code":"insufficient_quota"}}');
        $client = new OpenAIClient($http->client(), 'sk-proj-secret');

        try {
            $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi')]));
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::InsufficientCredits, $e->kind);
            self::assertFalse($e->retryable());
        }
    }

    public function testTemperatureIsSentWithoutReasoning(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenAIClient($http->client(), 'sk-proj-secret');

        $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi')], temperature: 0.2));

        self::assertSame(0.2, $http->lastPayload()['temperature']);
    }

    public function testStreamIsReadLikeOpenRouter(): void
    {
        $chunk = static fn(array $data): string => 'data: ' . \json_encode($data, \JSON_THROW_ON_ERROR) . "\n\n";
        $http = (new FakeHttp())->respond(200, [
            $chunk(['id' => 'c', 'model' => 'gpt-6-luna', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hel']]]]),
            $chunk(['id' => 'c', 'model' => 'gpt-6-luna', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]]),
            $chunk(['id' => 'c', 'model' => 'gpt-6-luna', 'choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]),
            "data: [DONE]\n\n",
        ], ['content-type' => 'text/event-stream']);
        $client = new OpenAIClient($http->client(), 'sk-proj-secret');

        $events = \iterator_to_array($client->stream(new ChatRequest('gpt-6-luna', [Message::user('Hi')])), false);

        self::assertEquals([new TextDelta('Hel'), new TextDelta('lo')], \array_slice($events, 0, 2));
        $end = \end($events);
        self::assertInstanceOf(StreamEnd::class, $end);
        self::assertSame('Hello', $end->response->text);
        self::assertSame(['include_usage' => true], $http->lastPayload()['stream_options']);
    }

    public function testErrorsNameTheProvider(): void
    {
        $http = (new FakeHttp())->respond(401, '{"error":{"message":"Incorrect API key provided","type":"invalid_request_error","code":"invalid_api_key"}}');
        $client = new OpenAIClient($http->client(), 'sk-proj-wrong');

        try {
            $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi')]));
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringStartsWith('OpenAI (401)', $e->getMessage());
            self::assertStringNotContainsString('sk-proj-wrong', $e->getMessage());
        }
    }

    public function testEmptyKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenAIClient((new FakeHttp())->client(), '');
    }
}
