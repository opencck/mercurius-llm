<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * Provider-independent classification of a failed model call.
 */
enum ErrorKind: string
{
    /** Invalid or revoked API key, or access to the model denied. */
    case Auth = 'auth';
    /** Too many requests; see {@see LlmException::$retryAfter}. */
    case RateLimited = 'rate_limited';
    /** The request is malformed or names an unknown model. */
    case BadRequest = 'bad_request';
    /** The conversation does not fit into the model context window. */
    case ContextLength = 'context_length';
    /** The input or output was blocked by moderation. */
    case ContentFilter = 'content_filter';
    /** The provider or upstream model failed (5xx, overloaded, disconnected mid-stream). */
    case Server = 'server';
    /** The request or the provider timed out. */
    case Timeout = 'timeout';
    /** Connection-level failure: DNS, TCP, TLS, connection dropped. */
    case Network = 'network';
    /** The account has no money left. */
    case InsufficientCredits = 'insufficient_credits';
    /** Anything not recognized, including a malformed provider response. */
    case Unknown = 'unknown';

    /**
     * Whether the same request may succeed when sent again later.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::Server, self::Timeout, self::Network => true,
            default => false,
        };
    }
}
