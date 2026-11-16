<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

/**
 * Flavours of the Chat Completions request body. OpenRouter takes `max_tokens` and a `reasoning` object; the OpenAI API
 * wants `max_completion_tokens` and a `reasoning_effort` string, and its reasoning models refuse `temperature`.
 *
 * @internal
 */
enum Dialect
{
    case OpenRouter;
    case OpenAI;
}
