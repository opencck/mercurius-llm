<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Llm\Usage;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function testMessageConstructors(): void
    {
        self::assertSame(Role::System, Message::system('s')->role);
        self::assertSame(Role::User, Message::user('u')->role);

        $call = new ToolCall('c1', 'f', '{}');
        $assistant = Message::assistant('', [$call]);
        self::assertSame(Role::Assistant, $assistant->role);
        self::assertSame('', $assistant->content);
        self::assertSame([$call], $assistant->toolCalls);

        $result = Message::toolResult('c1', 'done');
        self::assertSame(Role::Tool, $result->role);
        self::assertSame('c1', $result->toolCallId);
    }

    public function testToolCallsOnlyForAssistant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message(Role::User, 'x', [new ToolCall('c', 'f')]);
    }

    public function testToolMessageNeedsId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message(Role::Tool, 'x');
    }

    public function testToolCallArguments(): void
    {
        self::assertSame(['a' => 1], (new ToolCall('c', 'f', '{"a":1}'))->arguments());
        self::assertSame([], (new ToolCall('c', 'f', ''))->arguments());

        $this->expectException(\JsonException::class);
        (new ToolCall('c', 'f', '{"a":'))->arguments();
    }

    public function testToolCallArgumentsMustBeObject(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ToolCall('c', 'f', '"text"'))->arguments();
    }

    public function testToolNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolDefinition('bad name!', 'x');
    }

    public function testChatRequestWithersAreImmutable(): void
    {
        $base = new ChatRequest('m/a', [Message::user('one')]);
        $changed = $base
            ->withModel('m/b')
            ->withAddedMessages(Message::assistant('two'), Message::user('three'))
            ->withTools([new ToolDefinition('t', 'd')])
            ->withMaxTokens(100)
            ->withTemperature(0.3)
            ->withReasoningEffort('high')
            ->withExtra(['top_p' => 0.9]);

        self::assertSame('m/a', $base->model);
        self::assertCount(1, $base->messages);
        self::assertNull($base->maxTokens);

        self::assertSame('m/b', $changed->model);
        self::assertCount(3, $changed->messages);
        self::assertCount(1, $changed->tools);
        self::assertSame(100, $changed->maxTokens);
        self::assertSame(0.3, $changed->temperature);
        self::assertSame('high', $changed->reasoningEffort);
        self::assertSame(['top_p' => 0.9], $changed->extra);
        self::assertCount(1, $changed->withMessages([Message::user('x')])->messages);
    }

    public function testChatRequestValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatRequest('m', [], [new ToolDefinition('t', 'a'), new ToolDefinition('t', 'b')]);
    }

    public function testUsagePlus(): void
    {
        $a = new Usage(10, 5, 2, 1, 3, 0.5);
        $b = new Usage(1, 1, 1, 1, 1);
        $sum = $a->plus($b);

        self::assertSame(11, $sum->inputTokens);
        self::assertSame(6, $sum->outputTokens);
        self::assertSame(3, $sum->cacheReadTokens);
        self::assertSame(2, $sum->cacheWriteTokens);
        self::assertSame(4, $sum->reasoningTokens);
        self::assertSame(0.5, $sum->costUsd);
        self::assertSame(16, $sum->promptTokens());
        self::assertSame(22, $sum->totalTokens());
        self::assertNull((new Usage())->plus(new Usage())->costUsd);
    }

    public function testFinishReasonMapping(): void
    {
        self::assertSame(FinishReason::Stop, FinishReason::fromOpenAi('stop'));
        self::assertSame(FinishReason::Length, FinishReason::fromOpenAi('length'));
        self::assertSame(FinishReason::ToolCalls, FinishReason::fromOpenAi('tool_calls'));
        self::assertSame(FinishReason::ContentFilter, FinishReason::fromOpenAi('content_filter'));
        self::assertSame(FinishReason::Error, FinishReason::fromOpenAi('error'));
        self::assertSame(FinishReason::Other, FinishReason::fromOpenAi(null));
        self::assertSame(FinishReason::Other, FinishReason::fromOpenAi('weird'));
    }
}
