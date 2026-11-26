<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Antigravity\AntigravityClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolDefinition;

final class AntigravityClientTest extends AsyncTestCase
{
    private static function request(): ChatRequest
    {
        return new ChatRequest('gemini-3.8-flash', [Message::user('Hi')], [new ToolDefinition('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]])], reasoningEffort: 'high');
    }

    public function testTheEffortReachesTheServiceWithToolsAndTheAnswerIsRead(): void
    {
        $http = (new FakeHttp())->respond(200, '{"id":"chatcmpl-1","model":"gemini-3.8-flash","choices":[{"index":0,"finish_reason":"tool_calls","message":{"role":"assistant","content":null,'
            . '"tool_calls":[{"id":"call_1","type":"function","function":{"name":"read","arguments":"{\"path\":\"a\"}"}}]}}],'
            . '"usage":{"prompt_tokens":1200,"completion_tokens":5,"prompt_tokens_details":{"cached_tokens":200},"completion_tokens_details":{"reasoning_tokens":3}}}');
        $client = new AntigravityClient($http->client(), 'module-token', 'http://antigravity:8095/v1');

        $response = $client->complete(self::request());

        self::assertSame('http://antigravity:8095/v1/chat/completions', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer module-token', $http->requests[0]->getHeader('authorization'));
        self::assertSame(['effort' => 'high'], $http->lastPayload()['reasoning']);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame('read', $response->toolCalls[0]->name);
        self::assertSame([1000, 200, 3], [$response->usage->inputTokens, $response->usage->cacheReadTokens, $response->usage->reasoningTokens]);
        self::assertNull($response->usage->costUsd);
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalOfTheServiceKeepsItsKindAndTheTimeTheWindowReopens(int $status, string $body, ErrorKind $kind, bool $retryable, ?int $resetsAt): void
    {
        $http = (new FakeHttp())->respond($status, $body);
        $client = new AntigravityClient($http->client(), 'module-token', 'http://antigravity:8095/v1');

        try {
            $client->complete(self::request());
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame([$kind, $retryable, $resetsAt], [$e->kind, $e->retryable(), $e->resetsAt]);
            self::assertStringStartsWith('Antigravity (' . $status . '): ', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, ErrorKind, bool, ?int}> */
    public static function refusals(): iterable
    {
        yield 'a used-up window' => [429, '{"error":{"message":"The window of the Antigravity plan for this model is used up.","type":"rate_limit_error","code":429,"resets_at":4070908800}}', ErrorKind::RateLimited, true, 4070908800];
        yield 'a violation is not repeated' => [422, '{"error":{"message":"The Antigravity CLI left the rules of a model call; the call was stopped.","type":"tool_violation","code":422}}', ErrorKind::BadRequest, false, null];
        yield 'no sign-in' => [401, '{"error":{"message":"Nobody is signed in to the Antigravity plan.","type":"authentication_error","code":401}}', ErrorKind::Auth, false, null];
        yield 'the owner must act' => [403, '{"error":{"message":"Google asks the owner of the account to act.","type":"account_action_required","code":403}}', ErrorKind::Auth, false, null];
    }
}
