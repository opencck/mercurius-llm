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

    /**
     * @return array<string, mixed>
     * @throws LlmException When a message carries a video: the Messages API takes pictures only.
     */
    public static function toPayload(ChatRequest $request, bool $stream): array
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
            $payload['tools'] = \array_map(static fn($tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => self::schema($tool->parameters),
            ], $request->tools);
        }
        // The version 2026-10-21 refuses temperature, top_p and top_k: the effort is the only knob left.
        $effort = match ($request->reasoningEffort) {
            null => null,
            'none', 'minimal' => 'low',
            default => \in_array($request->reasoningEffort, self::EFFORTS, true) ? $request->reasoningEffort : null,
        };
        if ($effort !== null) {
            $payload['output_config'] = ['effort' => $effort];
        }
        if ($stream) {
            $payload['stream'] = true;
        }

        return [...$payload, ...$request->extra];
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws LlmException
     */
    public static function toResponse(array $data): ChatResponse
    {
        if (Json::string($data, 'type') === 'error') {
            throw self::error(Json::object($data, 'error'));
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

        return new ChatResponse(
            $text,
            $calls,
            self::finishReason(Json::string($data, 'stop_reason')),
            self::usage(Json::object($data, 'usage')),
            Json::string($data, 'model') ?? '',
            Json::string($data, 'id') ?? '',
            $reasoning,
        );
    }

    /**
     * @param array<array-key, mixed> $usage
     */
    public static function usage(array $usage, ?Usage $before = null): Usage
    {
        return new Usage(
            Json::int($usage, 'input_tokens') ?? $before->inputTokens ?? 0,
            Json::int($usage, 'output_tokens') ?? $before->outputTokens ?? 0,
            Json::int($usage, 'cache_read_input_tokens') ?? $before->cacheReadTokens ?? 0,
            Json::int($usage, 'cache_creation_input_tokens') ?? $before->cacheWriteTokens ?? 0,
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
    public static function error(array $error): LlmException
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

        return new LlmException($kind, 'Anthropic: ' . \mb_strcut(\preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500, 'UTF-8'));
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
