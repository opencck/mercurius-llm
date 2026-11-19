<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Anthropic\AnthropicClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Message;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolCallDelta;
use Mercurius\Llm\ToolDefinition;

final class AnthropicClientTest extends AsyncTestCase
{
    private const OK = '{"id":"msg_1","type":"message","role":"assistant","model":"claude-opus-5-5","content":['
        . '{"type":"thinking","thinking":"плохая погода","signature":"sig"},'
        . '{"type":"text","text":"Проверю."},'
        . '{"type":"tool_use","id":"toolu_1","name":"weather","input":{"city":"Paris"}}],'
        . '"stop_reason":"tool_use","usage":{"input_tokens":20,"output_tokens":7,"cache_read_input_tokens":900,"cache_creation_input_tokens":100}}';

    /** @param list<string> $events */
    private static function sse(array $events): string
    {
        return \implode('', \array_map(static fn(string $e): string => 'event: x' . "\n" . 'data: ' . $e . "\n\n", $events));
    }

    public function testTheConversationGoesInTheShapeOfTheMessagesApi(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK);
        $client = new AnthropicClient($http->client(), 'sk-ant');

        $response = $client->complete(new ChatRequest('claude-opus-5-5', [
            Message::system('правила')->cached(),
            Message::user('Погода в Париже?', [MediaPart::image('image/png', 'PNG')]),
            Message::assistant('', [new ToolCall('toolu_0', 'weather', '{"city":"Paris"}')]),
            Message::toolResult('toolu_0', 'дождь'),
            Message::user('а завтра?'),
        ], [new ToolDefinition('weather', 'Погода', ['type' => 'object', 'properties' => []])], 2000, 0.7, 'high'));

        $request = $http->requests[0];
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        self::assertSame('sk-ant', $request->getHeader('x-api-key'));
        self::assertSame('2026-10-21', $request->getHeader('anthropic-version'));
        self::assertNull($request->getHeader('authorization'));
        $payload = $http->lastPayload();
        self::assertSame([['type' => 'text', 'text' => 'правила', 'cache_control' => ['type' => 'ephemeral']]], $payload['system']);
        self::assertSame([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Погода в Париже?'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => \base64_encode('PNG')]],
            ]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_0', 'name' => 'weather', 'input' => ['city' => 'Paris']]]],
            // a tool result and the next words of the person are one user turn: the roles alternate
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_0', 'content' => 'дождь'],
                ['type' => 'text', 'text' => 'а завтра?'],
            ]],
        ], $payload['messages']);
        self::assertSame([['name' => 'weather', 'description' => 'Погода', 'input_schema' => ['type' => 'object', 'properties' => []]]], $payload['tools']);
        self::assertSame(['effort' => 'high'], $payload['output_config']);
        self::assertSame(2000, $payload['max_tokens']);
        self::assertArrayNotHasKey('temperature', $payload, 'the version 2026-10-21 refuses sampling fields');

        self::assertSame('Проверю.', $response->text);
        self::assertSame('плохая погода', $response->reasoning);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame([['toolu_1', 'weather', '{"city":"Paris"}']], \array_map(static fn(ToolCall $c): array => [$c->id, $c->name, $c->argumentsJson], $response->toolCalls));
        self::assertSame([20, 7, 900, 100], [$response->usage->inputTokens, $response->usage->outputTokens, $response->usage->cacheReadTokens, $response->usage->cacheWriteTokens]);
    }

    public function testAStreamGivesTextReasoningToolCallsAndTheAnswer(): void
    {
        $http = (new FakeHttp())->respond(200, [self::sse([
            '{"type":"message_start","message":{"id":"msg_2","model":"claude-opus-5-5","usage":{"input_tokens":10,"output_tokens":1,"cache_read_input_tokens":500}}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"думаю"}}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Сейчас"}}',
            '{"type":"ping"}',
            '{"type":"content_block_start","index":2,"content_block":{"type":"tool_use","id":"toolu_9","name":"weather","input":{}}}',
            '{"type":"content_block_delta","index":2,"delta":{"type":"input_json_delta","partial_json":"{\"city\":"}}',
            '{"type":"content_block_delta","index":2,"delta":{"type":"input_json_delta","partial_json":"\"Paris\"}"}}',
            '{"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":30}}',
            '{"type":"message_stop"}',
        ])], ['content-type' => 'text/event-stream']);

        $events = \iterator_to_array((new AnthropicClient($http->client(), 'sk-ant'))->stream(new ChatRequest('claude-opus-5-5', [Message::user('погода?')])), false);

        self::assertTrue($http->lastPayload()['stream']);
        self::assertEquals([
            new ReasoningDelta('думаю'),
            new TextDelta('Сейчас'),
            new ToolCallDelta(0, 'toolu_9', 'weather', ''),
            new ToolCallDelta(0, null, null, '{"city":'),
            new ToolCallDelta(0, null, null, '"Paris"}'),
        ], \array_slice($events, 0, 5));
        $end = \end($events);
        self::assertInstanceOf(StreamEnd::class, $end);
        self::assertSame(['Сейчас', '{"city":"Paris"}', FinishReason::ToolCalls, 30, 500, 'msg_2'], [$end->response->text, $end->response->toolCalls[0]->argumentsJson, $end->response->finishReason, $end->response->usage->outputTokens, $end->response->usage->cacheReadTokens, $end->response->id]);
    }

    public function testAnOverloadIsRetryableAndAnErrorEventStopsTheStream(): void
    {
        $http = (new FakeHttp())
            ->respond(529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')
            ->respond(200, [self::sse(['{"type":"message_start","message":{"id":"m","model":"x","usage":{"input_tokens":1,"output_tokens":0}}}', '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'])], ['content-type' => 'text/event-stream']);
        $client = new AnthropicClient($http->client(), 'sk-ant');

        try {
            $client->complete(new ChatRequest('claude-opus-5-5', [Message::user('x')]));
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
            self::assertTrue($e->retryable());
            self::assertStringStartsWith('Anthropic', $e->getMessage());
        }
        try {
            \iterator_to_array($client->stream(new ChatRequest('claude-opus-5-5', [Message::user('x')])), false);
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
        }
    }
}
