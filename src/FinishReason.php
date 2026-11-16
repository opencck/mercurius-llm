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
        return match ($reason) {
            'stop', 'end_turn', 'stop_sequence' => self::Stop,
            'length', 'max_tokens' => self::Length,
            'tool_calls', 'function_call', 'tool_use' => self::ToolCalls,
            'content_filter', 'refusal' => self::ContentFilter,
            'error' => self::Error,
            default => self::Other,
        };
    }
}
