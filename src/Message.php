<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * One message of a conversation in the provider-independent format.
 *
 * Adapters convert it to the provider's wire format. Assistant messages may carry tool calls; tool messages carry the
 * id of the call they answer.
 */
final class Message
{
    /** @var list<ToolCall> */
    public readonly array $toolCalls;

    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public readonly Role $role,
        public readonly string $content = '',
        array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        /** a cache breakpoint at the end of this message, for providers that cache only where asked */
        public readonly bool $cacheMark = false,
    ) {
        if ($toolCalls !== [] && $role !== Role::Assistant) {
            throw new \InvalidArgumentException('Only assistant messages may contain tool calls');
        }
        if ($role === Role::Tool && ($toolCallId === null || $toolCallId === '')) {
            throw new \InvalidArgumentException('A tool message requires a tool call id');
        }
        if ($role !== Role::Tool && $toolCallId !== null) {
            throw new \InvalidArgumentException('Only tool messages may reference a tool call id');
        }
        $this->toolCalls = $toolCalls;
    }

    /**
     * The same message with a cache breakpoint at its end. Providers that cache on their own ignore it.
     */
    public function cached(): self
    {
        return new self($this->role, $this->content, $this->toolCalls, $this->toolCallId, true);
    }

    public static function system(string $content): self
    {
        return new self(Role::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    public static function assistant(string $content, array $toolCalls = []): self
    {
        return new self(Role::Assistant, $content, $toolCalls);
    }

    /**
     * The result of executing a tool call, sent back to the model.
     */
    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self(Role::Tool, $content, [], $toolCallId);
    }
}
