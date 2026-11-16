<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

/**
 * Typed reads from decoded JSON. Provider responses are untrusted: every accessor returns a value of the expected
 * type or a neutral default, never `mixed`.
 *
 * @internal
 */
final class Json
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function object(array $data, string|int $key): array
    {
        return isset($data[$key]) && \is_array($data[$key]) ? $data[$key] : [];
    }

    /**
     * Array items that are themselves objects/arrays; other items are dropped.
     *
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function objects(array $data, string|int $key): array
    {
        return \array_values(self::keyedObjects($data, $key));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, array<array-key, mixed>> Items that are objects/arrays, keyed as in the source.
     */
    public static function keyedObjects(array $data, string|int $key): array
    {
        $source = self::object($data, $key);
        $result = [];
        foreach (\array_keys($source) as $index) {
            $result[$index] = self::object($source, $index);
            if ($result[$index] === [] && !\is_array($source[$index])) {
                unset($result[$index]);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string|int $key): array
    {
        $source = self::object($data, $key);
        $result = [];
        foreach (\array_keys($source) as $index) {
            $value = self::string($source, $index);
            if ($value !== null) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string|int $key): ?string
    {
        return isset($data[$key]) && \is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * Integers; floats are truncated, numeric strings are parsed.
     *
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string|int $key): ?int
    {
        $number = self::float($data, $key);

        return $number === null ? null : (int) $number;
    }

    /**
     * Numbers; numeric strings (as in OpenRouter prices) are parsed.
     *
     * @param array<array-key, mixed> $data
     */
    public static function float(array $data, string|int $key): ?float
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (\is_int($data[$key]) || \is_float($data[$key])) {
            return (float) $data[$key];
        }
        if (\is_string($data[$key]) && \is_numeric($data[$key])) {
            return (float) $data[$key];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function has(array $data, string|int $key): bool
    {
        return isset($data[$key]) && \is_array($data[$key]);
    }
}
