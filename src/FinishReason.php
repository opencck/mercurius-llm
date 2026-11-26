<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * Why the model stopped generating, normalized across providers.
 */
enum FinishReason: string
{
    /** Natural end of the answer or a stop sequence. */
    case Stop = 'stop';
    /** The output token limit (max_tokens) or the context window was reached. */
    case Length = 'length';
    /** The model wants tools to be executed. */
    case ToolCalls = 'tool_calls';
    /** The output was blocked by a moderation filter. */
    case ContentFilter = 'content_filter';
    /** The provider reported an error while generating. */
    case Error = 'error';
    /** Anything else, including a missing reason. */
    case Other = 'other';

    /**
     * Maps an OpenAI-compatible `finish_reason` value.
     */
    public static function fromOpenAi(?string $reason): self
    {
        // ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L87-L90 (_FINISH_REASON_MAP) — addresses in
        //      front of Gemini answer in capitals (STOP, MAX_TOKENS) and with Gemini's own reasons of a block
        return match ($reason === null ? null : \strtolower($reason)) {
            'stop', 'end_turn', 'stop_sequence' => self::Stop,
            'length', 'max_tokens' => self::Length,
            'tool_calls', 'function_call', 'tool_use' => self::ToolCalls,
            'content_filter', 'refusal', 'safety', 'recitation', 'blocklist', 'prohibited_content', 'spii' => self::ContentFilter,
            'error' => self::Error,
            default => self::Other,
        };
    }
}
