<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenAI;

use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\Json;
use Mercurius\Llm\ReasoningDelta;
use Mercurius\Llm\StreamEvent;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolCallDelta;
use Mercurius\Llm\Usage;

/**
 * Folds the events of a Responses API stream into deltas and the final answer. Text, reasoning and tool call
 * arguments come as deltas; `response.completed` or `response.incomplete` carries the status and the usage, and ends
 * the answer. Tool calls are numbered from zero in the order they start.
 *
 * ref: references/hermes-agent/agent/codex_runtime.py@e96221fa:L847-L848, L862-L880, L1081-L1101 — the delta
 *      event types, a `function_call` item opening a call, an `error` frame with its fields at the top or nested
 *      under `error`, and the terminal events carrying the response.
 *
 * @internal
 */
final class ResponsesAccumulator
{
    private string $id = '';
    private string $model = '';
    private string $text = '';
    private string $reasoning = '';

    /** @var array<array-key, mixed>|null the response object of the terminal event */
    private ?array $final = null;

    /** @var array<int, int> output index → tool call number */
    private array $toolOfItem = [];

    /** @var array<int, array{id: string, name: string, arguments: string}> */
    private array $tools = [];

    /**
     * @param array<array-key, mixed> $event
     * @return list<StreamEvent>
     * @throws LlmException On a failed response or an `error` event.
     */
    public function push(array $event): array
    {
        $type = Json::string($event, 'type') ?? '';
        switch ($type) {
            case 'response.created':
            case 'response.in_progress':
                $response = Json::object($event, 'response');
                $this->id = Json::string($response, 'id') ?? $this->id;
                $this->model = Json::string($response, 'model') ?? $this->model;

                return [];
            case 'response.output_text.delta':
                return $this->text(Json::string($event, 'delta') ?? '');
            case 'response.reasoning_summary_text.delta':
            case 'response.reasoning_text.delta':
                return $this->reasoning(Json::string($event, 'delta') ?? '');
            case 'response.output_item.added':
                $item = Json::object($event, 'item');
                if (Json::string($item, 'type') !== 'function_call') {
                    return [];
                }
                $number = \count($this->tools);
                $this->toolOfItem[Json::int($event, 'output_index') ?? $number] = $number;
                $this->tools[$number] = [
                    'id' => Json::string($item, 'call_id') ?? Json::string($item, 'id') ?? '',
                    'name' => Json::string($item, 'name') ?? '',
                    'arguments' => Json::string($item, 'arguments') ?? '',
                ];

                return [new ToolCallDelta($number, $this->tools[$number]['id'], $this->tools[$number]['name'], $this->tools[$number]['arguments'])];
            case 'response.function_call_arguments.delta':
                $number = $this->toolOfItem[Json::int($event, 'output_index') ?? -1] ?? null;
                $fragment = Json::string($event, 'delta') ?? '';
                if ($number === null || $fragment === '') {
                    return [];
                }
                $this->tools[$number]['arguments'] .= $fragment;

                return [new ToolCallDelta($number, null, null, $fragment)];
            case 'response.completed':
            case 'response.incomplete':
            case 'response.failed':
                $response = Json::object($event, 'response');
                if ($type === 'response.failed') {
                    throw ResponsesMapper::error(Json::object($response, 'error'));
                }
                $this->final = $response;

                return [];
            case 'error':
                $nested = Json::object($event, 'error');
                throw ResponsesMapper::error([
                    'code' => Json::string($event, 'code') ?? Json::string($nested, 'code'),
                    'message' => Json::string($event, 'message') ?? Json::string($nested, 'message'),
                ]);
            default:
                return [];
        }
    }

    public function finished(): bool
    {
        return $this->final !== null;
    }

    public function response(): ChatResponse
    {
        $final = $this->final ?? [];
        $calls = \array_map(
            static fn(array $tool): ToolCall => new ToolCall($tool['id'], $tool['name'], $tool['arguments'] === '' ? '{}' : $tool['arguments']),
            \array_values($this->tools),
        );

        return new ChatResponse(
            $this->text,
            $calls,
            ResponsesMapper::finishReason($final, $calls !== []),
            Json::has($final, 'usage') ? ResponsesMapper::usage(Json::object($final, 'usage')) : new Usage(),
            Json::string($final, 'model') ?? $this->model,
            Json::string($final, 'id') ?? $this->id,
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
}
