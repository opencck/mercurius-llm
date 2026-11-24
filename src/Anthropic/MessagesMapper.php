<?php

declare(strict_types=1);

namespace Mercurius\Llm\Anthropic;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\Json;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;

/**
 * Maps the provider-independent request and answer to the Anthropic Messages API and back.
 *
 * @internal
 */
final class MessagesMapper
{
    /** Messages API requires `max_tokens`; a request without a limit gets this one. */
    public const DEFAULT_MAX_TOKENS = 16000;

    /** Text blocks must not be blank: an empty turn gets this placeholder instead of a 400. */
    private const EMPTY = '(empty message)';

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Families that think always: `thinking: disabled` is a 400 there. */
    private const MANDATORY_THINKING = ['fable', 'opus-5-5', 'sonnet-5-5', 'haiku-5-5', 'mythos'];

    /** Short names a caller may use, and the model each one means. */
    private const ALIASES = ['sonnet' => 'sonnet-5-5', 'haiku' => 'haiku-5-5', 'opus' => 'opus-5-5', 'fable' => 'fable-5-2'];

    /**
     * @param bool $eagerToolInput Ask the API to stream the arguments of tool calls as the model writes them. Off
     *                             for an endpoint that refuses the field.
     * @return array<string, mixed>
     * @throws LlmException When a message carries a video: the Messages API takes pictures only.
     */
    public static function toPayload(ChatRequest $request, bool $stream, bool $eagerToolInput = true): array
    {
        $system = [];
        /** @var list<array{role: 'user'|'assistant', content: list<array<string, mixed>>}> $turns */
        $turns = [];
        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                $system[] = self::cached(['type' => 'text', 'text' => $message->content], $message->cacheMark);
                continue;
            }
            [$role, $blocks] = match ($message->role) {
                Role::Assistant => ['assistant', self::assistantBlocks($message)],
                Role::Tool => ['user', [[
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId ?? '',
                    'content' => \trim($message->content) === '' ? '(no output)' : $message->content,
                ]]],
                default => ['user', self::userBlocks($message)],
            };
            if ($message->cacheMark && $blocks !== []) {
                $last = \array_key_last($blocks);
                $blocks[$last] = self::cached($blocks[$last], true);
            }
            // ref: references/hermes-agent/agent/anthropic_message_convert.py@e96221fa:L542-L558 (_merge_consecutive_roles)
            //      — the roles must alternate: a tool result and the next words of the person become one user turn;
            //      the blocks are kept whole so a cached prefix stays byte-equal.
            $lastTurn = \array_key_last($turns);
            if ($lastTurn !== null && $turns[$lastTurn]['role'] === $role) {
                $turns[$lastTurn]['content'] = [...$turns[$lastTurn]['content'], ...$blocks];
            } else {
                $turns[] = ['role' => $role, 'content' => $blocks];
            }
        }
        // ref: references/hermes-agent/agent/anthropic_message_convert.py@e96221fa:L689-L701 (_ensure_leading_user_turn)
        //      — the first turn must be the person's.
        if ($turns === [] || $turns[0]['role'] !== 'user') {
            \array_unshift($turns, ['role' => 'user', 'content' => [['type' => 'text', 'text' => self::EMPTY]]]);
        }

        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'messages' => $turns,
        ];
        if ($system !== []) {
            $payload['system'] = $system;
        }
        if ($request->tools !== []) {
            // Without the field the API holds the arguments of a call back until they are whole: a long call looks
            // like a stalled stream. With it the arguments are not checked by the API — the accumulator checks them.
            $eager = $stream && $eagerToolInput ? ['eager_input_streaming' => true] : [];
            $payload['tools'] = \array_map(static fn($tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                ...$eager,
                'input_schema' => self::schema($tool->parameters),
            ], $request->tools);
        }
        // The version 2026-10-21 refuses temperature, top_p and top_k: the effort is the only knob left.
        $effort = match ($request->reasoningEffort) {
            null => null,
            'none', 'minimal' => 'low',
            default => \in_array($request->reasoningEffort, self::EFFORTS, true) ? $request->reasoningEffort : null,
        };
        $model = self::family($request->model);
        $adaptive = self::adaptive($model);
        // ref: references/hermes-agent/agent/anthropic_adapter.py@e96221fa:L618-L638 (_thinking_kwargs) — a disable
        //      where the model takes it, else the lowest effort; adaptive thinking with the summary shown, since the
        //      newer models hide the text of thinking by default; nothing for a model without adaptive thinking.
        //      Departure: an effort the model lacks is cut down to `high`, not raised to `max`, and thinking is
        //      turned on only where it was asked for or is the model's own default.
        if ($request->reasoningEffort === 'none' && self::acceptsThinkingDisable($model)) {
            $payload['thinking'] = ['type' => 'disabled'];
            $effort = null;
        } elseif ($adaptive && $request->reasoningEffort !== 'none' && ($effort !== null || self::thinksByDefault($model))) {
            $payload['thinking'] = ['type' => 'adaptive', 'display' => 'summarized'];
        }
        if ($effort === 'xhigh' && \preg_match('/^(opus|sonnet)-4-6(-|$)/', $model) === 1) {
            $effort = 'high';
        }
        // A model without adaptive thinking has no effort parameter either, but Opus 4.5, which takes an effort alone.
        if ($effort !== null && ($adaptive || \str_starts_with($model, 'opus-4-5'))) {
            $payload['output_config'] = ['effort' => $effort];
        }
        if ($stream) {
            $payload['stream'] = true;
        }

        return [...$payload, ...$request->extra];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param ?string $requestId The id of the HTTP request, from its header.
     * @param string $provider The name the errors are worded with.
     * @throws LlmException
     */
    public static function toResponse(array $data, ?string $requestId = null, string $provider = 'Anthropic'): ChatResponse
    {
        if (Json::string($data, 'type') === 'error') {
            throw self::error(Json::object($data, 'error'), $provider);
        }
        $text = '';
        $reasoning = '';
        $calls = [];
        foreach (Json::objects($data, 'content') as $block) {
            match (Json::string($block, 'type')) {
                'text' => $text .= Json::string($block, 'text') ?? '',
                'thinking' => $reasoning .= Json::string($block, 'thinking') ?? '',
                'tool_use' => $calls[] = new ToolCall(
                    Json::string($block, 'id') ?? '',
                    Json::string($block, 'name') ?? '',
                    self::arguments($block['input'] ?? null),
                ),
                default => null,
            };
        }

        $stopReason = Json::string($data, 'stop_reason');
        $refusal = Json::object($data, 'stop_details');

        return new ChatResponse(
            $text,
            // A call the model was writing when it refused is not a call to run.
            $stopReason === 'refusal' ? [] : $calls,
            self::finishReason($stopReason),
            self::usage(Json::object($data, 'usage')),
            Json::string($data, 'model') ?? '',
            Json::string($data, 'id') ?? '',
            $reasoning,
            $requestId,
            Json::string($refusal, 'category'),
            Json::string($refusal, 'explanation'),
        );
    }

    /**
     * The model as its family and version, without what a host adds around the name: `anthropic.claude-opus-5-5`,
     * `anthropic/claude-opus-5-5`, `claude-opus-5-5[1m]` and `opus` are all `opus-5-5`.
     */
    private static function family(string $model): string
    {
        $name = \strtolower($model);
        $name = \str_ends_with($name, '[1m]') ? \substr($name, 0, -4) : $name;
        $name = (string) \preg_replace('/^(anthropic[.\/])?(claude-)?/', '', $name);

        return self::ALIASES[$name] ?? $name;
    }

    /** Claude 4.6 and later take `thinking: adaptive` and `output_config.effort`; Haiku 4.5 and older do not. */
    private static function adaptive(string $family): bool
    {
        return \preg_match('/^(3-|(opus|sonnet|haiku)-(3|4)(-[0-5])?(-\d{8})?$)/', $family) !== 1;
    }

    private static function acceptsThinkingDisable(string $family): bool
    {
        foreach (self::MANDATORY_THINKING as $always) {
            if (\str_starts_with($family, $always)) {
                return false;
            }
        }

        return true;
    }

    /** The 5.x families, Fable and Mythos think when nothing is said; 4.6–4.8 think only when asked. */
    private static function thinksByDefault(string $family): bool
    {
        return \preg_match('/^(fable|mythos|(opus|sonnet|haiku)-[5-9])/', $family) === 1;
    }

    /**
     * @param array<array-key, mixed> $usage
     */
    public static function usage(array $usage, ?Usage $before = null): Usage
    {
        // ref: references/hermes-agent/agent/usage_pricing.py@e96221fa:L703-L709 — the tokens of thinking are a
        //      part of the output, counted in `output_tokens_details.thinking_tokens`: the whole thinking, not the
        //      summary that is shown.
        return new Usage(
            Json::int($usage, 'input_tokens') ?? $before->inputTokens ?? 0,
            Json::int($usage, 'output_tokens') ?? $before->outputTokens ?? 0,
            Json::int($usage, 'cache_read_input_tokens') ?? $before->cacheReadTokens ?? 0,
            Json::int($usage, 'cache_creation_input_tokens') ?? $before->cacheWriteTokens ?? 0,
            Json::int(Json::object($usage, 'output_tokens_details'), 'thinking_tokens') ?? $before->reasoningTokens ?? 0,
        );
    }

    public static function finishReason(?string $stopReason): FinishReason
    {
        return match ($stopReason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /**
     * An `error` object of a 200 body or of a stream event: `{"type": "overloaded_error", "message": "..."}`.
     *
     * @param array<array-key, mixed> $error
     */
    public static function error(array $error, string $provider = 'Anthropic'): LlmException
    {
        $type = Json::string($error, 'type') ?? '';
        $message = Json::string($error, 'message') ?? 'Provider error';
        $kind = match ($type) {
            'authentication_error', 'permission_error' => ErrorKind::Auth,
            'billing_error' => ErrorKind::InsufficientCredits,
            'rate_limit_error' => ErrorKind::RateLimited,
            'request_too_large' => ErrorKind::ContextLength,
            'api_error', 'overloaded_error' => ErrorKind::Server,
            'timeout_error' => ErrorKind::Timeout,
            'invalid_request_error', 'not_found_error' => \str_contains(\strtolower($message), 'too long') ? ErrorKind::ContextLength : ErrorKind::BadRequest,
            default => ErrorKind::Unknown,
        };

        return new LlmException($kind, $provider . ': ' . \mb_strcut(\preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500, 'UTF-8'));
    }

    /** A tool input as the JSON the model wrote; an empty input is the empty object. */
    public static function arguments(mixed $input): string
    {
        if (!\is_array($input) || $input === []) {
            return '{}';
        }

        $json = \json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? '{}' : $json;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws LlmException
     */
    private static function userBlocks(Message $message): array
    {
        $blocks = \trim($message->content) === '' ? [] : [['type' => 'text', 'text' => $message->content]];
        foreach ($message->media as $part) {
            if ($part->kind !== MediaPart::IMAGE) {
                throw new LlmException(ErrorKind::BadRequest, 'Anthropic: the Messages API takes pictures, not ' . $part->kind);
            }
            $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part->mime, 'data' => \base64_encode($part->bytes)]];
        }

        return $blocks === [] ? [['type' => 'text', 'text' => self::EMPTY]] : $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function assistantBlocks(Message $message): array
    {
        // Thinking blocks are not sent back: dropping all of them is a valid edit of the history, and their
        // signatures are not kept.
        $blocks = \trim($message->content) === '' ? [] : [['type' => 'text', 'text' => $message->content]];
        foreach ($message->toolCalls as $call) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,
                // Decoded as objects, so `{}` inside the arguments stays an object.
                'input' => self::object(\json_decode($call->argumentsJson, false)),
            ];
        }

        return $blocks === [] ? [['type' => 'text', 'text' => self::EMPTY]] : $blocks;
    }

    /** Malformed arguments of an earlier call go back as an empty input: the API takes objects only. */
    private static function object(mixed $value): \stdClass
    {
        return $value instanceof \stdClass ? $value : new \stdClass();
    }

    /**
     * ref: references/hermes-agent/agent/anthropic_message_convert.py@e96221fa:L124-L141 (_normalize_tool_input_schema)
     * — an object schema needs `properties`, and an empty one goes as `{}`, not `[]`; the nullable unions hermes
     * also collapses are left to the tool definitions.
     *
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    private static function schema(array $parameters): array
    {
        if ($parameters === []) {
            $parameters = ['type' => 'object'];
        }
        if (($parameters['type'] ?? null) === 'object' && (($parameters['properties'] ?? []) === [] || !\is_array($parameters['properties']))) {
            $parameters['properties'] = new \stdClass();
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function cached(array $block, bool $mark): array
    {
        return $mark ? [...$block, 'cache_control' => ['type' => 'ephemeral']] : $block;
    }
}
