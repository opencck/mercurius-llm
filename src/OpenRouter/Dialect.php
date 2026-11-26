<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

/**
 * Flavours of the Chat Completions request body. OpenRouter takes `max_tokens` and a `reasoning` object; the OpenAI API
 * wants `max_completion_tokens` and a `reasoning_effort` string, and its reasoning models refuse `temperature`. The
 * OpenAI-compatible address of Google (Gemini API, Vertex) takes thinking as `extra_body.google.thinking_config` and
 * wants the signature of a tool call back.
 *
 * @internal
 */
enum Dialect
{
    case OpenRouter;
    case OpenAI;
    case Gemini;
}
