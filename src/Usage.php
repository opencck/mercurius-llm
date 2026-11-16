<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * Token and cost accounting of one or more calls.
 *
 * Input is split into three disjoint buckets: {@see $inputTokens} (not served from cache), {@see $cacheReadTokens}
 * and {@see $cacheWriteTokens}; their sum is {@see promptTokens()}. {@see $reasoningTokens} is a part of
 * {@see $outputTokens}, not an addition to it.
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $reasoningTokens = 0,
        /** Cost reported by the provider, in USD; null when the provider did not report it. */
        public readonly ?float $costUsd = null,
    ) {
    }

    /**
     * All input tokens: uncached, read from cache and written to cache.
     */
    public function promptTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    public function totalTokens(): int
    {
        return $this->promptTokens() + $this->outputTokens;
    }

    /**
     * Sum of two usages. The cost stays null only when both are null.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->reasoningTokens + $other->reasoningTokens,
            $this->costUsd === null && $other->costUsd === null
                ? null
                : ($this->costUsd ?? 0.0) + ($other->costUsd ?? 0.0),
        );
    }
}
