<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * An immutable request to a chat model. Use the `with*()` methods to derive modified copies.
 */
final class ChatRequest
{
    /** @var list<Message> */
    public readonly array $messages;

    /** @var list<ToolDefinition> */
    public readonly array $tools;

    /** @var array<string, mixed> */
    public readonly array $extra;

    /**
     * @param list<Message> $messages
     * @param list<ToolDefinition> $tools
     * @param string|null $reasoningEffort Provider reasoning effort, e.g. "low", "medium", "high"; null leaves the
     *                                     model default.
     * @param array<string, mixed> $extra Provider-specific top-level payload fields. They override the fields built
     *                                    from this request (except the ones that control streaming).
     */
    public function __construct(
        public readonly string $model,
        array $messages,
        array $tools = [],
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?string $reasoningEffort = null,
        array $extra = [],
    ) {
        if ($model === '') {
            throw new \InvalidArgumentException('Model must not be empty');
        }
        if ($maxTokens !== null && $maxTokens < 1) {
            throw new \InvalidArgumentException('maxTokens must be positive');
        }
        if ($temperature !== null && ($temperature < 0.0 || $temperature > 2.0)) {
            throw new \InvalidArgumentException('temperature must be within [0, 2]');
        }
        $names = [];
        foreach ($tools as $tool) {
            if (isset($names[$tool->name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate tool name "%s"', $tool->name));
            }
            $names[$tool->name] = true;
        }
        $this->messages = $messages;
        $this->tools = $tools;
        $this->extra = $extra;
    }

    public function withModel(string $model): self
    {
        return new self($model, $this->messages, $this->tools, $this->maxTokens, $this->temperature, $this->reasoningEffort, $this->extra);
    }

    /**
     * @param list<Message> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->model, $messages, $this->tools, $this->maxTokens, $this->temperature, $this->reasoningEffort, $this->extra);
    }

    /**
     * Appends messages to the conversation.
     */
    public function withAddedMessages(Message ...$messages): self
    {
        return $this->withMessages([...$this->messages, ...\array_values($messages)]);
    }

    /**
     * @param list<ToolDefinition> $tools
     */
    public function withTools(array $tools): self
    {
        return new self($this->model, $this->messages, $tools, $this->maxTokens, $this->temperature, $this->reasoningEffort, $this->extra);
    }

    public function withMaxTokens(?int $maxTokens): self
    {
        return new self($this->model, $this->messages, $this->tools, $maxTokens, $this->temperature, $this->reasoningEffort, $this->extra);
    }

    public function withTemperature(?float $temperature): self
    {
        return new self($this->model, $this->messages, $this->tools, $this->maxTokens, $temperature, $this->reasoningEffort, $this->extra);
    }

    public function withReasoningEffort(?string $reasoningEffort): self
    {
        return new self($this->model, $this->messages, $this->tools, $this->maxTokens, $this->temperature, $reasoningEffort, $this->extra);
    }

    /**
     * @param array<string, mixed> $extra Replaces the whole extra array.
     */
    public function withExtra(array $extra): self
    {
        return new self($this->model, $this->messages, $this->tools, $this->maxTokens, $this->temperature, $this->reasoningEffort, $extra);
    }
}
