<?php

declare(strict_types=1);

namespace Mercurius\Llm\Moonshot;

use Mercurius\Llm\OpenRouter\Json;

/**
 * Brings the parameters of a tool to the stricter JSON Schema Moonshot accepts; otherwise the request fails with 400
 * "tools.function.parameters is not a valid moonshot flavored json schema".
 *
 * First the local references (`#/$defs/…`, `#/definitions/…`) are resolved, as the official client does; then every
 * schema gets a type, a node with `anyOf` keeps the type on its branches and loses its `null` branches, `nullable` goes,
 * enumerations of scalars lose `null` and the empty string, and every object gets a `required` list of its own
 * properties.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/kimi-code/packages/core/src/llm/builtin/provider/kimi/schema.ts@a6ac07d:L3-L14 (derefJsonSchema),
 *      L129-L170 (resolveNode), L263-L331 (normalizeProperty, inferTypeFromStructure) — references resolved, a type
 *      inferred from the enum or the structure
 * ref: references/hermes-agent/agent/moonshot_schema.py@e96221f:L35-L143 (_repair_schema, _ensure_required_array,
 *      _fill_missing_type) — the anyOf, nullable, enum and required repairs
 */
final class ToolSchema
{
    private const MAX_DEPTH = 32;

    private const MAP_KEYS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
    private const LIST_KEYS = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];
    private const NODE_KEYS = ['items', 'contains', 'not', 'additionalProperties', 'propertyNames', 'if', 'then', 'else', 'unevaluatedProperties', 'unevaluatedItems'];

    /** A node with one of these constrains its instance instead of naming a type of its own. */
    private const NO_TYPE_KEYS = ['$ref', 'anyOf', 'oneOf', 'allOf', 'not', 'if', 'then', 'else'];

    private const SCALARS = ['string', 'integer', 'number', 'boolean'];

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    public static function clean(array $schema): array
    {
        $node = self::resolve($schema, $schema, [], 0);
        foreach (['$defs', 'definitions'] as $bucket) {
            if (!self::refersTo($node, $bucket, 0)) {
                unset($node[$bucket]);
            }
        }
        $node = self::repair($node, 0);
        $node['type'] = 'object';
        if (!\is_array($node['properties'] ?? null)) {
            $node['properties'] = [];
        }

        return self::required($node);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $root
     * @param array<string, true> $seen references being resolved, against cycles
     * @return array<array-key, mixed>
     */
    private static function resolve(array $node, array $root, array $seen, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }
        $ref = Json::string($node, '$ref');
        if ($ref !== null && \str_starts_with($ref, '#/') && !isset($seen[$ref])) {
            $target = self::pointer($root, $ref);
            if ($target === null) {
                return $node;
            }
            $merged = self::resolve($target, $root, $seen + [$ref => true], $depth + 1);
            unset($node['$ref']);
            // the keys next to the reference win over the target, as in the official client
            return \array_replace($merged, self::resolve($node, $root, $seen, $depth + 1));
        }
        foreach (\array_keys($node) as $key) {
            if (\is_array($node[$key])) {
                $node[$key] = self::resolve(Json::object($node, $key), $root, $seen, $depth + 1);
            }
        }

        return $node;
    }

    /**
     * The schema a local pointer names; null when it names nothing or not an object.
     *
     * @param array<array-key, mixed> $root
     * @return array<array-key, mixed>|null
     */
    private static function pointer(array $root, string $ref): ?array
    {
        $current = $root;
        foreach (\explode('/', \substr($ref, 2)) as $part) {
            $part = \str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!\array_key_exists($part, $current) || !\is_array($current[$part])) {
                return null;
            }
            $current = Json::object($current, $part);
        }

        return \array_is_list($current) && $current !== [] ? null : $current;
    }

    /** @param array<array-key, mixed> $node */
    private static function refersTo(array $node, string $bucket, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        $ref = Json::string($node, '$ref');
        if ($ref !== null && \str_starts_with($ref, '#/' . $bucket . '/')) {
            return true;
        }
        foreach (\array_keys($node) as $key) {
            if ($key !== $bucket && \is_array($node[$key]) && self::refersTo(Json::object($node, $key), $bucket, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function repair(array $node, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }
        foreach (\array_keys($node) as $key) {
            if (!\is_array($node[$key])) {
                continue;
            }
            $value = Json::object($node, $key);
            if (\in_array($key, self::MAP_KEYS, true) || (\in_array($key, self::LIST_KEYS, true) && \array_is_list($value))) {
                foreach (\array_keys($value) as $name) {
                    if (\is_array($value[$name])) {
                        $value[$name] = self::repair(Json::object($value, $name), $depth + 1);
                    }
                }
                $node[$key] = $value;
            } elseif (\in_array($key, self::NODE_KEYS, true)) {
                $node[$key] = self::repair($value, $depth + 1);
            }
        }

        if (\is_array($node['anyOf'] ?? null)) {
            unset($node['type']);
            $branches = Json::object($node, 'anyOf');
            $kept = [];
            foreach (\array_keys($branches) as $i) {
                $branch = Json::object($branches, $i);
                if ($branch !== [] && ($branch['type'] ?? null) !== 'null') {
                    $kept[] = $branch;
                }
            }
            if ($kept === [] || \count($kept) === \count($branches)) {
                return $node;
            }
            if (\count($kept) > 1) {
                $node['anyOf'] = $kept;

                return $node;
            }
            unset($node['anyOf']);
            $node = \array_replace($node, $kept[0]);
        }
        unset($node['nullable']);

        $node = self::typed($node);
        $type = Json::string($node, 'type');
        if (\is_array($node['enum'] ?? null) && \in_array($type, self::SCALARS, true)) {
            $enum = \array_values(\array_filter(Json::object($node, 'enum'), static fn(mixed $v): bool => $v !== null && $v !== ''));
            if ($enum === []) {
                unset($node['enum']);
            } else {
                $node['enum'] = $enum;
            }
        }

        return $type === 'object' ? self::required($node) : $node;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function typed(array $node): array
    {
        if (\is_array($node['type'] ?? null)) {
            $concrete = \array_values(\array_filter(Json::strings($node, 'type'), static fn(string $t): bool => $t !== '' && $t !== 'null'));
            $node['type'] = $concrete[0] ?? 'string';

            return $node;
        }
        if ((Json::string($node, 'type') ?? '') !== '') {
            return $node;
        }
        foreach (self::NO_TYPE_KEYS as $key) {
            if (\array_key_exists($key, $node)) {
                return $node;
            }
        }
        $inferred = match (true) {
            isset($node['properties']) || isset($node['required']) || isset($node['additionalProperties']) => 'object',
            isset($node['items']) || isset($node['prefixItems']) => 'array',
            \is_array($node['enum'] ?? null) && $node['enum'] !== [] => self::typeOfValues(Json::object($node, 'enum')),
            \array_key_exists('const', $node) => self::typeOfValues([$node['const']]),
            isset($node['minimum']) || isset($node['maximum']) || isset($node['multipleOf']) => 'number',
            default => 'string',
        };

        return ['type' => $inferred] + $node;
    }

    /** @param array<array-key, mixed> $values */
    private static function typeOfValues(array $values): string
    {
        foreach (\array_keys($values) as $key) {
            /** @var mixed $value */
            $value = $values[$key];
            $type = match (true) {
                \is_bool($value) => 'boolean',
                \is_int($value) => 'integer',
                \is_float($value) => 'number',
                \is_array($value) => \array_is_list($value) ? 'array' : 'object',
                \is_string($value) && $value !== '' => 'string',
                default => null,
            };
            if ($type !== null) {
                return $type;
            }
        }

        return 'string';
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function required(array $node): array
    {
        $properties = Json::object($node, 'properties');
        $node['required'] = \array_values(\array_filter(Json::strings($node, 'required'), static fn(string $name): bool => \array_key_exists($name, $properties)));

        return $node;
    }
}
