<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * An event of a streamed answer: {@see TextDelta}, {@see ReasoningDelta}, {@see ToolCallDelta} or the final
 * {@see StreamEnd}.
 */
interface StreamEvent
{
}
