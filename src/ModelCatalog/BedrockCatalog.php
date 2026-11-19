<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Mercurius\Llm\LlmException;

/**
 * The Claude models of Amazon Bedrock (the Mantle endpoint), described by the OpenRouter catalog. Bedrock has no
 * models endpoint there, so the list is the reference's Claude models in Bedrock spelling: `anthropic/claude-opus-5.5`
 * is `anthropic.claude-opus-5-5`. Which of them an account may call is decided by Bedrock model access; a model the
 * account has no access to fails at the call and the fallback takes over.
 */
final class BedrockCatalog
{
    private const REFERENCE_PREFIX = 'anthropic/claude-';

    public function __construct(private readonly OpenRouterCatalog $reference)
    {
    }

    /**
     * @return list<ModelInfo> by the order of the reference
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $models = [];
        foreach ($this->reference->fetch($cancellation) as $ref) {
            // a variant (`:batch`, `:free`) is not a model of its own
            if (!\str_starts_with($ref->id, self::REFERENCE_PREFIX) || \str_contains($ref->id, ':')) {
                continue;
            }
            $models[] = new ModelInfo(
                'anthropic.' . \str_replace('.', '-', \substr($ref->id, \strlen('anthropic/'))),
                $ref->name,
                $ref->contextLength,
                $ref->inputPricePerToken,
                $ref->outputPricePerToken,
                $ref->inputModalities,
                $ref->outputModalities,
                $ref->supportedParameters,
                $ref->maxCompletionTokens,
                $ref->cacheReadPricePerToken,
                $ref->cacheWritePricePerToken,
                $ref->intelligenceIndex,
                $ref->codingIndex,
                $ref->agenticIndex,
                $ref->reasoningMandatory,
            );
        }

        return $models;
    }
}
