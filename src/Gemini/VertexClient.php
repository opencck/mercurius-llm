<?php

declare(strict_types=1);

namespace Mercurius\Llm\Gemini;

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
 * Gemini of Google Cloud (Gemini Enterprise Agent Platform, formerly Vertex AI) through its OpenAI-compatible address.
 * The entry is a service account: every request carries the hourly token of {@see VertexAccount}. Models are named
 * `google/gemini-…`. The API reports no cost.
 *
 * spec: packages/llm/BRIEF.md#gemini-и-vertex
 *
 * ref: references/hermes-agent/agent/vertex_adapter.py@e96221f:L193-L196 (build_vertex_base_url) — the address; the
 *      region `global` has the host without a prefix
 */
final class VertexClient implements LlmClient
{
    public const DEFAULT_REGION = 'global';

    /** `global`, or a region of Google Cloud such as `us-central1` and `europe-west4`. */
    public const REGION_PATTERN = '/^(global|[a-z]+(-[a-z]+)+\d+)$/';

    /** A project id of Google Cloud: 6 to 30 lower-case letters, digits and hyphens; a domain-scoped one has a prefix. */
    public const PROJECT_PATTERN = '/^([a-z0-9.-]+:)?[a-z][a-z0-9-]{4,28}[a-z0-9]$/';

    private readonly ChatCompletions $completions;

    /**
     * @param string $projectId the project to bill; empty takes the one of the service account
     * @param string $baseUrl overrides the address built from the project and the region (tests, a private endpoint)
     */
    public function __construct(
        HttpClient $http,
        VertexAccount $account,
        string $projectId = '',
        string $region = self::DEFAULT_REGION,
        string $baseUrl = '',
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
        float $firstEventTimeout = 240.0,
        float $eventTimeout = 120.0,
    ) {
        $this->completions = new ChatCompletions(
            new HttpTransport(
                $http,
                null,
                $baseUrl !== '' ? $baseUrl : self::baseUrl($projectId !== '' ? $projectId : $account->projectId, $region),
                null,
                null,
                $connectTimeout,
                $inactivityTimeout,
                $transferTimeout,
                $maxResponseBytes,
                [],
                $account->token(...),
            ),
            Dialect::Gemini,
            'Vertex',
            $firstEventTimeout,
            $eventTimeout,
        );
    }

    /**
     * @throws \InvalidArgumentException when the project or the region cannot be part of an address
     */
    public static function baseUrl(string $projectId, string $region = self::DEFAULT_REGION): string
    {
        if (\preg_match(self::PROJECT_PATTERN, $projectId) !== 1) {
            throw new \InvalidArgumentException('Vertex project id is empty or malformed');
        }
        if (\preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw new \InvalidArgumentException('Vertex region must be "global" or a Google Cloud region such as us-central1');
        }
        $host = $region === 'global' ? 'aiplatform.googleapis.com' : $region . '-aiplatform.googleapis.com';

        return \sprintf('https://%s/v1beta1/projects/%s/locations/%s/endpoints/openapi', $host, $projectId, $region);
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
