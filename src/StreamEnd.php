<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * The last event of a stream: the fully assembled answer, including tool calls and usage.
 */
final class StreamEnd implements StreamEvent
{
    public function __construct(public readonly ChatResponse $response)
    {
    }
}
