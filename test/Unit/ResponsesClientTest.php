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
use Mercurius\Llm\OpenAI\ResponsesClient;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolCallDelta;
use Mercurius\Llm\ToolDefinition;

final class ResponsesClientTest extends AsyncTestCase
{
    private const OK = '{"id":"resp_1","object":"response","model":"gpt-6.1-codex","status":"completed","output":['
        . '{"type":"reasoning","id":"rs_1","summary":[{"type":"summary_text","text":"нужна погода"}]},'
        . '{"type":"message","id":"msg_1","role":"assistant","content":[{"type":"output_text","text":"Проверю."}]},'
        . '{"type":"function_call","id":"fc_1","call_id":"call_1","name":"weather","arguments":"{\"city\":\"Paris\"}"}],'
        . '"usage":{"input_tokens":1000,"input_tokens_details":{"cached_tokens":800},"output_tokens":50,"output_tokens_details":{"reasoning_tokens":30}}}';

    /** @param list<string> $events */
    private static function sse(array $events): string
    {
        return \implode('', \array_map(static fn(string $e): string => 'data: ' . $e . "\n\n", $events));
    }

    public function testTheConversationGoesAsItemsOfTheResponsesApi(): void
    {
        $http = (new FakeHttp())->respond(200, self::OK);

        $response = (new ResponsesClient($http->client(), 'sk-proj'))->complete(new ChatRequest('gpt-6.1-codex', [
            Message::system('правила'),
            Message::user('Погода?', [MediaPart::image('image/png', 'PNG')]),
            Message::assistant('Смотрю', [new ToolCall('call_0', 'weather', '{"city":"Paris"}')]),
            Message::toolResult('call_0', 'дождь'),
        ], [new ToolDefinition('weather', 'Погода')], 2000, 0.5, 'high', ['prompt_cache_key' => 'conversation-1']));

        $request = $http->requests[0];
        self::assertSame('https://api.openai.com/v1/responses', (string) $request->getUri());
        self::assertSame('Bearer sk-proj', $request->getHeader('authorization'));
        $payload = $http->lastPayload();
        self::assertSame('правила', $payload['instructions']);
        self::assertSame([
            ['type' => 'message', 'role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => 'Погода?'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . \base64_encode('PNG')],
            ]],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Смотрю']]],
            ['type' => 'function_call', 'call_id' => 'call_0', 'name' => 'weather', 'arguments' => '{"city":"Paris"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_0', 'output' => 'дождь'],
        ], $payload['input']);
        self::assertSame([['type' => 'function', 'name' => 'weather', 'description' => 'Погода', 'parameters' => ['type' => 'object', 'properties' => []], 'strict' => false]], $payload['tools']);
        self::assertSame(['effort' => 'high'], $payload['reasoning']);
        self::assertSame(2000, $payload['max_output_tokens']);
        self::assertFalse($payload['store'], 'nothing is kept on the provider side');
        self::assertSame('conversation-1', $payload['prompt_cache_key']);
        self::assertArrayNotHasKey('temperature', $payload, 'reasoning models refuse it');

        self::assertSame('Проверю.', $response->text);
        self::assertSame('нужна погода', $response->reasoning);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame([['call_1', 'weather', '{"city":"Paris"}']], \array_map(static fn(ToolCall $c): array => [$c->id, $c->name, $c->argumentsJson], $response->toolCalls));
        self::assertSame([200, 50, 800, 30], [$response->usage->inputTokens, $response->usage->outputTokens, $response->usage->cacheReadTokens, $response->usage->reasoningTokens], 'cached tokens are a part of the input');
    }

    public function testAStreamGivesTextReasoningAndToolCalls(): void
    {
        $http = (new FakeHttp())->respond(200, [self::sse([
            '{"type":"response.created","response":{"id":"resp_2","model":"gpt-6.1-codex","status":"in_progress"}}',
            '{"type":"response.reasoning_summary_text.delta","output_index":0,"delta":"думаю"}',
            '{"type":"response.output_text.delta","output_index":1,"delta":"Сейчас"}',
            '{"type":"response.output_item.added","output_index":2,"item":{"type":"function_call","id":"fc_9","call_id":"call_9","name":"weather","arguments":""}}',
            '{"type":"response.function_call_arguments.delta","output_index":2,"delta":"{\"city\":"}',
            '{"type":"response.function_call_arguments.delta","output_index":2,"delta":"\"Paris\"}"}',
            '{"type":"response.completed","response":{"id":"resp_2","model":"gpt-6.1-codex","status":"completed","usage":{"input_tokens":10,"output_tokens":5}}}',
        ])], ['content-type' => 'text/event-stream']);

        $events = \iterator_to_array((new ResponsesClient($http->client(), 'sk-proj'))->stream(new ChatRequest('gpt-6.1-codex', [Message::user('погода?')])), false);

        self::assertTrue($http->lastPayload()['stream']);
        self::assertEquals([
            new ReasoningDelta('думаю'),
            new TextDelta('Сейчас'),
            new ToolCallDelta(0, 'call_9', 'weather', ''),
            new ToolCallDelta(0, null, null, '{"city":'),
            new ToolCallDelta(0, null, null, '"Paris"}'),
        ], \array_slice($events, 0, 5));
        $end = \end($events);
        self::assertInstanceOf(StreamEnd::class, $end);
        self::assertSame(['Сейчас', '{"city":"Paris"}', FinishReason::ToolCalls, 5, 'resp_2'], [$end->response->text, $end->response->toolCalls[0]->argumentsJson, $end->response->finishReason, $end->response->usage->outputTokens, $end->response->id]);
    }

    public function testAnIncompleteAnswerIsCutAndAFailureIsAnError(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"id":"r","model":"m","status":"incomplete","incomplete_details":{"reason":"max_output_tokens"},"output":[{"type":"message","content":[{"type":"output_text","text":"нач"}]}],"usage":{"input_tokens":1,"output_tokens":1}}')
            ->respond(200, [self::sse(['{"type":"response.created","response":{"id":"r","model":"m"}}', '{"type":"response.failed","response":{"id":"r","status":"failed","error":{"code":"server_error","message":"boom"}}}'])], ['content-type' => 'text/event-stream'])
            ->respond(429, '{"error":{"message":"Rate limit reached","type":"requests","code":"rate_limit_exceeded"}}');
        $client = new ResponsesClient($http->client(), 'sk-proj');

        self::assertSame(FinishReason::Length, $client->complete(new ChatRequest('m', [Message::user('x')]))->finishReason);
        try {
            \iterator_to_array($client->stream(new ChatRequest('m', [Message::user('x')])), false);
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
            self::assertStringStartsWith('OpenAI', $e->getMessage());
        }
        try {
            $client->complete(new ChatRequest('m', [Message::user('x')]));
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertStringStartsWith('OpenAI', $e->getMessage());
        }
    }
}
