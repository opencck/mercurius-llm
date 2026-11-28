<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\Gemini\GeminiModel;
use Mercurius\Llm\Gemini\ToolSchema;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Message;
use Mercurius\Llm\Moonshot\MoonshotModel;
use Mercurius\Llm\Moonshot\ToolSchema as MoonshotSchema;
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
        $gemini = $dialect === Dialect::Gemini;
        $moonshot = $dialect === Dialect::Moonshot;
        // Gemini 3 and later want the signature of a tool call back, and a stand-in for a call another provider made
        $stub = $gemini && GeminiModel::usesThinkingLevel($request->model);
        $payload = [
            'model' => $request->model,
            'messages' => \array_map(static fn (Message $m): array => self::message($m, $gemini, $stub, $moonshot), $request->messages),
        ];
        if ($request->tools !== []) {
            $payload['tools'] = \array_map(static fn (ToolDefinition $t): array => self::tool($t, $dialect), $request->tools);
        }
        $effort = $request->reasoningEffort;
        if ($request->maxTokens !== null) {
            // ref: references/hermes-agent/utils.py@e96221fa:L835-L838 (model_forces_max_completion_tokens) — newer
            //      OpenAI models refuse max_tokens; the OpenAI API takes max_completion_tokens for all of them
            $payload[$dialect === Dialect::OpenAI || $moonshot ? 'max_completion_tokens' : 'max_tokens'] = match ($dialect) {
                Dialect::Gemini => GeminiModel::maxTokens($request->model, $effort, $request->maxTokens),
                // Kimi always thinks, and its thinking is billed against the ceiling
                Dialect::Moonshot => MoonshotModel::maxTokens($request->maxTokens),
                default => $request->maxTokens,
            };
        }
        $temperature = match ($dialect) {
            // Reasoning models of the OpenAI API refuse a temperature other than the default.
            Dialect::OpenAI => $effort === null,
            Dialect::Gemini => !GeminiModel::ignoresTemperature($request->model),
            Dialect::OpenRouter => true,
            // K3 fixes its temperature; Kimi manages it on its side
            Dialect::Moonshot => false,
        };
        if ($request->temperature !== null && $temperature) {
            $payload['temperature'] = $request->temperature;
        }
        if ($gemini) {
            // ref: references/hermes-agent/agent/transports/chat_completions.py@e96221f:L227-L239
            //      (_snake_case_gemini_thinking_config) and L632-L637 — the compatible address reads thinking from
            //      `extra_body.google.thinking_config` in snake case
            $thinking = GeminiModel::thinking($request->model, $effort);
            if ($thinking !== null) {
                $payload['extra_body'] = ['google' => ['thinking_config' => $thinking]];
            }
        } elseif ($moonshot) {
            $payload = \array_replace($payload, MoonshotModel::thinking($request->model, $effort));
        } elseif ($effort !== null) {
            if ($dialect === Dialect::OpenAI) {
                // The OpenAI API has no "max": its highest effort is "xhigh".
                $payload['reasoning_effort'] = $effort === 'max' ? 'xhigh' : $effort;
            } else {
                $payload['reasoning'] = ['effort' => $effort];
            }
        }

        // OpenAI Chat Completions refuses function tools with reasoning on ("use /v1/responses or set reasoning_effort
        // to 'none'"), and reasoning is on by default: with tools it is turned off explicitly.
        if ($dialect === Dialect::OpenAI && $request->tools !== []) {
            $payload['reasoning_effort'] = 'none';
            unset($payload['temperature']);
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
                self::signature($call),
            );
        }

        return new ChatResponse(
            Json::string($message, 'content') ?? '',
            $toolCalls,
            FinishReason::fromOpenAi(Json::string($choice, 'finish_reason')),
            self::usage(Json::object($data, 'usage')),
            Json::string($data, 'model') ?? '',
            Json::string($data, 'id') ?? '',
            Json::string($message, 'reasoning') ?? Json::string($message, 'reasoning_content') ?? '',
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
        // Moonshot reports the cached part at the top of `usage` as well
        $cacheRead = self::count($promptDetails, 'cached_tokens') ?: self::count($usage, 'cached_tokens');
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
     * The signature Gemini put on a tool call: `extra_content.google.thought_signature`, or the same key without the
     * namespace. An empty one is no signature: replayed, it fails as corrupted.
     *
     * ref: references/hermes-agent/agent/transports/chat_completions.py@e96221f:L320-L333
     *      (_has_replayable_thought_signature)
     *
     * @param array<array-key, mixed> $call
     */
    public static function signature(array $call): ?string
    {
        $extra = Json::object($call, 'extra_content');
        $signature = Json::string(Json::object($extra, 'google'), 'thought_signature') ?? Json::string($extra, 'thought_signature');

        return $signature !== null && \trim($signature) !== '' ? $signature : null;
    }

    /**
     * @param bool $signatures whether tool calls carry their signatures (the dialect of Google)
     * @param bool $stub whether a call without a signature gets the stand-in
     * @param bool $reasoning whether the reasoning of the turn goes back (the dialect of Moonshot)
     * @return array<string, mixed>
     */
    private static function message(Message $message, bool $signatures = false, bool $stub = false, bool $reasoning = false): array
    {
        $out = ['role' => $message->role->value];
        switch ($message->role) {
            case Role::Assistant:
                $out['content'] = $message->content === '' && $message->toolCalls !== [] ? null : $message->content;
                if ($reasoning) {
                    // ref: references/kimi-code/packages/core/src/llm/builtin/provider/kimi/protocol.ts@a6ac07d:L90-L121
                    //      (applyKimiMessageExtras) — no empty text next to tool calls
                    // ref: references/hermes-agent/agent/message_sanitization.py@e96221f:L702-L724 — a turn with tool
                    //      calls without reasoning is 400 on Kimi: a space stands in for a turn another provider made
                    if ($out['content'] === null) {
                        unset($out['content']);
                    }
                    if ($message->reasoning !== '') {
                        $out['reasoning_content'] = $message->reasoning;
                    } elseif ($message->toolCalls !== []) {
                        $out['reasoning_content'] = ' ';
                    }
                }
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
                    if ($signatures) {
                        // Gemini signs the first call of a step only: the stand-in goes there and nowhere else
                        foreach ($message->toolCalls as $i => $call) {
                            $signature = $call->signature ?? ($stub && $i === 0 ? GeminiModel::SKIP_SIGNATURE : null);
                            if ($signature !== null) {
                                $out['tool_calls'][$i]['extra_content'] = ['google' => ['thought_signature' => $signature]];
                            }
                        }
                    }
                }
                break;
            case Role::Tool:
                $out['tool_call_id'] = $message->toolCallId;
                $out['content'] = $message->content;
                break;
            default:
                // ref: references/hermes-agent/agent/prompt_caching.py@e96221fa:L50-L54 (_text_part) — a marker goes on a text part,
                //      so a marked string becomes one part
                $text = ['type' => 'text', 'text' => $message->content] + ($message->cacheMark ? ['cache_control' => ['type' => 'ephemeral']] : []);
                $out['content'] = $message->cacheMark || $message->media !== []
                    ? [$text, ...\array_map(static fn (MediaPart $m): array => $m->kind === MediaPart::VIDEO
                        ? ['type' => 'video_url', 'video_url' => ['url' => $m->dataUrl()]]
                        : ['type' => 'image_url', 'image_url' => ['url' => $m->dataUrl()]], $message->media)]
                    : $message->content;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tool(ToolDefinition $tool, Dialect $dialect = Dialect::OpenRouter): array
    {
        $parameters = $tool->parameters === []
            ? ['type' => 'object', 'properties' => new \stdClass()]
            : self::fixEmptyObjects(match ($dialect) {
                Dialect::Gemini => ToolSchema::clean($tool->parameters),
                Dialect::Moonshot => MoonshotSchema::clean($tool->parameters),
                default => $tool->parameters,
            });
        if ($dialect === Dialect::Moonshot && $tool->parameters === []) {
            $parameters['required'] = [];
        }

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
