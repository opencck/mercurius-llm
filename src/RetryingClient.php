<?php

declare(strict_types=1);

namespace Mercurius\Llm;

use Amp\Cancellation;

use function Amp\delay;

/**
 * Decorator that retries retryable failures ({@see ErrorKind::retryable()}) with exponential backoff and full jitter.
 *
 * When the provider sent `Retry-After`, that delay is used instead of the backoff; if it is longer than
 * `$maxRetryAfter`, the error is rethrown at once. A stream is retried only if it failed before its first event:
 * once something was yielded, the caller already holds partial output and the error is passed through.
 */
final class RetryingClient implements LlmClient
{
    /** @var \Closure(float, ?Cancellation): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): float */
    private readonly \Closure $random;

    /**
     * @param int $maxAttempts Total attempts including the first one.
     * @param float $baseDelay Backoff for the first retry, in seconds; doubles with every attempt.
     * @param float $maxDelay Upper bound of the backoff, in seconds.
     * @param float $maxRetryAfter Longest provider-requested wait that is still honoured, in seconds.
     * @param (\Closure(LlmException, int, float): void)|null $onRetry Called before each wait with the error,
     *                                                                the number of the failed attempt and the delay.
     * @param (\Closure(float, ?Cancellation): void)|null $sleep Waits; defaults to {@see delay()}.
     * @param (\Closure(): float)|null $random Returns a number in [0, 1); used for jitter.
     */
    public function __construct(
        private readonly LlmClient $inner,
        private readonly int $maxAttempts = 3,
        private readonly float $baseDelay = 1.0,
        private readonly float $maxDelay = 30.0,
        private readonly float $maxRetryAfter = 60.0,
        private readonly ?\Closure $onRetry = null,
        ?\Closure $sleep = null,
        ?\Closure $random = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1');
        }
        if ($baseDelay < 0.0 || $maxDelay < 0.0 || $maxRetryAfter < 0.0) {
            throw new \InvalidArgumentException('Delays must not be negative');
        }
        $this->sleep = $sleep ?? static function (float $seconds, ?Cancellation $cancellation): void {
            delay($seconds, cancellation: $cancellation);
        };
        $this->random = $random ?? static fn (): float => \mt_rand() / (\mt_getrandmax() + 1);
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->inner->complete($request, $cancellation);
            } catch (LlmException $e) {
                $this->backoff($e, $attempt, $cancellation);
            }
        }
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        for ($attempt = 1; ; $attempt++) {
            $events = self::generate($this->inner->stream($request, $cancellation));
            try {
                // Starting the iteration sends the request and waits for the first event.
                $events->current();
            } catch (LlmException $e) {
                $this->backoff($e, $attempt, $cancellation);
                continue;
            }

            // Something has been received (or the stream ended): from here on errors are passed through.
            yield from $events;

            return;
        }
    }

    /**
     * @param iterable<int, StreamEvent> $events
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    private static function generate(iterable $events): \Generator
    {
        yield from $events;
    }

    /**
     * Waits before the next attempt or rethrows the error when it must not be retried.
     *
     * @throws LlmException
     */
    private function backoff(LlmException $e, int $attempt, ?Cancellation $cancellation): void
    {
        if (!$e->retryable() || $attempt >= $this->maxAttempts) {
            throw $e;
        }
        // A used-up window with a named reset time: waiting here would hold the call for hours. The caller decides.
        if ($e->resetsAt !== null) {
            throw $e;
        }

        if ($e->retryAfter !== null) {
            if ($e->retryAfter > $this->maxRetryAfter) {
                throw $e;
            }
            $seconds = $e->retryAfter;
        } else {
            $cap = \min($this->maxDelay, $this->baseDelay * (float) (2 ** ($attempt - 1)));
            $seconds = ($this->random)() * $cap;
        }

        if ($this->onRetry !== null) {
            ($this->onRetry)($e, $attempt, $seconds);
        }
        ($this->sleep)($seconds, $cancellation);
    }
}
