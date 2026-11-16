<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Mercurius\Llm\Usage;

/**
 * A model as described by the provider's catalog.
 */
final class ModelInfo
{
    /**
     * @param float|null $inputPricePerToken USD per input token; null when unknown or variable (e.g. routers).
     * @param float|null $outputPricePerToken USD per output token; null when unknown or variable.
     * @param list<string> $inputModalities E.g. "text", "image", "file", "audio".
     * @param list<string> $outputModalities
     * @param list<string> $supportedParameters Request parameters the model accepts, e.g. "tools", "reasoning".
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?int $contextLength,
        public readonly ?float $inputPricePerToken,
        public readonly ?float $outputPricePerToken,
        public readonly array $inputModalities = [],
        public readonly array $outputModalities = [],
        public readonly array $supportedParameters = [],
        /** Output token limit of the top provider, when published. */
        public readonly ?int $maxCompletionTokens = null,
        /** USD per token read from the prompt cache, when published. */
        public readonly ?float $cacheReadPricePerToken = null,
        /** USD per token written to the prompt cache, when published. */
        public readonly ?float $cacheWritePricePerToken = null,
    ) {
    }

    public function supports(string $parameter): bool
    {
        return \in_array($parameter, $this->supportedParameters, true);
    }

    public function supportsTools(): bool
    {
        return $this->supports('tools');
    }

    /**
     * Estimates the cost of a call from the catalog prices; null when a needed price is unknown.
     * Cache buckets fall back to the input price when no cache price is published.
     */
    public function estimateCost(Usage $usage): ?float
    {
        if ($this->inputPricePerToken === null || $this->outputPricePerToken === null) {
            return null;
        }

        return (float) $usage->inputTokens * $this->inputPricePerToken
            + (float) $usage->cacheReadTokens * ($this->cacheReadPricePerToken ?? $this->inputPricePerToken)
            + (float) $usage->cacheWriteTokens * ($this->cacheWritePricePerToken ?? $this->inputPricePerToken)
            + (float) $usage->outputTokens * $this->outputPricePerToken;
    }
}
