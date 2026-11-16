<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A piece of the model's reasoning text, for models and providers that expose it.
 */
final class ReasoningDelta implements StreamEvent
{
    public function __construct(public readonly string $text)
    {
    }
}
