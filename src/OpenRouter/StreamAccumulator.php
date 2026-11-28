<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEvent;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolCallDelta;
use Mercurius\Llm\Usage;

/**
 * Assembles a streamed Chat Completions answer from decoded chunks.
 *
 * Text and reasoning are concatenated, tool call fragments are merged by their index, usage is taken from the chunk
 * that carries it (OpenRouter sends it last and repeats `finish_reason` there: the first reported reason wins, the
 * repetition is only accounting). A chunk with a top-level `error` aborts the stream.
 *
 * @internal
 */
final class StreamAccumulator
{
    private string $text = '';

    private string $reasoning = '';

    /** @var array<int, array{id: string, name: string, arguments: string, signature: ?string}> */
    private array $toolCalls = [];

    private ?FinishReason $finishReason = null;

    private ?Usage $usage = null;

    private string $model = '';

    private string $id = '';

    private int $size = 0;

    public function __construct(
        private readonly int $maxToolCalls = 128,
        private readonly int $maxAnswerBytes = 16 * 1024 * 1024,
    ) {
    }

    /**
     * Consumes one chunk and returns the events it produced.
     *
     * @param array<array-key, mixed> $chunk
     * @return list<StreamEvent>
     * @throws LlmException When the chunk carries an error or a limit is exceeded.
     */
    public function push(array $chunk): array
    {
        if (Json::has($chunk, 'error')) {
            throw ErrorClassifier::fromErrorObject(Json::object($chunk, 'error'));
        }
        $id = Json::string($chunk, 'id');
        if ($this->id === '' && $id !== null) {
            $this->id = $id;
        }
        $model = Json::string($chunk, 'model');
        if ($model !== null && $model !== '') {
            $this->model = $model;
        }
        if (Json::has($chunk, 'usage')) {
            $this->usage = PayloadMapper::usage(Json::object($chunk, 'usage'));
        }

        $events = [];
        foreach (Json::objects($chunk, 'choices') as $choice) {
            if ((Json::int($choice, 'index') ?? 0) !== 0) {
                continue; // only the first choice is supported (n = 1)
            }
            // ref: references/kimi-code/packages/core/src/llm/builtin/protocol/openai/decode.ts@a6ac07d:L316-L320 — Kimi
            //      may put usage inside the choice
            if ($this->usage === null && Json::has($choice, 'usage')) {
                $this->usage = PayloadMapper::usage(Json::object($choice, 'usage'));
            }
            $delta = Json::object($choice, 'delta');

            // Moonshot names the reasoning `reasoning_content`
            $reasoning = Json::string($delta, 'reasoning') ?? Json::string($delta, 'reasoning_content');
            if ($reasoning !== null && $reasoning !== '') {
                $this->grow(\strlen($reasoning));
                $this->reasoning .= $reasoning;
                $events[] = new ReasoningDelta($reasoning);
            }

            $content = Json::string($delta, 'content');
            if ($content !== null && $content !== '') {
                $this->grow(\strlen($content));
                $this->text .= $content;
                $events[] = new TextDelta($content);
            }

            foreach (Json::keyedObjects($delta, 'tool_calls') as $position => $call) {
                $events[] = $this->toolCall($call, \is_int($position) ? $position : 0);
            }

            $reason = Json::string($choice, 'finish_reason');
            if ($this->finishReason === null && $reason !== null && $reason !== '') {
                $this->finishReason = FinishReason::fromOpenAi($reason);
            }
        }

        return $events;
    }

    /**
     * Whether the model reported why it stopped.
     */
    public function finished(): bool
    {
        return $this->finishReason !== null;
    }

    public function response(): ChatResponse
    {
        $calls = $this->toolCalls;
        \ksort($calls);
        $toolCalls = [];
        foreach ($calls as $call) {
            $toolCalls[] = new ToolCall($call['id'], $call['name'], $call['arguments'], $call['signature']);
        }

        return new ChatResponse(
            $this->text,
            $toolCalls,
            $this->finishReason ?? FinishReason::Other,
            $this->usage ?? new Usage(),
            $this->model,
            $this->id,
            $this->reasoning,
        );
    }

    /**
     * @param array<array-key, mixed> $call
     * @throws LlmException
     */
    private function toolCall(array $call, int $position): ToolCallDelta
    {
        $index = Json::int($call, 'index') ?? $position;
        $function = Json::object($call, 'function');
        $id = Json::string($call, 'id');
        $id = $id === '' ? null : $id;
        $name = Json::string($function, 'name');
        $name = $name === '' ? null : $name;
        $fragment = Json::string($function, 'arguments') ?? '';

        $current = $this->toolCalls[$index] ?? null;
        if ($current === null) {
            if (\count($this->toolCalls) >= $this->maxToolCalls) {
                throw new LlmException(ErrorKind::Unknown, 'OpenRouter: too many tool calls in one answer');
            }
            $current = ['id' => '', 'name' => '', 'arguments' => '', 'signature' => null];
        }
        $this->grow(\strlen($fragment));
        // ref: references/hermes-agent/agent/chat_completion_helpers_tool_calls.py@e96221f:L33 — Kimi sends a fresh id
        //      with every fragment of the arguments: the first one names the call
        $this->toolCalls[$index] = [
            'id' => $current['id'] !== '' ? $current['id'] : ($id ?? ''),
            'name' => $name ?? $current['name'],
            'arguments' => $current['arguments'] . $fragment,
            'signature' => PayloadMapper::signature($call) ?? $current['signature'],
        ];

        return new ToolCallDelta($index, $id, $name, $fragment);
    }

    /**
     * @throws LlmException
     */
    private function grow(int $bytes): void
    {
        $this->size += $bytes;
        if ($this->size > $this->maxAnswerBytes) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: streamed answer exceeds the size limit');
        }
    }
}
