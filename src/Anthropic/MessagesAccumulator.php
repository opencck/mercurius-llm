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
    private ?string $refusalCategory = null;
    private ?string $refusalExplanation = null;
    private bool $finished = false;

    /** @var array<int, int> Content block index → tool call number. */
    private array $toolOfBlock = [];

    /** @var array<int, array{id: string, name: string, arguments: string}> Tool call number → the call so far. */
    private array $tools = [];

    /**
     * @param ?string $requestId The id of the HTTP request, from its header.
     * @param string $provider The name the errors are worded with.
     */
    public function __construct(
        private readonly ?string $requestId = null,
        private readonly string $provider = 'Anthropic',
    ) {
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
                $delta = Json::object($event, 'delta');
                $this->stopReason = Json::string($delta, 'stop_reason') ?? $this->stopReason;
                // ref: references/hermes-agent/agent/anthropic_adapter.py@e96221fa:L832-L858 — the reason of a
                //      refusal comes in the delta of the message, not in the final message of a stream.
                $refusal = Json::object($delta, 'stop_details');
                $this->refusalCategory = Json::string($refusal, 'category') ?? $this->refusalCategory;
                $this->refusalExplanation = Json::string($refusal, 'explanation') ?? $this->refusalExplanation;
                // The counts here are running totals: they replace the ones from message_start.
                $this->usage = MessagesMapper::usage(Json::object($event, 'usage'), $this->usage);

                return [];
            case 'message_stop':
                $this->finished = true;

                return [];
            case 'error':
                throw MessagesMapper::error(Json::object($event, 'error'), $this->provider);
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
        $finish = $this->stopReason === null ? FinishReason::Other : MessagesMapper::finishReason($this->stopReason);
        $calls = [];
        foreach ($this->tools as $tool) {
            $arguments = \trim($tool['arguments']) === '' ? '{}' : $tool['arguments'];
            // Streamed arguments are not checked by the API: an answer cut at the limit ends in the middle of
            // them, and the model can write JSON that does not parse. Such a call is never run — the answer ends
            // as one cut by length, and the caller asks again.
            if (!\is_array(\json_decode($arguments, true))) {
                // A refusal stays a refusal: its unfinished call is dropped below with the rest.
                $finish = $this->stopReason === 'refusal' ? $finish : FinishReason::Length;
                continue;
            }
            $calls[] = new ToolCall($tool['id'], $tool['name'], $arguments);
        }

        return new ChatResponse(
            $this->text,
            // A call the model was writing when it refused is not a call to run.
            $this->stopReason === 'refusal' ? [] : $calls,
            $finish,
            $this->usage,
            $this->model,
            $this->id,
            $this->reasoning,
            $this->requestId,
            $this->refusalCategory,
            $this->refusalExplanation,
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
