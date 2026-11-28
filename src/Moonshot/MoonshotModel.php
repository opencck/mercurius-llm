<?php

declare(strict_types=1);

namespace Mercurius\Llm\Moonshot;

/**
 * What a Kimi model takes in a request, by its id: the effort of thinking and the ceiling of the answer.
 *
 * K3, K3.1 and K2.8 Preview (`kimi-for-coding`) think always and take the top-level `reasoning_effort` low, high or max;
 * they refuse the `thinking` parameter. Turning thinking off is not possible: K3.1 answers 400, the other models of the
 * subscription are quietly served by K2.8 without thinking — so "none" is not sent, and the model thinks at its own
 * default. K2.7 Code Highspeed has no adjustable effort. The K2 models of the platform keep earlier reasoning only with
 * `thinking.keep = "all"`.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/hermes-agent/agent/reasoning_effort.py@e96221f:L53-L59 (KIMI_K3_EFFORTS, KIMI_K3_OVERRIDES) — medium
 *      rounds up to high, xhigh to max
 * ref: references/hermes-agent/plugins/model-providers/kimi-coding/__init__.py@e96221f:L54-L65
 *      (KimiProfile.build_api_kwargs_extras) — thinking or an effort, never both; keep "all" for K2
 * ref: docs/kimi-code-models.md:L93-L110 (fetched 2026-11-28) — the mapping of efforts and the routing of "off"
 * ref: references/kimi-code/packages/core/src/llm/builtin/provider/kimi/protocol.ts@a6ac07d:L77-L88
 *      (kimiOpenAIThinkingParams) — the official client sends the effort inside `thinking`; the documentation of both
 *      addresses names the top-level field, which is taken here
 */
final class MoonshotModel
{
    /** Thinking is billed against the ceiling of the answer: a small one leaves an empty answer. */
    public const MAX_OUTPUT_TOKENS = 32_000;

    private const EFFORTS = [
        'minimal' => 'low',
        'low' => 'low',
        'medium' => 'high',
        'high' => 'high',
        'xhigh' => 'max',
        'max' => 'max',
    ];

    /**
     * The fields of thinking for this model and effort.
     *
     * @return array<string, mixed>
     */
    public static function thinking(string $model, ?string $effort): array
    {
        $id = self::bare($model);
        if (\str_starts_with($id, 'kimi-k2')) {
            return ['thinking' => ['type' => 'enabled', 'keep' => 'all']];
        }
        if ($id === 'kimi-for-coding-highspeed' || $effort === null) {
            return [];
        }
        $level = self::EFFORTS[$effort] ?? null;

        return $level === null ? [] : ['reasoning_effort' => $level];
    }

    public static function maxTokens(int $requested): int
    {
        return \max($requested, self::MAX_OUTPUT_TOKENS);
    }

    private static function bare(string $model): string
    {
        $model = \strtolower(\trim($model));
        $slash = \strrpos($model, '/');

        return $slash === false ? $model : \substr($model, $slash + 1);
    }
}
