<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\RetryingClient;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\Test\Support\FakeLlmClient;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\Usage;

use function Amp\async;

final class RetryingClientTest extends AsyncTestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private static function request(): ChatRequest
    {
        return new ChatRequest('m/x', [Message::user('hi')]);
    }

    private static function response(string $text = 'ok'): ChatResponse
    {
        return new ChatResponse($text, [], FinishReason::Stop, new Usage(), 'm/x', 'gen');
    }

    private function retrying(FakeLlmClient $inner, int $maxAttempts = 3, float $random = 0.5): RetryingClient
    {
        return new RetryingClient(
            $inner,
            maxAttempts: $maxAttempts,
            baseDelay: 1.0,
            maxDelay: 3.0,
            maxRetryAfter: 20.0,
            sleep: function (float $seconds, ?Cancellation $cancellation): void {
                $this->sleeps[] = $seconds;
            },
            random: static fn (): float => $random,
        );
    }

    public function testRetriesRetryableErrorsWithExponentialJitteredBackoff(): void
    {
        $inner = new FakeLlmClient([
            new LlmException(ErrorKind::Server, 'boom'),
            new LlmException(ErrorKind::Network, 'reset'),
            new LlmException(ErrorKind::Timeout, 'slow'),
            self::response(),
        ]);
        $response = $this->retrying($inner, maxAttempts: 4)->complete(self::request());

        self::assertSame('ok', $response->text);
        self::assertSame(4, $inner->calls);
        // full jitter: random (0.5) * min(maxDelay 3, base 1 * 2^(n-1)) => 0.5, 1.0, 1.5 (capped at 3)
        self::assertSame([0.5, 1.0, 1.5], $this->sleeps);
    }

    public function testDoesNotRetryNonRetryableErrors(): void
    {
        $inner = new FakeLlmClient([new LlmException(ErrorKind::Auth, 'bad key'), self::response()]);

        try {
            $this->retrying($inner)->complete(self::request());
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
        }
        self::assertSame(1, $inner->calls);
        self::assertSame([], $this->sleeps);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $inner = new FakeLlmClient([
            new LlmException(ErrorKind::Server, 'one'),
            new LlmException(ErrorKind::Server, 'two'),
            self::response(),
        ]);

        try {
            $this->retrying($inner, maxAttempts: 2)->complete(self::request());
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame('two', $e->getMessage());
        }
        self::assertSame(2, $inner->calls);
    }

    public function testRespectsRetryAfter(): void
    {
        $inner = new FakeLlmClient([new LlmException(ErrorKind::RateLimited, 'slow down', 429, 7.5), self::response()]);
        $this->retrying($inner)->complete(self::request());

        self::assertSame([7.5], $this->sleeps);
    }

    public function testRetryAfterLongerThanLimitIsNotWaited(): void
    {
        $inner = new FakeLlmClient([new LlmException(ErrorKind::RateLimited, 'tomorrow', 429, 3600.0), self::response()]);

        $this->expectException(LlmException::class);
        try {
            $this->retrying($inner)->complete(self::request());
        } finally {
            self::assertSame(1, $inner->calls);
        }
    }

    public function testStreamRetriedBeforeFirstEvent(): void
    {
        $end = new StreamEnd(self::response('streamed'));
        $inner = new FakeLlmClient([
            new LlmException(ErrorKind::Server, 'before start'),
            [new LlmException(ErrorKind::Network, 'still before start')],
            [new TextDelta('streamed'), $end],
        ]);

        $events = [];
        foreach ($this->retrying($inner)->stream(self::request()) as $event) {
            $events[] = $event;
        }

        self::assertSame(3, $inner->calls);
        self::assertCount(2, $events);
        self::assertSame($end, $events[1]);
    }

    public function testStreamNotRetriedAfterFirstEvent(): void
    {
        $inner = new FakeLlmClient([
            [new TextDelta('par'), new LlmException(ErrorKind::Server, 'mid-stream')],
            [new TextDelta('should not happen')],
        ]);

        $events = [];
        try {
            foreach ($this->retrying($inner)->stream(self::request()) as $event) {
                $events[] = $event;
            }
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame('mid-stream', $e->getMessage());
        }
        self::assertSame(1, $inner->calls);
        self::assertCount(1, $events);
    }

    public function testDefaultSleepIsCancellable(): void
    {
        $inner = new FakeLlmClient([new LlmException(ErrorKind::RateLimited, 'wait', 429, 10.0), self::response()]);
        $client = new RetryingClient($inner);
        $deferred = new DeferredCancellation();

        $future = async(fn () => $client->complete(self::request(), $deferred->getCancellation()));
        \Amp\delay(0.01);
        $deferred->cancel();

        $this->expectException(CancelledException::class);
        $future->await();
    }

    public function testDefaultSleepActuallyWaits(): void
    {
        $inner = new FakeLlmClient([new LlmException(ErrorKind::Server, 'x', 500, 0.05), self::response()]);
        $start = \microtime(true);
        (new RetryingClient($inner))->complete(self::request());

        self::assertGreaterThanOrEqual(0.04, \microtime(true) - $start);
    }
}
