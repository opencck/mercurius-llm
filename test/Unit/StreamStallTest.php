<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\Test\Support\FakeHttp;

use function Amp\delay;

/**
 * A provider that keeps the connection alive with comments but sends nothing is a timeout, not a long wait.
 */
final class StreamStallTest extends AsyncTestCase
{
    /** @param array<string, mixed> $data */
    private static function chunk(array $data): string
    {
        return 'data: ' . \json_encode($data, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    private static function drain(OpenRouterClient $client): void
    {
        foreach ($client->stream(new ChatRequest('a/m', [Message::user('Hi')])) as $_) {
        }
    }

    public function testKeepAliveAloneDoesNotKeepTheStreamAlive(): void
    {
        $http = (new FakeHttp())->respond(200, (static function (): \Generator {
            for ($i = 0; $i < 40; $i++) {
                yield ": OPENROUTER PROCESSING\n\n";
                delay(0.05);
            }
        })(), ['content-type' => 'text/event-stream']);
        $client = new OpenRouterClient($http->client(), 'sk', firstEventTimeout: 0.3, eventTimeout: 0.3);
        $started = \microtime(true);

        try {
            self::drain($client);
            self::fail('expected a timeout');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Timeout, $e->kind);
            self::assertTrue($e->retryable());
            self::assertStringContainsString('no stream events', $e->getMessage());
        }
        self::assertLessThan(1.5, \microtime(true) - $started, 'cut at the first-event limit, not after all keep-alives');
    }

    public function testSilenceAfterTheFirstEventIsATimeoutToo(): void
    {
        $http = (new FakeHttp())->respond(200, (static function (): \Generator {
            yield self::chunk(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => ['content' => 'He']]]]);
            for ($i = 0; $i < 40; $i++) {
                yield ": OPENROUTER PROCESSING\n\n";
                delay(0.05);
            }
        })(), ['content-type' => 'text/event-stream']);
        $client = new OpenRouterClient($http->client(), 'sk', firstEventTimeout: 5.0, eventTimeout: 0.3);

        $this->expectException(LlmException::class);
        self::drain($client);
    }

    public function testSteadyEventsKeepTheStreamGoing(): void
    {
        $http = (new FakeHttp())->respond(200, (static function (): \Generator {
            for ($i = 0; $i < 6; $i++) {
                yield self::chunk(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => ['content' => 'x']]]]);
                delay(0.1);
            }
            yield self::chunk(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]);
            yield "data: [DONE]\n\n";
        })(), ['content-type' => 'text/event-stream']);
        $client = new OpenRouterClient($http->client(), 'sk', firstEventTimeout: 0.3, eventTimeout: 0.3);

        $text = '';
        foreach ($client->stream(new ChatRequest('a/m', [Message::user('Hi')])) as $event) {
            if ($event instanceof \Mercurius\Llm\StreamEnd) {
                $text = $event->response->text;
            }
        }
        self::assertSame('xxxxxx', $text, 'a stream longer than the limits but never silent completes');
    }
}
