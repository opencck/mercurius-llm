<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A complete, normalized model answer.
 */
final class ChatResponse
{
    /** @var list<ToolCall> */
    public readonly array $toolCalls;

    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public readonly string $text,
        array $toolCalls,
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        /** Model that actually served the request (may differ from the requested one, e.g. with routers). */
        public readonly string $model,
        /** Provider-side id of the generation, useful for support requests and cost lookups. */
        public readonly string $id,
        /** Reasoning text if the provider returned it; empty otherwise. */
        public readonly string $reasoning = '',
        /** Provider-side id of the HTTP request (not of the generation), for support; null when there is none. */
        public readonly ?string $requestId = null,
        /** Why the model refused, as the provider classed it (e.g. "cyber"); null when it did not refuse or did not say. */
        public readonly ?string $refusalCategory = null,
        /** The provider's explanation of a refusal; null when there is none. */
        public readonly ?string $refusalExplanation = null,
    ) {
        $this->toolCalls = $toolCalls;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * The answer as an assistant message, ready to be appended to the conversation.
     */
    public function toMessage(): Message
    {
        return Message::assistant($this->text, $this->toolCalls);
    }
}
