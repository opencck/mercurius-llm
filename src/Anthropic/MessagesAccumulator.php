<?php

declare(strict_types=1);

namespace Mercurius\Llm\Anthropic;

use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\Json;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEvent;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolCallDelta;
use Mercurius\Llm\Usage;

/**
 * Folds the events of a Messages API stream (`message_start`, `content_block_*`, `message_delta`, `message_stop`)
 * into deltas and the final answer. Tool calls are numbered from zero in the order they start, whatever the index of
 * their content block.
 *
 * @internal
 */
final class MessagesAccumulator
{
    private string $id = '';
    private string $model = '';
    private string $text = '';
    private string $reasoning = '';
    private Usage $usage;
    private ?string $stopReason = null;
    private bool $finished = false;

    /** @var array<int, int> Content block index → tool call number. */
    private array $toolOfBlock = [];

    /** @var array<int, array{id: string, name: string, arguments: string}> Tool call number → the call so far. */
    private array $tools = [];

    public function __construct()
    {
        $this->usage = new Usage();
    }

    /**
     * @param array<array-key, mixed> $event
     * @return list<StreamEvent>
     * @throws LlmException On an `error` event.
     */
    public function push(array $event): array
    {
        switch (Json::string($event, 'type')) {
            case 'message_start':
                $message = Json::object($event, 'message');
                $this->id = Json::string($message, 'id') ?? '';
                $this->model = Json::string($message, 'model') ?? '';
                $this->usage = MessagesMapper::usage(Json::object($message, 'usage'));

                return [];
            case 'content_block_start':
                $block = Json::object($event, 'content_block');
                $type = Json::string($block, 'type');
                if ($type === 'tool_use') {
                    $number = \count($this->tools);
                    $this->toolOfBlock[Json::int($event, 'index') ?? $number] = $number;
                    $this->tools[] = ['id' => Json::string($block, 'id') ?? '', 'name' => Json::string($block, 'name') ?? '', 'arguments' => ''];

                    return [new ToolCallDelta($number, $this->tools[$number]['id'], $this->tools[$number]['name'], '')];
                }
                $start = $type === 'text' ? (Json::string($block, 'text') ?? '') : '';

                return $start === '' ? [] : $this->text($start);
            case 'content_block_delta':
                $delta = Json::object($event, 'delta');

                return match (Json::string($delta, 'type')) {
                    'text_delta' => $this->text(Json::string($delta, 'text') ?? ''),
                    'thinking_delta' => $this->reasoning(Json::string($delta, 'thinking') ?? ''),
                    'input_json_delta' => $this->arguments(Json::int($event, 'index') ?? -1, Json::string($delta, 'partial_json') ?? ''),
                    default => [], // signature_delta, citations: not kept
                };
            case 'message_delta':
                $this->stopReason = Json::string(Json::object($event, 'delta'), 'stop_reason') ?? $this->stopReason;
                // The counts here are running totals: they replace the ones from message_start.
                $this->usage = MessagesMapper::usage(Json::object($event, 'usage'), $this->usage);

                return [];
            case 'message_stop':
                $this->finished = true;

                return [];
            case 'error':
                throw MessagesMapper::error(Json::object($event, 'error'));
            default:
                return []; // ping, content_block_stop and events of later API versions
        }
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function response(): ChatResponse
    {
        $calls = \array_map(
            static fn(array $tool): ToolCall => new ToolCall($tool['id'], $tool['name'], $tool['arguments'] === '' ? '{}' : $tool['arguments']),
            \array_values($this->tools),
        );

        return new ChatResponse(
            $this->text,
            $calls,
            $this->stopReason === null ? FinishReason::Other : MessagesMapper::finishReason($this->stopReason),
            $this->usage,
            $this->model,
            $this->id,
            $this->reasoning,
        );
    }

    /** @return list<StreamEvent> */
    private function text(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $this->text .= $text;

        return [new TextDelta($text)];
    }

    /** @return list<StreamEvent> */
    private function reasoning(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $this->reasoning .= $text;

        return [new ReasoningDelta($text)];
    }

    /** @return list<StreamEvent> */
    private function arguments(int $block, string $fragment): array
    {
        $number = $this->toolOfBlock[$block] ?? null;
        if ($number === null || $fragment === '') {
            return [];
        }
        $this->tools[$number]['arguments'] .= $fragment;

        return [new ToolCallDelta($number, null, null, $fragment)];
    }
}
