<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A failed model call.
 *
 * The message contains the provider's error text (truncated) and never the API key or the request body.
 */
final class LlmException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorKind $kind,
        string $message,
        /** HTTP status (or the code of a mid-stream error), when known. */
        public readonly ?int $status = null,
        /** Seconds the provider asked to wait before retrying, when it said so. */
        public readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
        /**
         * Unix time a used-up limit window reopens, when the provider named it. Such a limit is not a throttle: a
         * retry before that time fails the same way, so the caller decides what to do, not the retry loop.
         */
        public readonly ?int $resetsAt = null,
        /** Provider-side id of the failed request, for support; null when the provider sent none. */
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function retryable(): bool
    {
        return $this->kind->retryable();
    }
}
