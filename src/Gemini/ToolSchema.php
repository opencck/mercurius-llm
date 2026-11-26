<?php

declare(strict_types=1);

namespace Mercurius\Llm\Gemini;

use Mercurius\Llm\OpenRouter\Json;

/**
 * Brings a JSON Schema of tool parameters to what Google's OpenAI-compatible address accepts: it answers 400 to a
 * `type` given as a list and to a `null` branch of a union, both common in schemas of MCP tools.
 *
 * ref: references/hermes-agent/tools/schema_sanitizer.py@e96221f:L280-L294 (_normalize_type_array) — one type stays
 *      a string with `nullable`, several become `anyOf`
 * ref: references/hermes-agent/tools/schema_sanitizer.py@e96221f:L205-L224 (strip_nullable_unions) — a union of one
 *      schema and null becomes that schema. The renaming of property keys and the rest of the sanitizer serve other
 *      providers and are not taken.
 *
 * @internal
 */
final class ToolSchema
{
    /** Keys whose value is a list of names or literals, not of schemas. */
    private const NOT_SCHEMAS = ['required' => true, 'enum' => true, 'examples' => true, 'default' => true, 'const' => true];

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    public static function clean(array $schema, int $depth = 0): array
    {
        if ($depth > 32) {
            return $schema;
        }
        $out = $schema;
        foreach (\array_keys($schema) as $key) {
            if (!\is_array($schema[$key]) || isset(self::NOT_SCHEMAS[$key])) {
                continue;
            }
            $value = Json::object($schema, $key);
            if ($key === 'type') {
                unset($out[$key]);
                self::types($value, $out);
            } else {
                $out[$key] = self::clean($value, $depth + 1);
            }
        }
        foreach (['anyOf', 'oneOf'] as $union) {
            $variants = $out[$union] ?? null;
            if (!\is_array($variants)) {
                continue;
            }
            $rest = \array_values(\array_filter($variants, static fn (mixed $v): bool => !(\is_array($v) && ($v['type'] ?? null) === 'null')));
            if (\count($rest) === 1 && \count($rest) !== \count($variants) && \is_array($rest[0])) {
                unset($out[$union]);
                /** @var array<array-key, mixed> $only */
                $only = $rest[0];

                return $only + $out + ['nullable' => true];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $types
     * @param array<array-key, mixed> $out
     */
    private static function types(array $types, array &$out): void
    {
        $named = \array_values(\array_filter($types, static fn (mixed $t): bool => \is_string($t) && $t !== 'null'));
        if ($named === []) {
            $out['type'] = \in_array('null', $types, true) ? 'null' : 'object';

            return;
        }
        if (\count($named) === 1) {
            $out['type'] = $named[0];
        } else {
            $out['anyOf'] = \array_map(static fn (string $t): array => ['type' => $t], $named);
        }
        if (\in_array('null', $types, true)) {
            $out['nullable'] = true;
        }
    }
}
