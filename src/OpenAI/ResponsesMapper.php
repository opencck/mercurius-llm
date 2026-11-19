<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenAI;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\OpenRouter\ErrorClassifier;
use Mercurius\Llm\OpenRouter\Json;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;

/**
 * Maps the provider-independent request and answer to the OpenAI Responses API (`POST /v1/responses`) and back.
 *
 * ref: references/hermes-agent/agent/codex_responses_adapter.py@e96221fa:L440-L451 (_responses_tools),
 *      L629-L645 (tool message → function_call_output), L1053-L1072 (_preflight_codex_api_kwargs) — function tools
 *      flat with `strict: false`, tool results as `function_call_output` items, `store: false`. The replay of
 *      encrypted reasoning items hermes keeps between turns is left out: the history carries no reasoning.
 *
 * @internal
 */
final class ResponsesMapper
{
    /**
     * @return array<string, mixed>
     * @throws LlmException When a message carries a video: the Responses API takes pictures only.
     */
    public static function toPayload(ChatRequest $request, bool $stream): array
    {
        $instructions = [];
        $input = [];
        foreach ($request->messages as $message) {
            switch ($message->role) {
                case Role::System:
                    $instructions[] = $message->content;
                    break;
                case Role::User:
                    $parts = $message->content === '' ? [] : [['type' => 'input_text', 'text' => $message->content]];
                    foreach ($message->media as $part) {
                        if ($part->kind !== MediaPart::IMAGE) {
                            throw new LlmException(ErrorKind::BadRequest, 'OpenAI: the Responses API takes pictures, not ' . $part->kind);
                        }
                        $parts[] = ['type' => 'input_image', 'image_url' => $part->dataUrl()];
                    }
                    $input[] = ['type' => 'message', 'role' => 'user', 'content' => $parts === [] ? [['type' => 'input_text', 'text' => '']] : $parts];
                    break;
                case Role::Assistant:
                    if ($message->content !== '') {
                        $input[] = ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $message->content]]];
                    }
                    foreach ($message->toolCalls as $call) {
                        $input[] = ['type' => 'function_call', 'call_id' => $call->id, 'name' => $call->name, 'arguments' => $call->argumentsJson === '' ? '{}' : $call->argumentsJson];
                    }
                    break;
                case Role::Tool:
                    $input[] = ['type' => 'function_call_output', 'call_id' => $message->toolCallId ?? '', 'output' => $message->content];
                    break;
            }
        }

        $payload = ['model' => $request->model, 'input' => $input, 'store' => false];
        if ($instructions !== []) {
            $payload['instructions'] = \implode("\n\n", $instructions);
        }
        if ($request->tools !== []) {
            $payload['tools'] = \array_map(static fn($tool): array => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => self::schema($tool->parameters),
                'strict' => false,
            ], $request->tools);
        }
        if ($request->maxTokens !== null) {
            $payload['max_output_tokens'] = $request->maxTokens;
        }
        if ($request->reasoningEffort !== null) {
            // `max` is the effort scale of other providers; the Responses API stops at `xhigh`.
            $payload['reasoning'] = ['effort' => $request->reasoningEffort === 'max' ? 'xhigh' : $request->reasoningEffort];
        } elseif ($request->temperature !== null) {
            // Reasoning models refuse a temperature other than the default.
            $payload['temperature'] = $request->temperature;
        }
        if ($stream) {
            $payload['stream'] = true;
        }

        return [...$payload, ...$request->extra];
    }

    /**
     * A complete `response` object: from a plain answer or from the terminal event of a stream.
     *
     * @param array<array-key, mixed> $data
     * @throws LlmException When the response failed.
     */
    public static function toResponse(array $data): ChatResponse
    {
        if (Json::string($data, 'status') === 'failed') {
            throw self::error(Json::object($data, 'error'));
        }
        $text = '';
        $reasoning = '';
        $calls = [];
        foreach (Json::objects($data, 'output') as $item) {
            switch (Json::string($item, 'type')) {
                case 'message':
                    foreach (Json::objects($item, 'content') as $part) {
                        if (Json::string($part, 'type') === 'output_text') {
                            $text .= Json::string($part, 'text') ?? '';
                        }
                    }
                    break;
                case 'reasoning':
                    foreach (Json::objects($item, 'summary') as $part) {
                        $reasoning .= Json::string($part, 'text') ?? '';
                    }
                    break;
                case 'function_call':
                    $calls[] = new ToolCall(
                        Json::string($item, 'call_id') ?? Json::string($item, 'id') ?? '',
                        Json::string($item, 'name') ?? '',
                        Json::string($item, 'arguments') ?? '{}',
                    );
                    break;
            }
        }

        return new ChatResponse(
            $text,
            $calls,
            self::finishReason($data, $calls !== []),
            self::usage(Json::object($data, 'usage')),
            Json::string($data, 'model') ?? '',
            Json::string($data, 'id') ?? '',
            $reasoning,
        );
    }

    /**
     * @param array<array-key, mixed> $data the response object
     */
    public static function finishReason(array $data, bool $hasCalls): FinishReason
    {
        if (Json::string($data, 'status') === 'incomplete') {
            return match (Json::string(Json::object($data, 'incomplete_details'), 'reason')) {
                'max_output_tokens' => FinishReason::Length,
                'content_filter' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            };
        }

        return $hasCalls ? FinishReason::ToolCalls : FinishReason::Stop;
    }

    /**
     * `input_tokens` counts the cached ones too; here they are a bucket of their own.
     *
     * @param array<array-key, mixed> $usage
     */
    public static function usage(array $usage): Usage
    {
        $input = Json::int($usage, 'input_tokens') ?? 0;
        $cached = \min($input, Json::int(Json::object($usage, 'input_tokens_details'), 'cached_tokens') ?? 0);

        return new Usage(
            $input - $cached,
            Json::int($usage, 'output_tokens') ?? 0,
            $cached,
            0,
            Json::int(Json::object($usage, 'output_tokens_details'), 'reasoning_tokens') ?? 0,
        );
    }

    /**
     * An error object of a failed response or of an `error` event: `{"code": "server_error", "message": "..."}`.
     *
     * @param array<array-key, mixed> $error
     */
    public static function error(array $error): LlmException
    {
        $message = Json::string($error, 'message') ?? 'Provider error';
        $kind = match (Json::string($error, 'code')) {
            'rate_limit_exceeded' => ErrorKind::RateLimited,
            'insufficient_quota' => ErrorKind::InsufficientCredits,
            'context_length_exceeded' => ErrorKind::ContextLength,
            'server_error', 'server_is_overloaded', 'internal_error' => ErrorKind::Server,
            default => null,
        };
        if ($kind === null) {
            return ErrorClassifier::fromErrorObject(['message' => $message]);
        }

        return new LlmException($kind, 'OpenRouter: ' . \mb_strcut(\preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500, 'UTF-8'));
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    private static function schema(array $parameters): array
    {
        if ($parameters === []) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (($parameters['type'] ?? null) === 'object' && (($parameters['properties'] ?? []) === [] || !\is_array($parameters['properties']))) {
            $parameters['properties'] = new \stdClass();
        }

        return $parameters;
    }
}
