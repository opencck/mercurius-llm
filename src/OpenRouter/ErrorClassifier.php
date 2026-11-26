<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\StreamException;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\TimeoutException;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;

/**
 * Turns OpenRouter error responses (HTTP status, error body, mid-stream error chunks) and transport failures into
 * {@see LlmException}.
 *
 * Error body format: `{"error": {"code": 429, "message": "...", "metadata": {...}}}`.
 *
 * @internal
 */
final class ErrorClassifier
{
    private const MAX_MESSAGE_LENGTH = 500;

    private const CONTEXT_PATTERNS = [
        'context length',
        'context_length',
        'context window',
        'maximum context',
        'too many tokens',
        'prompt is too long',
        'input is too long',
        'reduce the length',
    ];

    private const MODERATION_PATTERNS = ['moderation', 'flagged'];

    /**
     * Classifies a non-2xx HTTP response.
     *
     * @param string $body Raw response body (only the error message is kept).
     * @param array<string, ?string> $headers Other headers of the response by lower-case name: the id of the request
     *                                        and, for the Messages API, the state of the limit window.
     */
    public static function fromHttpResponse(int $status, string $body, ?string $retryAfterHeader, float $now, array $headers = []): LlmException
    {
        $message = self::extractMessage($body) ?? \sprintf('HTTP %d', $status);

        return new LlmException(
            self::kindFor($status, $message),
            self::format($status, $message),
            $status,
            self::parseRetryAfter($retryAfterHeader, $now) ?? ($status === 429 ? self::retryInMessage($message) : null),
            null,
            $status === 429 ? self::windowReset($body, $headers) : null,
            self::requestId($headers['request-id'] ?? $headers['x-request-id'] ?? null),
        );
    }

    /**
     * An id of a request as a provider sends it; anything else is dropped, so a header never carries text into a
     * log or a message.
     */
    public static function requestId(?string $value): ?string
    {
        return $value !== null && \preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) === 1 ? $value : null;
    }

    /**
     * Unix time a used-up limit window reopens: `error.resets_at` of the body, or the reset header of a window the
     * provider says is rejected. Every answer of the Messages API carries the reset of the window, so the header
     * alone means nothing: a throttle while the window is open must not look like a limit of hours.
     *
     * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L86-L98
     *      (rejected_window_reset) — only a 429 with the status `rejected` makes the reset this answer's.
     *
     * @param array<string, ?string> $headers
     */
    private static function windowReset(string $body, array $headers): ?int
    {
        try {
            /** @var mixed $data */
            $data = \json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        /** @var mixed $inBody */
        $inBody = \is_array($data) && \is_array($data['error'] ?? null) ? ($data['error']['resets_at'] ?? null) : null;
        if (\is_int($inBody) && $inBody > 0) {
            return $inBody;
        }
        $reset = $headers['anthropic-ratelimit-unified-reset'] ?? null;
        if (\trim($headers['anthropic-ratelimit-unified-status'] ?? '') !== 'rejected' || $reset === null || !\is_numeric($reset)) {
            return null;
        }
        $time = (int) \round((float) $reset);

        return $time > 0 ? $time : null;
    }

    /**
     * Classifies an `error` object that came inside a 200 response or a stream chunk.
     *
     * @param array<array-key, mixed> $error
     */
    public static function fromErrorObject(array $error): LlmException
    {
        $status = Json::int($error, 'code');
        $message = Json::string($error, 'message') ?? 'Provider error';
        $kind = $status !== null
            ? self::kindFor($status, $message)
            : (self::kindForMessage($message) ?? ErrorKind::Unknown);

        return new LlmException($kind, self::format($status, $message), $status);
    }

    public static function fromHttpException(HttpException $e): LlmException
    {
        return $e instanceof TimeoutException
            ? new LlmException(ErrorKind::Timeout, 'Request timed out: ' . $e->getMessage(), null, null, $e)
            : new LlmException(ErrorKind::Network, 'Network error: ' . $e->getMessage(), null, null, $e);
    }

    /**
     * A failure while reading a response body. The HTTP client wraps the cause (e.g. an inactivity timeout) into a
     * {@see StreamException}; the cause decides the kind.
     */
    public static function fromStreamException(StreamException $e): LlmException
    {
        $cause = $e->getPrevious();
        if ($cause instanceof HttpException) {
            return self::fromHttpException($cause);
        }

        return new LlmException(ErrorKind::Network, 'Network error: ' . $e->getMessage(), null, null, $e);
    }

    public static function kindFor(int $status, string $message): ErrorKind
    {
        $byMessage = self::kindForMessage($message);

        return match (true) {
            $status === 401 => ErrorKind::Auth,
            $status === 402 => ErrorKind::InsufficientCredits,
            $status === 403 => $byMessage === ErrorKind::ContentFilter ? ErrorKind::ContentFilter : ErrorKind::Auth,
            $status === 408 => ErrorKind::Timeout,
            $status === 413 => ErrorKind::ContextLength,
            // OpenAI reports a spent balance as 429 too; waiting does not help it.
            $status === 429 => $byMessage === ErrorKind::InsufficientCredits ? ErrorKind::InsufficientCredits : ErrorKind::RateLimited,
            $status >= 400 && $status < 500 => $byMessage === ErrorKind::ContextLength ? ErrorKind::ContextLength : ErrorKind::BadRequest,
            $status >= 500 && $status < 600 => ErrorKind::Server,
            default => ErrorKind::Unknown,
        };
    }

    /**
     * Parses `Retry-After`: delta-seconds or an HTTP date. Returns seconds from now, never negative.
     */
    public static function parseRetryAfter(?string $value, float $now): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = \trim($value);
        if ($value === '') {
            return null;
        }
        if (\is_numeric($value)) {
            return \max(0.0, (float) $value);
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $value);
        if ($date === false) {
            return null;
        }

        return \max(0.0, (float) $date->getTimestamp() - $now);
    }

    private static function kindForMessage(string $message): ?ErrorKind
    {
        $lower = \strtolower($message);
        foreach (self::CONTEXT_PATTERNS as $pattern) {
            if (\str_contains($lower, $pattern)) {
                return ErrorKind::ContextLength;
            }
        }
        foreach (self::MODERATION_PATTERNS as $pattern) {
            if (\str_contains($lower, $pattern)) {
                return ErrorKind::ContentFilter;
            }
        }
        // ref: references/hermes-agent/agent/error_classifier.py@e96221fa:L115 — billing phrases, insufficient_quota
        foreach (['exceeded your current quota', 'insufficient_quota', 'insufficient credits', 'insufficient balance'] as $pattern) {
            if (\str_contains($lower, $pattern)) {
                // Google words a used-up window of requests the same way OpenAI words a spent balance: a quota
                // that names its window or a time to retry is a limit, and waiting helps it.
                return self::namesWindow($lower) ? null : ErrorKind::InsufficientCredits;
            }
        }

        return null;
    }

    /**
     * Whether a message about a quota names a window that reopens.
     *
     * ref: references/hermes-agent/agent/error_classifier.py@e96221f:L200-L203 (_USAGE_LIMIT_TRANSIENT_SIGNALS) — the
     *      signs of a periodic quota; of them the ones Google's messages carry
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L232-L234 (is_free_tier_quota_error) — the
     *      free tier names itself in the metric
     */
    private static function namesWindow(string $lower): bool
    {
        foreach (['retry in', 'try again', 'per minute', 'per day', 'per second', 'perminute', 'perday', 'free_tier', 'resets in', 'resets at'] as $sign) {
            if (\str_contains($lower, $sign)) {
                return true;
            }
        }

        return false;
    }

    /** Seconds from "Please retry in 34.5s" of a Google quota message. */
    private static function retryInMessage(string $message): ?float
    {
        return \preg_match('/retry in (\d+(?:\.\d+)?)\s*s\b/i', $message, $m) === 1 ? (float) $m[1] : null;
    }

    private static function extractMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        try {
            $decoded = \json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null; // not JSON (e.g. an HTML page from a proxy): do not echo it
        }
        if (!\is_array($decoded)) {
            return null;
        }
        // Google's OpenAI-compatible address wraps the error object in a list
        if (\array_is_list($decoded) && $decoded !== []) {
            $decoded = Json::object($decoded, 0);
        }
        return Json::string(Json::object($decoded, 'error'), 'message') ?? Json::string($decoded, 'error');
    }

    private static function format(?int $status, string $message): string
    {
        $message = \preg_replace('/\s+/', ' ', $message) ?? $message;
        if (\strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = \mb_strcut($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8') . '...';
        }

        return $status === null ? 'OpenRouter: ' . $message : \sprintf('OpenRouter (%d): %s', $status, $message);
    }
}
