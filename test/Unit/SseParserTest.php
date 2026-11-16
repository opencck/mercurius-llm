<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\SseParser;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    public function testParsesEventsAndSkipsComments(): void
    {
        $parser = new SseParser();
        $events = $parser->push(": OPENROUTER PROCESSING\n\ndata: {\"a\":1}\n\n: OPENROUTER PROCESSING\n\ndata: [DONE]\n\n");

        self::assertSame(['{"a":1}', '[DONE]'], $events);
    }

    public function testEventSplitAcrossChunks(): void
    {
        $parser = new SseParser();
        self::assertSame([], $parser->push('da'));
        self::assertSame([], $parser->push('ta: {"x"'));
        self::assertSame([], $parser->push(":2}\n"));
        self::assertSame(['{"x":2}'], $parser->push("\n"));
    }

    public function testCrLfAndCrLineEndings(): void
    {
        $parser = new SseParser();
        self::assertSame([], $parser->push("data: one\r"));
        self::assertSame(['one'], $parser->push("\n\r\n"));
        // A trailing CR may be half of CRLF, so the event is dispatched once the next byte arrives.
        self::assertSame([], $parser->push("data: two\r\r"));
        self::assertSame(['two'], $parser->push('data: three'));
        self::assertSame('three', $parser->finish());
    }

    public function testMultiLineDataAndOtherFields(): void
    {
        $parser = new SseParser();
        $events = $parser->push("event: message\nid: 7\ndata: first\ndata:second\n\n");

        self::assertSame(["first\nsecond"], $events);
    }

    public function testFinishFlushesTrailingEvent(): void
    {
        $parser = new SseParser();
        self::assertSame([], $parser->push('data: tail'));
        self::assertSame('tail', $parser->finish());
        self::assertNull($parser->finish());
    }

    public function testEventSizeLimit(): void
    {
        $parser = new SseParser(16);
        $this->expectException(LlmException::class);
        $parser->push('data: ' . \str_repeat('x', 64));
    }
}
