<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * Author of a message in a conversation.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
