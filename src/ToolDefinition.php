<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A tool the model may call: name, human-readable description and JSON Schema of its parameters.
 */
final class ToolDefinition
{
    /**
     * @param array<array-key, mixed> $parameters JSON Schema of the arguments object. An empty array means
     *                                           "no parameters" and is sent as `{"type":"object","properties":{}}`.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters = [],
    ) {
        if (\preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Tool name must be 1-64 characters of letters, digits, underscores or dashes',
            );
        }
    }
}
