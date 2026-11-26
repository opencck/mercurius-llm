<?php

declare(strict_types=1);

namespace Mercurius\Llm\Antigravity;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\OpenRouter\ChatCompletions;
use Mercurius\Llm\OpenRouter\Dialect;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\StreamEvent;

/**
 * The Chat Completions side of the `mercurius-antigravity` service: models of a Google Antigravity plan served by the
 * official CLI in a service of its own. The body is the plain one (`max_tokens`, `reasoning.effort`): the effort
 * reaches the service with tools too, and the service picks the level the model has. The service reports no cost.
 *
 * spec: packages/llm/BRIEF.md#antigravity
 */
final class AntigravityClient implements LlmClient
{
    private readonly ChatCompletions $completions;

    /**
     * @param string $token the token of the module
     * @param string $baseUrl the address of the service with `/v1`
     * @param float $firstEventTimeout longer than the service itself waits: up to two minutes for a place in its queue and
     *                                 five minutes of silence of the CLI, so its own answer about a timeout arrives first
     */
    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $token,
        string $baseUrl,
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 600.0,
        float $transferTimeout = 1800.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
        float $firstEventTimeout = 480.0,
        float $eventTimeout = 360.0,
    ) {
        if ($token === '') {
            throw new \InvalidArgumentException('The token of the antigravity module must not be empty');
        }
        $this->completions = new ChatCompletions(
            new HttpTransport($http, $token, $baseUrl, null, null, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes),
            Dialect::OpenRouter,
            'Antigravity',
            $firstEventTimeout,
            $eventTimeout,
        );
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        return $this->completions->complete($request, $cancellation);
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        return $this->completions->stream($request, $cancellation);
    }
}
