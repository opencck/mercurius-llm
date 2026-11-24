<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Anthropic\AnthropicClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\RetryingClient;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolDefinition;

/**
 * What the Messages API asks of a caller beyond the shape of a conversation: thinking and effort by model, streamed
 * tool arguments, a refusal, the tokens of thinking, a limit with the time its window reopens.
 */
final class AnthropicRefinementsTest extends AsyncTestCase
{
    private const OK = '{"id":"msg_1","type":"message","role":"assistant","model":"m","content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}';

    /** @param list<string> $events */
    private static function sse(array $events): string
    {
        return \implode('', \array_map(static fn(string $e): string => 'event: x' . "\n" . 'data: ' . $e . "\n\n", $events));
    }

    private static function end(AnthropicClient $client, ChatRequest $request): StreamEnd
    {
        $last = null;
        foreach ($client->stream($request) as $event) {
            $last = $event;
        }
        self::assertInstanceOf(StreamEnd::class, $last);

        return $last;
    }

    public function testThinkingAndEffortFollowWhatTheModelTakes(): void
    {
        $cases = [
            // the newer models hide the text of thinking unless the summary is asked for
            ['claude-opus-5-5', 'high', ['type' => 'adaptive', 'display' => 'summarized'], ['effort' => 'high']],
            ['claude-sonnet-5-5[1m]', null, ['type' => 'adaptive', 'display' => 'summarized'], null],
            ['anthropic.claude-fable-5-2', 'none', null, ['effort' => 'low']],
            ['sonnet', 'minimal', ['type' => 'adaptive', 'display' => 'summarized'], ['effort' => 'low']],
            // a model that takes the disable gets it instead of the lowest effort
            ['claude-opus-5', 'none', ['type' => 'disabled'], null],
            // 4.6–4.8 think only when asked, and 4.6 has no xhigh
            ['claude-opus-4-8', null, null, null],
            ['claude-opus-4-6', 'xhigh', ['type' => 'adaptive', 'display' => 'summarized'], ['effort' => 'high']],
            ['claude-opus-4-8', 'xhigh', ['type' => 'adaptive', 'display' => 'summarized'], ['effort' => 'xhigh']],
            // no adaptive thinking and no effort parameter
            ['claude-haiku-4-5-20251001', 'high', null, null],
            ['claude-haiku-4-5', 'none', ['type' => 'disabled'], null],
            ['claude-sonnet-4-5', 'medium', null, null],
            // Opus 4.5 takes an effort, without adaptive thinking
            ['claude-opus-4-5', 'high', null, ['effort' => 'high']],
            // an unknown model is taken for a new one
            ['claude-next-9', 'max', ['type' => 'adaptive', 'display' => 'summarized'], ['effort' => 'max']],
        ];
        foreach ($cases as [$model, $effort, $thinking, $config]) {
            $http = (new FakeHttp())->respond(200, self::OK);
            (new AnthropicClient($http->client(), 'sk-ant'))->complete(new ChatRequest($model, [Message::user('hi')], [], 100, null, $effort));
            $payload = $http->lastPayload();
            self::assertSame($thinking, $payload['thinking'] ?? null, $model . ' / ' . var_export($effort, true));
            self::assertSame($config, $payload['output_config'] ?? null, $model . ' / ' . var_export($effort, true));
        }
    }

    public function testStreamedToolArgumentsAreAskedForAndCheckedBeforeACallIsRun(): void
    {
        $tools = [new ToolDefinition('write', 'Write', ['type' => 'object', 'properties' => []]), new ToolDefinition('read', 'Read', [])];
        $request = new ChatRequest('claude-opus-5-5', [Message::user('hi')], $tools, 100);
        $stream = self::sse([
            '{"type":"message_start","message":{"id":"msg_1","model":"claude-opus-5-5","usage":{"input_tokens":3,"output_tokens":1}}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"read","input":{}}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"path\":\"a.txt\"}"}}',
            '{"type":"content_block_stop","index":0}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_2","name":"write","input":{}}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"path\":\"b.txt\",\"text\":\"half of a"}}',
            '{"type":"content_block_stop","index":1}',
            '{"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":40}}',
            '{"type":"message_stop"}',
        ]);

        $http = (new FakeHttp())->respond(200, $stream, ['content-type' => 'text/event-stream', 'request-id' => 'req_011CabcXYZ']);
        $end = self::end(new AnthropicClient($http->client(), 'sk-ant'), $request);

        /** @var list<array<string, mixed>> $sent */
        $sent = $http->lastPayload()['tools'];
        self::assertSame([true, true], \array_column($sent, 'eager_input_streaming'));
        self::assertSame(FinishReason::Length, $end->response->finishReason, 'arguments that do not parse end the answer as cut');
        self::assertCount(1, $end->response->toolCalls, 'the call with broken arguments is never run');
        self::assertSame(['toolu_1', '{"path":"a.txt"}'], [$end->response->toolCalls[0]->id, $end->response->toolCalls[0]->argumentsJson]);
        self::assertSame('req_011CabcXYZ', $end->response->requestId);

        $plain = (new FakeHttp())->respond(200, self::OK, ['request-id' => 'req 1 <b>not an id</b>']);
        $response = (new AnthropicClient($plain->client(), 'sk-ant'))->complete($request);
        /** @var list<array<string, mixed>> $whole */
        $whole = $plain->lastPayload()['tools'];
        self::assertArrayNotHasKey('eager_input_streaming', $whole[0], 'only a stream asks for streamed arguments');
        self::assertNull($response->requestId, 'a header that is not an id is dropped');

        $bedrock = (new FakeHttp())->respond(200, $stream, ['content-type' => 'text/event-stream']);
        self::end(AnthropicClient::bedrock($bedrock->client(), 'token', 'us-east-1'), $request);
        /** @var list<array<string, mixed>> $mantle */
        $mantle = $bedrock->lastPayload()['tools'];
        self::assertArrayNotHasKey('eager_input_streaming', $mantle[0]);
    }

    public function testARefusalCarriesItsReasonAndNoCallToRun(): void
    {
        $refused = '{"id":"msg_1","type":"message","model":"claude-opus-5-5","content":[{"type":"text","text":"I can\'t"},{"type":"tool_use","id":"toolu_1","name":"run","input":{"cmd":"x"}}],'
            . '"stop_reason":"refusal","stop_details":{"type":"refusal","category":"cyber","explanation":"The request asks for an exploit."},'
            . '"usage":{"input_tokens":5,"output_tokens":90,"output_tokens_details":{"thinking_tokens":70}}}';
        $http = (new FakeHttp())->respond(200, $refused);
        $response = (new AnthropicClient($http->client(), 'sk-ant'))->complete(new ChatRequest('claude-opus-5-5', [Message::user('hi')]));

        self::assertSame(FinishReason::ContentFilter, $response->finishReason);
        self::assertSame([], $response->toolCalls);
        self::assertSame(['cyber', 'The request asks for an exploit.'], [$response->refusalCategory, $response->refusalExplanation]);
        self::assertSame([90, 70], [$response->usage->outputTokens, $response->usage->reasoningTokens], 'the tokens of thinking are a part of the output');

        $stream = (new FakeHttp())->respond(200, self::sse([
            '{"type":"message_start","message":{"id":"msg_1","model":"claude-opus-5-5","usage":{"input_tokens":3,"output_tokens":1}}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"run","input":{}}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"cmd\":"}}',
            '{"type":"message_delta","delta":{"stop_reason":"refusal","stop_details":{"type":"refusal","category":"bio","explanation":null}},"usage":{"output_tokens":12,"output_tokens_details":{"thinking_tokens":9}}}',
            '{"type":"message_stop"}',
        ]), ['content-type' => 'text/event-stream']);
        $end = self::end(new AnthropicClient($stream->client(), 'sk-ant'), new ChatRequest('claude-opus-5-5', [Message::user('hi')]));

        self::assertSame(FinishReason::ContentFilter, $end->response->finishReason, 'a refusal is a refusal, not an answer cut by length');
        self::assertSame([], $end->response->toolCalls);
        self::assertSame(['bio', null, 9], [$end->response->refusalCategory, $end->response->refusalExplanation, $end->response->usage->reasoningTokens]);
    }

    public function testAUsedUpWindowNamesTheTimeItReopensAndIsNotRetried(): void
    {
        $limit = '{"type":"error","error":{"type":"rate_limit_error","message":"This request would exceed your account\'s rate limit."}}';
        $http = (new FakeHttp())
            ->respond(429, $limit, ['retry-after' => '5', 'request-id' => 'req_limit', 'anthropic-ratelimit-unified-status' => 'rejected', 'anthropic-ratelimit-unified-reset' => '1893456000'])
            ->respond(200, self::OK);
        $client = new RetryingClient(new AnthropicClient($http->client(), 'token', name: 'Claude subscription'), sleep: static fn() => null);
        try {
            $client->complete(new ChatRequest('claude-opus-5-5', [Message::user('hi')]));
            self::fail('a used-up window was answered');
        } catch (LlmException $e) {
            self::assertSame([ErrorKind::RateLimited, 429, 1893456000, 'req_limit'], [$e->kind, $e->status, $e->resetsAt, $e->requestId]);
            self::assertStringStartsWith('Claude subscription', $e->getMessage(), 'the error is worded with the name of the provider the client serves');
        }
        self::assertCount(1, $http->requests, 'waiting for hours is not a retry: the caller decides');

        // a throttle while the window is open carries the same reset header and is retried as before
        $throttle = (new FakeHttp())
            ->respond(429, $limit, ['retry-after' => '1', 'anthropic-ratelimit-unified-status' => 'allowed', 'anthropic-ratelimit-unified-reset' => '1893456000'])
            ->respond(200, self::OK);
        $answer = (new RetryingClient(new AnthropicClient($throttle->client(), 'token'), sleep: static fn() => null))->complete(new ChatRequest('claude-opus-5-5', [Message::user('hi')]));
        self::assertSame('ok', $answer->text);
        self::assertCount(2, $throttle->requests);

        // a service in front of the API names the time in the body
        $body = (new FakeHttp())->respond(429, '{"type":"error","error":{"type":"rate_limit_error","message":"The limit of the plan is reached.","resets_at":1893456001}}');
        try {
            (new AnthropicClient($body->client(), 'token'))->complete(new ChatRequest('claude-opus-5-5', [Message::user('hi')]));
            self::fail('a used-up window was answered');
        } catch (LlmException $e) {
            self::assertSame(1893456001, $e->resetsAt);
        }
    }
}
