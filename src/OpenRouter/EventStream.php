<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;

/**
 * The data events of a server-sent event stream, decoded as JSON objects, with a stall timer: the first event must
 * come within one limit and each next one within another. The consumer stops when its protocol says the answer is
 * complete; leaving the loop closes the body. Errors name the provider.
 *
 * @internal
 */
final class EventStream
{
    /**
     * @return \Generator<int, array<array-key, mixed>, mixed, void>
     * @throws LlmException
     * @throws CancelledException When the caller cancelled.
     */
    public static function events(Response $response, string $provider, float $firstEventTimeout, float $eventTimeout, ?Cancellation $cancellation): \Generator
    {
        $body = $response->getBody();
        $parser = new SseParser();
        // ref: references/hermes-agent/agent/chat_completion_helpers.py@e96221fa:L2835-L2842 (_count_chunk) — only
        //      events re-arm the stall timer; the HTTP inactivity timeout counts bytes and keep-alives would feed it.
        $deadline = \microtime(true) + $firstEventTimeout;
        $limit = $firstEventTimeout;

        try {
            while (true) {
                $left = $deadline - \microtime(true);
                if ($left <= 0) {
                    throw self::stalled($provider, $limit);
                }
                $timeout = new TimeoutCancellation($left);
                try {
                    $chunk = $body->read($cancellation === null ? $timeout : new CompositeCancellation($cancellation, $timeout));
                } catch (StreamException $e) {
                    throw self::named($provider, ErrorClassifier::fromStreamException($e));
                } catch (CancelledException $e) {
                    if (self::requested($cancellation)) {
                        throw $e;
                    }
                    throw self::stalled($provider, $limit);
                }

                try {
                    $payloads = $chunk === null ? \array_filter([$parser->finish()], static fn(?string $p): bool => $p !== null) : $parser->push($chunk);
                } catch (LlmException $e) {
                    throw self::named($provider, $e);
                }
                if ($payloads !== []) {
                    $deadline = \microtime(true) + $eventTimeout;
                    $limit = $eventTimeout;
                }
                foreach ($payloads as $payload) {
                    try {
                        yield HttpTransport::decode($payload);
                    } catch (LlmException $e) {
                        throw self::named($provider, $e);
                    }
                }
                if ($chunk === null) {
                    break;
                }
            }
        } finally {
            $body->close();
        }
    }

    /** The shared transport says "OpenRouter" in its errors; another provider gets its own name. */
    public static function named(string $provider, LlmException $e): LlmException
    {
        if ($provider === 'OpenRouter' || !\str_starts_with($e->getMessage(), 'OpenRouter')) {
            return $e;
        }

        return new LlmException($e->kind, $provider . \substr($e->getMessage(), \strlen('OpenRouter')), $e->status, $e->retryAfter, $e);
    }

    /** Whether the caller (not the stall timer) cancelled. */
    private static function requested(?Cancellation $cancellation): bool
    {
        return $cancellation !== null && $cancellation->isRequested();
    }

    /** A retryable timeout: before the first event a retry is safe, after it the caller decides. */
    private static function stalled(string $provider, float $seconds): LlmException
    {
        return new LlmException(ErrorKind::Timeout, \sprintf('%s: no stream events for %d s', $provider, (int) \ceil($seconds)));
    }
}
