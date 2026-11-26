<?php

declare(strict_types=1);

namespace Mercurius\Llm\Gemini;

/**
 * What a Gemini model takes in a Chat Completions request of Google's OpenAI-compatible address: how its thinking is
 * steered, whether it reads `temperature`, how large the answer limit must be.
 *
 * spec: packages/llm/BRIEF.md#gemini-и-vertex
 *
 * @internal
 */
final class GeminiModel
{
    /** Published limit of the answer shared by the current Gemini text models. */
    public const MAX_OUTPUT_TOKENS = 65535;

    /**
     * Stands in for the signature of a tool call another provider made: without it Gemini 3 and later refuse the
     * replayed history with 400.
     *
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L81-L83 (_SKIP_SIGNATURE) — the same value;
     *      hermes sends it through the native API only, here it goes in `extra_content` of the compatible address
     */
    public const SKIP_SIGNATURE = 'skip_thought_signature_validator';

    private const HIGH = ['high', 'xhigh', 'max'];

    /** Models that take the `xhigh` level; the others answer 400 to it. */
    private const XHIGH_MODELS = ['gemini-4-hydrogen'];

    /** The model id without the prefix of Vertex or of an aggregator: `google/gemini-3-flash` is `gemini-3-flash`. */
    public static function bare(string $model): string
    {
        $name = \strtolower(\trim($model));
        foreach (['google/', 'gemini/', 'models/'] as $prefix) {
            if (\str_starts_with($name, $prefix) && \strlen($name) > \strlen($prefix)) {
                return \substr($name, \strlen($prefix));
            }
        }

        return $name;
    }

    /**
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L120-L124 (_gemini_version)
     *
     * @return array{int, int} major and minor; [0, 0] for a model that is not Gemini or carries no version
     */
    public static function version(string $model): array
    {
        return \preg_match('/^gemini-(\d+)(?:\.(\d+))?/', self::bare($model), $m) === 1
            ? [(int) $m[1], (int) ($m[2] ?? 0)]
            : [0, 0];
    }

    /**
     * Gemini 3 and later steer thinking with a level; so do the `-latest` aliases, which follow the newest release.
     *
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L127-L133 (gemini_uses_thinking_level)
     */
    public static function usesThinkingLevel(string $model): bool
    {
        $bare = self::bare($model);

        return (\str_starts_with($bare, 'gemini-') && \str_ends_with($bare, '-latest')) || self::version($model) >= [3, 0];
    }

    /**
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L136-L139 (gemini_sampling_deprecated) —
     *      Gemini 3.6 and later ignore `temperature`
     */
    public static function ignoresTemperature(string $model): bool
    {
        return self::version($model) >= [3, 6];
    }

    /**
     * The `thinking_config` of the request for an effort, or null when nothing is to be said: the model is not Gemini
     * (Gemma on the same key answers 400 to the field), no effort was asked, or the family has no level to name.
     *
     * Thought summaries are never asked for: the thinking itself is steered, its text is not requested.
     *
     * ref: references/hermes-agent/agent/transports/chat_completions.py@e96221f:L171-L224 (_build_gemini_thinking_config)
     *      — levels by family; hermes asks for thought summaries, here they stay off
     * ref: references/hermes-agent/plugins/model-providers/gemini/__init__.py@e96221f:L18-L31 (_with_top_thinking_level)
     *      — `xhigh` only for the models that take it
     *
     * @return array<string, mixed>|null
     */
    public static function thinking(string $model, ?string $effort): ?array
    {
        $bare = self::bare($model);
        if ($effort === null) {
            return null;
        }
        if (!\str_starts_with($bare, 'gemini')) {
            return null;
        }
        $effort = \strtolower($effort);
        $off = $effort === 'none';
        $config = ['include_thoughts' => false];
        if (\str_starts_with($bare, 'gemini-2.5-')) {
            // 2.5 takes a budget: 0 turns thinking off, and no budget is guessed from a coarse effort
            return $off ? $config + ['thinking_budget' => 0] : null;
        }
        if (!self::usesThinkingLevel($model)) {
            return null;
        }
        if ($off) {
            // there is no "off" level: the lowest one every family takes
            return $config + ['thinking_level' => 'low'];
        }
        $high = \in_array($effort, self::HIGH, true);
        $level = match (true) {
            $high && $effort !== 'high' && self::startsWithAny($bare, self::XHIGH_MODELS) => 'xhigh',
            $high => 'high',
            // Gemini 3 Pro takes low and high only
            self::version($model)[0] === 3 && \str_contains($bare, 'pro') => 'low',
            $effort === 'minimal' || $effort === 'low' => 'low',
            default => 'medium',
        };

        return $config + ['thinking_level' => $level];
    }

    /**
     * Thinking is billed against the answer limit: with thinking asked for, a small limit would be spent on thoughts
     * and the answer would come back empty, so the limit is raised to the published one.
     *
     * ref: references/hermes-agent/agent/gemini_native_adapter.py@e96221f:L511-L535
     *      (_thinking_requests_output_headroom, _effective_gemini_max_output_tokens) — a call with thinking turned off
     *      keeps its small limit
     */
    public static function maxTokens(string $model, ?string $effort, int $requested): int
    {
        $bare = self::bare($model);
        $thinks = $effort !== null && \strtolower($effort) !== 'none'
            && (\str_starts_with($bare, 'gemini-2.5-') || self::usesThinkingLevel($model));

        return $thinks ? \max($requested, self::MAX_OUTPUT_TOKENS) : $requested;
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (\str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
