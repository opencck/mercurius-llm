<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Mercurius\Llm\LlmException;

/**
 * The Gemini models of Google Cloud (Vertex), described by the OpenRouter catalog. The OpenAI-compatible address of
 * Vertex has no list of models, so the list is the reference's Gemini models that answer with text; Vertex spells
 * them the same way, `google/gemini-…`. Which of them a project may call is decided by Google Cloud; a model the
 * project has no access to fails at the call and the fallback takes over.
 *
 * ref: references/hermes-agent/plugins/model-providers/vertex/__init__.py@e96221f:L26-L31 — the address has no route
 *      for models; hermes ships a fixed list, here the list follows the reference
 */
final class VertexCatalog
{
    private const REFERENCE_PREFIX = 'google/gemini-';

    private const NOT_CHAT = '/(embedding|image|tts|audio|live|transcribe|robotics|computer-use)/i';

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
            // a variant (`:free`, `:batch`) is not a model of its own
            if (!\str_starts_with($ref->id, self::REFERENCE_PREFIX) || \str_contains($ref->id, ':')
                || \preg_match(self::NOT_CHAT, $ref->id) === 1 || $ref->outputModalities !== ['text']) {
                continue;
            }
            $models[] = $ref;
        }

        return $models;
    }
}
