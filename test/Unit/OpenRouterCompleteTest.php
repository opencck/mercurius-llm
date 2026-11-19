<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;

final class OpenRouterCompleteTest extends AsyncTestCase
{
    private const OK_BODY = '{"id":"gen-9","model":"anthropic/claude-sonnet-4","choices":[{"index":0,"finish_reason":"tool_calls",'
        . '"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_1","type":"function",'
        . '"function":{"name":"search","arguments":"{\"q\":\"php\"}"}}]}}],'
        . '"usage":{"prompt_tokens":120,"completion_tokens":30,"prompt_tokens_details":{"cached_tokens":80,"cache_write_tokens":10},'
        . '"completion_tokens_details":{"reasoning_tokens":12},"cost":0.0042}}';

    public function testPayloadMapping(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-secret', 'https://example.test/api/v1/', 'Mercurius', 'https://mercurius.test');

        $request = new ChatRequest(
            'anthropic/claude-sonnet-4',
            [
                Message::system('Be brief'),
                Message::user('Find php'),
                Message::assistant('', [new ToolCall('call_0', 'search', '{"q":"amp"}')]),
                Message::toolResult('call_0', 'nothing found'),
                Message::assistant('Trying again'),
            ],
            [
                new ToolDefinition('search', 'Web search', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']]),
                new ToolDefinition('now', 'Current time'),
                new ToolDefinition('noop', 'Nested empty', ['type' => 'object', 'properties' => []]),
            ],
            maxTokens: 256,
            temperature: 0.0,
            reasoningEffort: 'low',
            extra: ['provider' => ['order' => ['anthropic']], 'max_tokens' => 512, 'stream' => true],
        );
        $client->complete($request);

        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://example.test/api/v1/chat/completions', (string) $sent->getUri());
        self::assertSame('Bearer sk-secret', $sent->getHeader('authorization'));
        self::assertSame('Mercurius', $sent->getHeader('x-title'));
        self::assertSame('https://mercurius.test', $sent->getHeader('http-referer'));
        self::assertSame('application/json', $sent->getHeader('content-type'));

        $body = $http->bodies[0];
        // Empty schemas must be JSON objects, not arrays.
        self::assertStringContainsString('"parameters":{"type":"object","properties":{}}', $body);
        self::assertStringContainsString('"properties":{}}', \substr($body, (int) \strpos($body, '"noop"')));
        self::assertStringContainsString('"temperature":0.0', $body);

        $payload = $http->lastPayload();
        self::assertSame('anthropic/claude-sonnet-4', $payload['model']);
        self::assertSame([
            ['role' => 'system', 'content' => 'Be brief'],
            ['role' => 'user', 'content' => 'Find php'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"q":"amp"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_0', 'content' => 'nothing found'],
            ['role' => 'assistant', 'content' => 'Trying again'],
        ], $payload['messages']);
        self::assertSame([
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'description' => 'Web search',
                'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            ],
        ], \is_array($payload['tools']) ? $payload['tools'][0] : null);
        self::assertSame(['effort' => 'low'], $payload['reasoning']);
        self::assertSame(512, $payload['max_tokens'], 'extra overrides built fields');
        self::assertSame(['order' => ['anthropic']], $payload['provider']);
        self::assertArrayNotHasKey('stream', $payload, 'complete() never streams');
    }

    public function testOptionalFieldsAreOmitted(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-secret');
        $client->complete(new ChatRequest('m/x', [Message::user('hi')]));

        $payload = $http->lastPayload();
        self::assertSame(['model', 'messages'], \array_keys($payload));
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', (string) $http->requests[0]->getUri());
        self::assertNull($http->requests[0]->getHeader('x-title'));
    }

    public function testAMessageMarkedForTheCacheGoesAsATextPartWithTheMark(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-secret');
        $client->complete(new ChatRequest('anthropic/claude', [Message::system('rules')->cached(), Message::user('hi')]));

        $messages = $http->lastPayload()['messages'];
        self::assertIsArray($messages);
        self::assertSame(
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'rules', 'cache_control' => ['type' => 'ephemeral']]]],
            $messages[0],
        );
        self::assertSame(['role' => 'user', 'content' => 'hi'], $messages[1], 'an unmarked message stays a string');
    }

    public function testAMessageWithMediaGoesAsTextAndDataUrlParts(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-secret');
        $client->complete(new ChatRequest('google/vision', [Message::user('what is on it?', [MediaPart::image('image/png', 'PNG'), MediaPart::video('video/mp4', 'MP4')])]));

        $messages = $http->lastPayload()['messages'];
        self::assertIsArray($messages);
        self::assertSame(['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'what is on it?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . \base64_encode('PNG')]],
            ['type' => 'video_url', 'video_url' => ['url' => 'data:video/mp4;base64,' . \base64_encode('MP4')]],
        ]], $messages[0]);
    }

    public function testResponseParsing(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-secret');
        $response = $client->complete(new ChatRequest('m/x', [Message::user('hi')]));

        self::assertSame('', $response->text);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame('gen-9', $response->id);
        self::assertSame('anthropic/claude-sonnet-4', $response->model);
        self::assertTrue($response->hasToolCalls());
        self::assertSame('search', $response->toolCalls[0]->name);
        self::assertSame(['q' => 'php'], $response->toolCalls[0]->arguments());
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(80, $response->usage->cacheReadTokens);
        self::assertSame(10, $response->usage->cacheWriteTokens);
        self::assertSame(120, $response->usage->promptTokens());
        self::assertSame(12, $response->usage->reasoningTokens);
        self::assertSame(0.0042, $response->usage->costUsd);

        $message = $response->toMessage();
        self::assertCount(1, $message->toolCalls);
    }

    public function testErrorInsideSuccessfulResponse(): void
    {
        $http = (new FakeHttp())->respond(200, '{"error":{"code":503,"message":"No available providers"}}');
        $client = new OpenRouterClient($http->client(), 'sk-secret');

        try {
            $client->complete(new ChatRequest('m/x', [Message::user('hi')]));
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
        }
    }

    public function testMalformedBody(): void
    {
        $http = (new FakeHttp())->respond(200, '<html>oops</html>');
        $client = new OpenRouterClient($http->client(), 'sk-secret');

        try {
            $client->complete(new ChatRequest('m/x', [Message::user('hi')]));
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Unknown, $e->kind);
            self::assertStringNotContainsString('oops', $e->getMessage());
        }
    }
}
