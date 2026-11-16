<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Llm\Usage;

/**
 * Converts between the package's format and the OpenAI-compatible Chat Completions format used by OpenRouter.
 *
 * @internal
 */
final class PayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toPayload(ChatRequest $request, bool $stream, Dialect $dialect = Dialect::OpenRouter): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => \array_map(self::message(...), $request->messages),
        ];
        if ($request->tools !== []) {
            $payload['tools'] = \array_map(self::tool(...), $request->tools);
        }
        $openAi = $dialect === Dialect::OpenAI;
        if ($request->maxTokens !== null) {
            // ref: references/hermes-agent/utils.py@e96221fa:L835-L838 (model_forces_max_completion_tokens) — newer
            //      OpenAI models refuse max_tokens; the OpenAI API takes max_completion_tokens for all of them
            $payload[$openAi ? 'max_completion_tokens' : 'max_tokens'] = $request->maxTokens;
        }
        // Reasoning models of the OpenAI API refuse a temperature other than the default.
        if ($request->temperature !== null && !($openAi && $request->reasoningEffort !== null)) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->reasoningEffort !== null) {
            if ($openAi) {
                // The OpenAI API has no "max": its highest effort is "xhigh".
                $payload['reasoning_effort'] = $request->reasoningEffort === 'max' ? 'xhigh' : $request->reasoningEffort;
            } else {
                $payload['reasoning'] = ['effort' => $request->reasoningEffort];
            }
        }

        $payload = \array_replace($payload, $request->extra);

        if ($stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        } else {
            unset($payload['stream'], $payload['stream_options']);
        }

        return $payload;
    }

    /**
     * Parses a non-streaming Chat Completions response body (already decoded).
     *
     * @param array<array-key, mixed> $data
     * @throws LlmException
     */
    public static function toResponse(array $data): ChatResponse
    {
        if (Json::has($data, 'error')) {
            throw ErrorClassifier::fromErrorObject(Json::object($data, 'error'));
        }
        $choices = Json::objects($data, 'choices');
        if ($choices === []) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: response has no choices');
        }
        $choice = $choices[0];
        $message = Json::object($choice, 'message');

        $toolCalls = [];
        foreach (Json::objects($message, 'tool_calls') as $call) {
            $function = Json::object($call, 'function');
            $toolCalls[] = new ToolCall(
                Json::string($call, 'id') ?? '',
                Json::string($function, 'name') ?? '',
                Json::string($function, 'arguments') ?? '',
            );
        }

        return new ChatResponse(
            Json::string($message, 'content') ?? '',
            $toolCalls,
            FinishReason::fromOpenAi(Json::string($choice, 'finish_reason')),
            self::usage(Json::object($data, 'usage')),
            Json::string($data, 'model') ?? '',
            Json::string($data, 'id') ?? '',
            Json::string($message, 'reasoning') ?? '',
        );
    }

    /**
     * Normalizes an OpenRouter `usage` object. `prompt_tokens` includes cached tokens, so the uncached part is
     * `prompt_tokens - cached_tokens - cache_write_tokens`.
     *
     * @param array<array-key, mixed> $usage
     */
    public static function usage(array $usage): Usage
    {
        $promptDetails = Json::object($usage, 'prompt_tokens_details');
        $completionDetails = Json::object($usage, 'completion_tokens_details');

        $prompt = self::count($usage, 'prompt_tokens');
        $cacheRead = self::count($promptDetails, 'cached_tokens');
        $cacheWrite = self::count($promptDetails, 'cache_write_tokens');

        return new Usage(
            \max(0, $prompt - $cacheRead - $cacheWrite),
            self::count($usage, 'completion_tokens'),
            $cacheRead,
            $cacheWrite,
            self::count($completionDetails, 'reasoning_tokens'),
            Json::float($usage, 'cost'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(Message $message): array
    {
        $out = ['role' => $message->role->value];
        switch ($message->role) {
            case Role::Assistant:
                $out['content'] = $message->content === '' && $message->toolCalls !== [] ? null : $message->content;
                if ($message->toolCalls !== []) {
                    $out['tool_calls'] = \array_map(
                        static fn (ToolCall $call): array => [
                            'id' => $call->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $call->name,
                                'arguments' => $call->argumentsJson === '' ? '{}' : $call->argumentsJson,
                            ],
                        ],
                        $message->toolCalls,
                    );
                }
                break;
            case Role::Tool:
                $out['tool_call_id'] = $message->toolCallId;
                $out['content'] = $message->content;
                break;
            default:
                $out['content'] = $message->content;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tool(ToolDefinition $tool): array
    {
        $parameters = $tool->parameters === []
            ? ['type' => 'object', 'properties' => new \stdClass()]
            : self::fixEmptyObjects($tool->parameters);

        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $parameters,
            ],
        ];
    }

    /**
     * PHP encodes an empty array as `[]`, but JSON Schema needs `{}` for `properties`: fix that recursively.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private static function fixEmptyObjects(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }
            if ($value === [] && ($key === 'properties' || $key === 'patternProperties' || $key === '$defs' || $key === 'definitions')) {
                $schema[$key] = new \stdClass();
            } else {
                $schema[$key] = self::fixEmptyObjects($value);
            }
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function count(array $data, string $key): int
    {
        return \max(0, Json::int($data, $key) ?? 0);
    }
}
