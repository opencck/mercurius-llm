<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A piece of the answer text.
 */
final class TextDelta implements StreamEvent
{
    public function __construct(public readonly string $text)
    {
    }
}
