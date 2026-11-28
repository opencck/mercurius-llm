<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\ModelCatalog\KimiCodeCatalog;
use Mercurius\Llm\ModelCatalog\MoonshotCatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\Moonshot\KimiCodeUsage;
use Mercurius\Llm\Moonshot\MoonshotClient;
use Mercurius\Llm\Moonshot\MoonshotModel;
use Mercurius\Llm\Moonshot\ToolSchema;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;

final class MoonshotClientTest extends AsyncTestCase
{
    private const OK_BODY = '{"id":"c1","model":"kimi-k3.1-pro","choices":[{"index":0,"finish_reason":"stop",'
        . '"message":{"role":"assistant","content":"Hello","reasoning_content":"thinking"}}],'
        . '"usage":{"prompt_tokens":50,"completion_tokens":5,"prompt_tokens_details":{"cached_tokens":20}}}';

    /** @return array<array-key, mixed> */
    private static function arrayAt(mixed $data, string|int ...$path): array
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            /** @var mixed $data */
            $data = $data[$key];
        }
        self::assertIsArray($data);

        return $data;
    }

    private static function sse(mixed $data): string
    {
        return 'data: ' . \json_encode($data, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    public function testThePlatformGetsAnEffortWithoutTemperatureAndNamesTheClient(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY, ['x-trace-id' => 'tr-1']);
        $client = MoonshotClient::platform($http->client(), 'sk-platform', '1.2.3');

        $response = $client->complete(new ChatRequest('kimi-k3.1-pro', [Message::user('Hi')], maxTokens: 256, temperature: 0.3, reasoningEffort: 'medium'));

        $sent = $http->requests[0];
        self::assertSame('https://api.moonshot.ai/v1/chat/completions', (string) $sent->getUri());
        self::assertSame('Bearer sk-platform', $sent->getHeader('authorization'));
        self::assertSame('Mercurius/1.2.3', $sent->getHeader('user-agent'));
        self::assertSame('https://mercurius.opencck.org', $sent->getHeader('http-referer'));
        self::assertSame('Mercurius', $sent->getHeader('x-title'));
        self::assertNull($sent->getHeader('x-msh-platform'), 'no device headers of the official client');
        $body = $http->lastPayload();
        self::assertSame('high', $body['reasoning_effort'], 'K3 has low, high and max: medium goes up');
        self::assertArrayNotHasKey('thinking', $body, 'K3 refuses the thinking parameter');
        self::assertArrayNotHasKey('reasoning', $body);
        self::assertArrayNotHasKey('temperature', $body, 'K3 fixes its temperature');
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame(MoonshotModel::MAX_OUTPUT_TOKENS, $body['max_completion_tokens'], 'reasoning is billed against the limit');
        self::assertSame('Hello', $response->text);
        self::assertSame('thinking', $response->reasoning);
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(20, $response->usage->cacheReadTokens);
    }

    public function testTheSubscriptionGoesToItsOwnAddressAndAVersionCannotCarryText(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = MoonshotClient::kimiCode($http->client(), 'sk-kimi-secret', "1.0\r\nX-Evil: 1");

        $client->complete(new ChatRequest('k3', [Message::user('Hi')]));

        $sent = $http->requests[0];
        self::assertSame('https://api.kimi.ai/coding/v1/chat/completions', (string) $sent->getUri());
        self::assertSame('Mercurius/dev', $sent->getHeader('user-agent'));
        self::assertArrayNotHasKey('reasoning_effort', $http->lastPayload(), 'no effort asked: the model default');
    }

    public function testOnlyTheKnownAddressesAreTaken(): void
    {
        $http = new FakeHttp();
        MoonshotClient::platform($http->client(), 'k', 'dev', 'https://api.moonshot.cn/v1/');
        MoonshotClient::kimiCode($http->client(), 'k', 'dev', 'https://api.kimi.com/coding/v1');
        $this->expectException(\InvalidArgumentException::class);
        MoonshotClient::kimiCode($http->client(), 'k', 'dev', 'https://evil.example/coding/v1');
    }

    public function testEffortByModel(): void
    {
        self::assertSame(['reasoning_effort' => 'max'], MoonshotModel::thinking('k3.1-pro', 'xhigh'));
        self::assertSame(['reasoning_effort' => 'low'], MoonshotModel::thinking('k3-256k', 'minimal'));
        self::assertSame(['reasoning_effort' => 'max'], MoonshotModel::thinking('kimi-for-coding', 'max'));
        self::assertSame([], MoonshotModel::thinking('k3', 'none'), 'thinking cannot be turned off: the model default');
        self::assertSame([], MoonshotModel::thinking('k3', null));
        self::assertSame([], MoonshotModel::thinking('kimi-for-coding-highspeed', 'high'), 'no adjustable effort');
        self::assertSame(['thinking' => ['type' => 'enabled', 'keep' => 'all']], MoonshotModel::thinking('kimi-k2.6', 'low'), 'K2 keeps its reasoning only when asked');
        self::assertSame(64_000, MoonshotModel::maxTokens(64_000));
        self::assertSame(MoonshotModel::MAX_OUTPUT_TOKENS, MoonshotModel::maxTokens(4096));
    }

    public function testTheReasoningOfAToolTurnGoesBackAndAnEmptyTextIsLeftOut(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = MoonshotClient::platform($http->client(), 'sk', 'dev');
        $calls = [new ToolCall('call_1', 'read', '{"path":"a"}')];

        $client->complete(new ChatRequest('kimi-k3', [
            Message::user('Read a'),
            Message::assistant('', $calls, 'I should read it'),
            Message::toolResult('call_1', 'text'),
            Message::assistant('', [new ToolCall('call_2', 'read', '{}')]),
            Message::toolResult('call_2', 'text'),
            Message::assistant('Done', [], 'all read'),
            Message::assistant('Plain'),
        ]));

        $messages = self::arrayAt($http->lastPayload(), 'messages');
        $first = self::arrayAt($messages, 1);
        self::assertSame('I should read it', $first['reasoning_content']);
        self::assertArrayNotHasKey('content', $first, 'Kimi takes no empty text next to tool calls');
        self::assertSame(' ', self::arrayAt($messages, 3)['reasoning_content'], 'a call another provider made gets a stand-in');
        self::assertSame('all read', self::arrayAt($messages, 5)['reasoning_content']);
        self::assertArrayNotHasKey('reasoning_content', self::arrayAt($messages, 6));
    }

    public function testOtherProvidersGetNoReasoningBack(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = new OpenRouterClient($http->client(), 'sk-or');

        $client->complete(new ChatRequest('moonshotai/kimi-k3', [Message::user('Hi'), Message::assistant('', [new ToolCall('c', 'read', '{}')], 'secret thought'), Message::toolResult('c', 'x')]));

        self::assertArrayNotHasKey('reasoning_content', self::arrayAt($http->lastPayload(), 'messages', 1));
    }

    public function testToolSchemasFollowTheMoonshotFlavour(): void
    {
        $clean = ToolSchema::clean([
            'type' => 'object',
            'properties' => [
                'mode' => ['enum' => ['fast', null, '']],
                'note' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'nullable' => true],
                'size' => ['$ref' => '#/$defs/Size'],
                'tags' => ['items' => ['type' => 'string']],
                'deep' => ['properties' => ['x' => ['type' => 'integer']], 'required' => ['x', 'gone']],
            ],
            'required' => ['mode', 'missing'],
            '$defs' => ['Size' => ['type' => 'integer', 'minimum' => 1]],
        ]);

        self::assertSame(['type' => 'string', 'enum' => ['fast']], self::arrayAt($clean, 'properties', 'mode'));
        self::assertSame(['type' => 'string'], self::arrayAt($clean, 'properties', 'note'));
        self::assertSame(['type' => 'integer', 'minimum' => 1], self::arrayAt($clean, 'properties', 'size'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], self::arrayAt($clean, 'properties', 'tags'));
        self::assertSame(['x'], self::arrayAt($clean, 'properties', 'deep', 'required'));
        self::assertSame('object', self::arrayAt($clean, 'properties', 'deep')['type']);
        self::assertSame(['mode'], $clean['required']);
        self::assertArrayNotHasKey('$defs', $clean, 'every reference was resolved');
    }

    public function testToolsAreCleanedOnTheWire(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK_BODY);
        $client = MoonshotClient::platform($http->client(), 'sk', 'dev');

        $client->complete(new ChatRequest('kimi-k3', [Message::user('Hi')], [new ToolDefinition('pick', 'Pick', ['type' => 'object', 'properties' => ['a' => ['enum' => ['x', null]]]])]));

        $parameters = self::arrayAt($http->lastPayload(), 'tools', 0, 'function', 'parameters');
        self::assertSame(['type' => 'string', 'enum' => ['x']], self::arrayAt($parameters, 'properties', 'a'));
        self::assertSame([], $parameters['required']);
    }

    public function testAStreamCarriesReasoningContentAndKeepsTheFirstIdOfACall(): void
    {
        $http = (new FakeHttp())->respond(200, [
            self::sse(['id' => 's1', 'model' => 'k3', 'choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'Let me ']]]]),
            self::sse(['id' => 's1', 'choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'think']]]]),
            self::sse(['id' => 's1', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'read', 'arguments' => '{"p":']]]]]]]),
            self::sse(['id' => 's1', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_b', 'function' => ['arguments' => '"x"}']]]]]]]),
            self::sse(['id' => 's1', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]),
            self::sse(['id' => 's1', 'choices' => [], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'cached_tokens' => 40]]),
            "data: [DONE]\n\n",
        ]);
        $client = MoonshotClient::kimiCode($http->client(), 'sk-kimi', 'dev');

        $events = \iterator_to_array($client->stream(new ChatRequest('k3', [Message::user('Hi')])), false);

        self::assertInstanceOf(ReasoningDelta::class, $events[0]);
        $end = \end($events);
        self::assertInstanceOf(StreamEnd::class, $end);
        self::assertSame('Let me think', $end->response->reasoning);
        self::assertSame('call_a', $end->response->toolCalls[0]->id, 'Kimi sends a fresh id with every fragment');
        self::assertSame('{"p":"x"}', $end->response->toolCalls[0]->argumentsJson);
        self::assertSame(40, $end->response->usage->cacheReadTokens, 'the top-level cached_tokens of Moonshot');
        self::assertSame(60, $end->response->usage->inputTokens);
    }

    public function testAUsedUpWindowOfTheSubscriptionReopensWhenTheQuotaSays(): void
    {
        $http = (new FakeHttp())
            ->respond(429, '{"error":{"message":"You\'ve reached your 5-hour usage limit. Your quota will reset when the current 5-hour window ends.","type":"rate_limit_reached_error"}}', ['x-trace-id' => 'tr-9'])
            ->respond(200, '{"usages":{"limit_5h":{"used_ratio":1.0,"reset_time":"2026-11-28T15:00:00Z"},"limit_month_total":{"used_ratio":0.4,"reset_time":"2026-12-01T00:00:00Z"}}}');
        $client = MoonshotClient::kimiCode($http->client(), 'sk-kimi', 'dev');

        try {
            $client->complete(new ChatRequest('k3', [Message::user('Hi')]));
            self::fail('no exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertSame(\strtotime('2026-11-28T15:00:00Z'), $e->resetsAt);
            self::assertSame('tr-9', $e->requestId);
            self::assertStringStartsWith('Kimi Code', $e->getMessage());
        }
        self::assertSame('https://api.kimi.ai/coding/v1/usages', (string) $http->requests[1]->getUri());
        self::assertSame('Mercurius/dev', $http->requests[1]->getHeader('user-agent'));
    }

    public function testAWindowWithoutAnAnswerOfTheQuotaReopensInAnHour(): void
    {
        $http = (new FakeHttp())
            ->respond(429, '{"error":{"message":"You\'ve reached your monthly usage limit for this billing cycle."}}')
            ->respond(401, '{"error":{"message":"Invalid Authentication"}}');
        $client = MoonshotClient::kimiCode($http->client(), 'sk-kimi', 'dev');

        try {
            $client->complete(new ChatRequest('k3', [Message::user('Hi')]));
            self::fail('no exception');
        } catch (LlmException $e) {
            self::assertNotNull($e->resetsAt);
            self::assertEqualsWithDelta(\time() + 3600, $e->resetsAt, 5);
        }
    }

    public function testErrorsOfKimiAreClassified(): void
    {
        $cases = [
            [429, '{"error":{"type":"exceeded_current_quota_error","message":"Token quota is insufficient"}}', ErrorKind::InsufficientCredits],
            [429, '{"error":{"type":"exceeded_current_quota_error","message":"Account balance is insufficient or the account has been disabled"}}', ErrorKind::InsufficientCredits],
            [429, '{"error":{"type":"engine_overloaded_error","message":"The engine is currently overloaded, please try again later"}}', ErrorKind::RateLimited],
            [403, '{"error":{"message":"Your current subscription does not have access to k3. Upgrade to higher-tier Kimi Code plans."}}', ErrorKind::BadRequest],
            [403, '{"error":{"message":"Your current plan supports only k3 up to 256K context."}}', ErrorKind::BadRequest],
            [400, '{"error":{"message":"total message size 3000000 exceeds limit 2097152"}}', ErrorKind::ContextLength],
            [400, '{"error":{"message":"Invalid request: Your request exceeded model token limit: 262144 (requested: 558009)"}}', ErrorKind::ContextLength],
            [400, '{"error":{"type":"invalid_request_error","message":"Input token length too long"}}', ErrorKind::ContextLength],
            [400, '{"error":{"type":"content_filter","message":"The request was rejected because it was considered high risk"}}', ErrorKind::ContentFilter],
        ];
        foreach ($cases as [$status, $body, $kind]) {
            $http = (new FakeHttp())->respond($status, $body);
            try {
                MoonshotClient::platform($http->client(), 'sk', 'dev')->complete(new ChatRequest('kimi-k3', [Message::user('Hi')]));
                self::fail('no exception');
            } catch (LlmException $e) {
                self::assertSame($kind, $e->kind, $body);
                self::assertNull($e->resetsAt, 'the platform has no windows');
                self::assertStringStartsWith('Moonshot', $e->getMessage());
            }
        }
    }

    public function testTheQuotaOfTheSubscription(): void
    {
        $http = (new FakeHttp())->respond(200, '{"usages":{"limit_5h":{"used_ratio":"0.25","reset_time":"2026-11-28T15:00:00Z"},'
            . '"limit_7d":{"used_ratio":1,"reset_time":"2026-12-02T00:00:00Z"},"limit_month_total":{"used_ratio":0.5}},'
            . '"boosterWallet":{"balance":{"type":"BOOSTER","amount":"10000000000","amountLeft":"2500000000"}}}');
        $usage = new KimiCodeUsage($http->client(), 'sk-kimi', 'dev');

        $quota = $usage->fetch();

        self::assertSame(0.25, $quota['windows']['5h']['used']);
        self::assertSame(\strtotime('2026-11-28T15:00:00Z'), $quota['windows']['5h']['resetsAt']);
        self::assertSame(1.0, $quota['windows']['7d']['used']);
        self::assertNull($quota['windows']['month']['resetsAt']);
        self::assertSame(['left' => 2500, 'total' => 10000], $quota['extraUsageCents']);
        self::assertSame(\strtotime('2026-12-02T00:00:00Z'), KimiCodeUsage::reopens($quota));
        self::assertSame('Bearer sk-kimi', $http->requests[0]->getHeader('authorization'));
    }

    public function testTheSubscriptionListsItsModelsWithTheirTraits(): void
    {
        $http = (new FakeHttp())->respond(200, '{"data":['
            . '{"id":"k3.1-pro","display_name":"K3.1 Pro","limit":{"context":262144},"modalities":{"input":["text","image","video"]},'
            . '"reasoning":{"type":"only","effort":{"valid":["low","high","max"],"default":"high"}}},'
            . '{"id":"kimi-for-coding","limit":{"context":1048576},"modalities":{"input":["text","image"]},"reasoning":{"type":"both"},"supports_tool_use":true},'
            . '{"id":"no-tools","limit":{"context":8192},"supports_tool_use":false},'
            . '{"id":"broken"}]}');

        $models = (new KimiCodeCatalog($http->client(), 'sk-kimi', 'dev'))->fetch();

        self::assertSame('https://api.kimi.ai/coding/v1/models', (string) $http->requests[0]->getUri());
        self::assertSame(['k3.1-pro', 'kimi-for-coding', 'no-tools'], \array_map(static fn($m): string => $m->id, $models));
        self::assertSame('Kimi Code: K3.1 Pro', $models[0]->name);
        self::assertSame(262144, $models[0]->contextLength);
        self::assertSame(0.0, $models[0]->inputPricePerToken);
        self::assertSame(['text', 'image', 'video'], $models[0]->inputModalities);
        self::assertTrue($models[0]->supportsTools());
        self::assertTrue($models[0]->supports('reasoning'));
        self::assertTrue($models[0]->reasoningMandatory);
        self::assertFalse($models[1]->reasoningMandatory);
        self::assertFalse($models[2]->supportsTools());
        self::assertSame(['text'], $models[2]->inputModalities);
    }

    public function testThePlatformListsItsModelsWithPricesOfTheReference(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"data":[{"id":"kimi-k3.1-pro","context_length":1048576,"supports_image_in":true,"supports_video_in":true,'
                . '"supports_reasoning":true,"supports_thinking_type":"only"},{"id":"moonshot-v1-8k","context_length":8192}]}')
            ->respond(200, '{"data":[{"id":"moonshotai/kimi-k3.1-pro","name":"MoonshotAI: Kimi K3.1 Pro","context_length":1048576,'
                . '"pricing":{"prompt":"0.0000006","completion":"0.000003","input_cache_read":"0.0000001"},"supported_parameters":["tools","reasoning"]}]}');

        $models = (new MoonshotCatalog($http->client(), 'sk', 'dev', new OpenRouterCatalog($http->client())))->fetch();

        self::assertSame(['kimi-k3.1-pro', 'moonshot-v1-8k'], \array_map(static fn($m): string => $m->id, $models));
        self::assertSame('MoonshotAI: Kimi K3.1 Pro', $models[0]->name);
        self::assertSame(0.0000006, $models[0]->inputPricePerToken);
        self::assertSame(0.0000001, $models[0]->cacheReadPricePerToken);
        self::assertSame(['text', 'image', 'video'], $models[0]->inputModalities);
        self::assertTrue($models[0]->reasoningMandatory);
        self::assertTrue($models[0]->supportsTools());
        self::assertNull($models[1]->inputPricePerToken, 'a model the reference does not know has no price');
        self::assertSame(['text'], $models[1]->inputModalities);
    }
}
