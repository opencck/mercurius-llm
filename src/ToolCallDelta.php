<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A fragment of a tool call. Fragments with the same index belong to the same call; the id and name usually come
 * with the first fragment only. The assembled calls are in the final {@see StreamEnd}.
 */
final class ToolCallDelta implements StreamEvent
{
    public function __construct(
        public readonly int $index,
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly string $argumentsFragment,
    ) {
    }
}
