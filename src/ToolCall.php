<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A tool invocation requested by the model.
 *
 * Arguments are kept as the raw JSON string the model produced: it is untrusted input and may be malformed,
 * so decoding is explicit ({@see self::arguments()}) and the original can be sent back to the provider verbatim.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $argumentsJson = '{}',
    ) {
    }

    /**
     * Decodes the arguments into an associative array.
     *
     * An empty string is treated as "no arguments".
     *
     * @return array<array-key, mixed>
     * @throws \JsonException When the model produced invalid JSON.
     * @throws \UnexpectedValueException When the JSON is valid but not an object or array.
     */
    public function arguments(): array
    {
        if (\trim($this->argumentsJson) === '') {
            return [];
        }

        $decoded = \json_decode($this->argumentsJson, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException(\sprintf('Arguments of tool call "%s" are not a JSON object', $this->name));
        }

        return $decoded;
    }
}
