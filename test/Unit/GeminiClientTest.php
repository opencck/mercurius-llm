<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\Gemini\GeminiClient;
use Mercurius\Llm\Gemini\GeminiModel;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\ModelCatalog\GeminiCatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\ModelCatalog\VertexCatalog;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;

final class GeminiClientTest extends AsyncTestCase
{
    private const OK_BODY = '{"id":"c1","model":"gemini-3.8-flash","choices":[{"index":0,"finish_reason":"STOP",'
        . '"message":{"role":"assistant","content":"Hello"}}],'
        . '"usage":{"prompt_tokens":50,"completion_tokens":5,"prompt_tokens_details":{"cached_tokens":20}}}';

    private const REFERENCE = '{"data":['
        . '{"id":"google/gemini-3.8-flash","name":"Google: Gemini 3.8 Flash","context_length":1000000,'
        . '"architecture":{"input_modalities":["text","image","video"],"output_modalities":["text"]},'
        . '"pricing":{"prompt":"0.00000075","completion":"0.00000375"},"supported_parameters":["tools"]},'
        . '{"id":"google/gemini-3.8-flash:free","name":"free","context_length":1000000,'
        . '"architecture":{"output_modalities":["text"]},"pricing":{"prompt":"0","completion":"0"}},'
        . '{"id":"google/gemini-nano-banana-2.1","name":"image","context_length":32000,'
        . '"architecture":{"output_modalities":["image","text"]},"pricing":{"prompt":"0.000001","completion":"0.00001"}},'
        . '{"id":"google/gemini-3.8-flash-tts","name":"speech","context_length":32000,'
        . '"architecture":{"output_modalities":["audio"]},"pricing":{"prompt":"0.000001","completion":"0.00001"}},'
        . '{"id":"google/gemma-4","name":"Gemma","context_length":128000,'
        . '"architecture":{"output_modalities":["text"]},"pricing":{"prompt":"0.0000001","completion":"0.0000002"}},'
        . '{"id":"openai/gpt-6-luna","name":"GPT","context_length":1000,"pricing":{"prompt":"0.0000001","completion":"0.0000005"}}'
        . ']}';

    /** A value deep inside a decoded request body. */
    private static function dig(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            /** @var mixed $data */
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayAt(mixed $data, string|int ...$path): array
    {
        $value = self::dig($data, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    public function testRequestGoesToTheCompatibleAddressWithThinkingOfTheFamily(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new GeminiClient($http->client(), 'AIza-secret');

        $response = $client->complete(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')], maxTokens: 256, temperature: 0.2, reasoningEffort: 'medium'));

        $sent = $http->requests[0];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', (string) $sent->getUri());
        self::assertSame('Bearer AIza-secret', $sent->getHeader('authorization'));
        self::assertNull($sent->getHeader('x-title'), 'no OpenRouter attribution');
        $body = $http->lastPayload();
        self::assertSame(['google' => ['thinking_config' => ['include_thoughts' => false, 'thinking_level' => 'medium']]], $body['extra_body']);
        self::assertArrayNotHasKey('reasoning', $body);
        self::assertArrayNotHasKey('reasoning_effort', $body);
        self::assertSame(GeminiModel::MAX_OUTPUT_TOKENS, $body['max_tokens'], 'thinking is billed against the limit');
        self::assertArrayNotHasKey('temperature', $body, 'Gemini 3.6 and later ignore it');
        self::assertSame('Hello', $response->text);
        self::assertSame(FinishReason::Stop, $response->finishReason, 'a reason in capitals');
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(20, $response->usage->cacheReadTokens);
        self::assertNull($response->usage->costUsd);
    }

    public function testThinkingByFamily(): void
    {
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'low'], GeminiModel::thinking('gemini-3-pro-preview', 'medium'), '3 Pro has low and high');
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'high'], GeminiModel::thinking('google/gemini-3-pro-preview', 'max'));
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'high'], GeminiModel::thinking('gemini-4-argon', 'xhigh'), 'xhigh only where it is taken');
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'xhigh'], GeminiModel::thinking('gemini-4-hydrogen', 'max'));
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'low'], GeminiModel::thinking('gemini-4-argon', 'none'), 'Gemini 4 always thinks');
        self::assertSame(['include_thoughts' => false, 'thinking_budget' => 0], GeminiModel::thinking('gemini-2.5-flash', 'none'));
        self::assertNull(GeminiModel::thinking('gemini-2.5-flash', 'high'), 'no budget is guessed');
        self::assertNull(GeminiModel::thinking('gemma-4', 'high'), 'Gemma refuses the field');
        self::assertNull(GeminiModel::thinking('gemini-3.8-flash', null));
        self::assertSame(['include_thoughts' => false, 'thinking_level' => 'low'], GeminiModel::thinking('gemini-flash-latest', 'minimal'));
    }

    public function testASmallLimitStaysWhenThinkingIsOffAndTemperatureGoesToOlderModels(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY)->respond(200, self::OK_BODY);
        $client = new GeminiClient($http->client(), 'AIza-secret');

        $client->complete(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')], maxTokens: 64, reasoningEffort: 'none'));
        self::assertSame(64, self::dig($http->lastPayload(), 'max_tokens'));

        $client->complete(new ChatRequest('gemini-2.5-flash', [Message::user('Hi')], maxTokens: 64, temperature: 0.2));
        self::assertSame(64, $http->lastPayload()['max_tokens']);
        self::assertSame(0.2, $http->lastPayload()['temperature']);
        self::assertArrayNotHasKey('extra_body', $http->lastPayload());
    }

    public function testASignatureOfAToolCallComesBackAndGoesOutAgain(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"id":"c1","model":"gemini-3.8-flash","choices":[{"index":0,"finish_reason":"tool_calls","message":{"role":"assistant","content":null,'
                . '"tool_calls":[{"id":"call_1","type":"function","function":{"name":"read","arguments":"{\"path\":\"a\"}"},"extra_content":{"google":{"thought_signature":"SIG-1"}}},'
                . '{"id":"call_2","type":"function","function":{"name":"read","arguments":"{\"path\":\"b\"}"}}]}}],"usage":{"prompt_tokens":5,"completion_tokens":5}}')
            ->respond(200, self::OK_BODY);
        $client = new GeminiClient($http->client(), 'AIza-secret');
        $tools = [new ToolDefinition('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => ['string', 'null']]]])];

        $first = $client->complete(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')], $tools));

        self::assertSame('SIG-1', $first->toolCalls[0]->signature);
        self::assertNull($first->toolCalls[1]->signature);
        self::assertSame(['type' => 'string', 'nullable' => true], self::dig($http->lastPayload(), 'tools', 0, 'function', 'parameters', 'properties', 'path'), 'a list of types is not accepted');

        $client->complete(new ChatRequest('gemini-3.8-flash', [
            Message::user('Hi'),
            Message::assistant('', $first->toolCalls),
            Message::toolResult('call_1', 'A'),
            Message::toolResult('call_2', 'B'),
        ], $tools));

        $calls = self::arrayAt($http->lastPayload(), 'messages', 1, 'tool_calls');
        self::assertSame(['google' => ['thought_signature' => 'SIG-1']], self::dig($calls, 0, 'extra_content'));
        self::assertArrayNotHasKey('extra_content', self::arrayAt($calls, 1), 'only the first call of a step is signed');
    }

    public function testACallOfAnotherProviderGetsTheStandInOnlyWhereSignaturesAreChecked(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY)->respond(200, self::OK_BODY);
        $client = new GeminiClient($http->client(), 'AIza-secret');
        $history = [Message::user('Hi'), Message::assistant('', [new ToolCall('toolu_1', 'read', '{}')]), Message::toolResult('toolu_1', 'A')];

        $client->complete(new ChatRequest('gemini-3.8-flash', $history));
        self::assertSame(['google' => ['thought_signature' => GeminiModel::SKIP_SIGNATURE]], self::dig($http->lastPayload(), 'messages', 1, 'tool_calls', 0, 'extra_content'));

        $client->complete(new ChatRequest('gemini-2.5-flash', $history));
        self::assertArrayNotHasKey('extra_content', self::arrayAt($http->lastPayload(), 'messages', 1, 'tool_calls', 0));
    }

    public function testASignatureArrivesInAStream(): void
    {
        $chunk = static fn (array $data): string => 'data: ' . \json_encode($data, \JSON_THROW_ON_ERROR) . "\n\n";
        $http = (new FakeHttp())->respond(200, [
            $chunk(['id' => 'c1', 'model' => 'gemini-3.8-flash', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"path":'],
                'extra_content' => ['google' => ['thought_signature' => 'SIG-9']],
            ]]]]]]),
            $chunk(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"a"}']]]], 'finish_reason' => 'tool_calls']]]),
            "data: [DONE]\n\n",
        ], ['content-type' => 'text/event-stream']);
        $client = new GeminiClient($http->client(), 'AIza-secret');

        $end = null;
        foreach ($client->stream(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')])) as $event) {
            if ($event instanceof StreamEnd) {
                $end = $event;
            }
        }

        self::assertNotNull($end);
        self::assertSame('SIG-9', $end->response->toolCalls[0]->signature);
        self::assertSame('{"path":"a"}', $end->response->toolCalls[0]->argumentsJson);
    }

    public function testOtherDialectsNeverSendASignature(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new \Mercurius\Llm\OpenAI\OpenAIClient($http->client(), 'sk-proj-secret');

        $client->complete(new ChatRequest('gpt-6-luna', [Message::user('Hi'), Message::assistant('', [new ToolCall('c', 'read', '{}', 'SIG')]), Message::toolResult('c', 'A')]));

        self::assertArrayNotHasKey('extra_content', self::arrayAt($http->lastPayload(), 'messages', 1, 'tool_calls', 0));
    }

    public function testAUsedUpWindowIsALimitAndNamesTheWait(): void
    {
        $http = (new FakeHttp())->respond(429, '[{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details. '
            . 'Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 10. Please retry in 34.5s.","status":"RESOURCE_EXHAUSTED"}}]');
        $client = new GeminiClient($http->client(), 'AIza-secret');

        try {
            $client->complete(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')]));
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertSame(34.5, $e->retryAfter);
            self::assertStringStartsWith('Gemini (429): You exceeded your current quota', $e->getMessage());
            self::assertStringNotContainsString('AIza-secret', $e->getMessage());
        }
    }

    public function testTheNameOfTheProviderKeepsTheIdOfTheRequest(): void
    {
        $http = (new FakeHttp())->respond(403, '{"error":{"code":403,"message":"Permission denied","status":"PERMISSION_DENIED"}}', ['x-request-id' => 'req_42']);
        $client = new GeminiClient($http->client(), 'AIza-secret');

        try {
            $client->complete(new ChatRequest('gemini-3.8-flash', [Message::user('Hi')]));
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertSame('req_42', $e->requestId);
        }
    }

    public function testABlockIsAContentFilter(): void
    {
        self::assertSame(FinishReason::ContentFilter, FinishReason::fromOpenAi('SAFETY'));
        self::assertSame(FinishReason::ContentFilter, FinishReason::fromOpenAi('RECITATION'));
        self::assertSame(FinishReason::Length, FinishReason::fromOpenAi('MAX_TOKENS'));
    }

    public function testModelsOfTheKeyGetPricesFromTheReference(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"object":"list","data":[{"id":"models/gemini-3.8-flash"},{"id":"models/gemini-nano-banana-2.1"},'
                . '{"id":"models/gemini-3.8-flash-tts"},{"id":"models/gemini-embedding-2"},{"id":"models/gemma-4"}]}')
            ->respond(200, self::REFERENCE);
        $client = $http->client();

        $models = (new GeminiCatalog($client, 'AIza-secret', new OpenRouterCatalog($client)))->fetch();

        self::assertSame('https://generativelanguage.googleapis.com/v1beta/openai/models', (string) $http->requests[0]->getUri());
        self::assertSame(['gemini-3.8-flash', 'gemma-4'], \array_map(static fn ($m) => $m->id, $models));
        self::assertSame(0.00000075, $models[0]->inputPricePerToken);
        self::assertSame(['text', 'image', 'video'], $models[0]->inputModalities);
        self::assertTrue($models[0]->supportsTools());
    }

    public function testTheVertexCatalogIsTheGeminiTextModelsOfTheReference(): void
    {
        $http = (new FakeHttp())->respond(200, self::REFERENCE);

        $models = (new VertexCatalog(new OpenRouterCatalog($http->client())))->fetch();

        self::assertSame(['google/gemini-3.8-flash'], \array_map(static fn ($m) => $m->id, $models));
    }
}
