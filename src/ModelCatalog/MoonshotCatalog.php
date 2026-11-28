<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Moonshot\MoonshotClient;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;

/**
 * The models of a Kimi Platform key (`GET /v1/models`): the window, what they read and whether they always think come
 * from the list; prices and indexes from the OpenRouter catalog (`moonshotai/<id>`). A model the reference does not
 * know stays, without a price.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/kimi-code/packages/oauth/src/open-platform.ts@a6ac07d:L47-L74 (toModelInfo) — `context_length`,
 *      `supports_image_in`, `supports_video_in`, `supports_tool_use`, `supports_thinking_type`
 * ref: docs/kimi-platform-list-models.md:L68-L71 (fetched 2026-11-28) — the fields of the list
 */
final class MoonshotCatalog
{
    private const REFERENCE_PREFIX = 'moonshotai/';

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        private readonly OpenRouterCatalog $reference,
        string $baseUrl = MoonshotClient::PLATFORM_URLS[0],
        float $timeout = 60.0,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, MoonshotClient::address($baseUrl, MoonshotClient::PLATFORM_URLS), MoonshotClient::PROJECT_NAME, MoonshotClient::PROJECT_URL, \min(10.0, $timeout), $timeout, $timeout, 4 * 1024 * 1024, MoonshotClient::headers($version));
    }

    /**
     * @return list<ModelInfo> by the order of the list
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $data = $this->transport->decodeJson($this->transport->send('GET', '/models', null, 'application/json', $cancellation), $cancellation);
        $entries = Json::objects($data, 'data');
        $known = [];
        foreach ($this->reference->fetch($cancellation) as $model) {
            $known[$model->id] = $model;
        }
        $models = [];
        foreach ($entries as $entry) {
            $id = Json::string($entry, 'id') ?? '';
            if ($id === '') {
                continue;
            }
            $ref = $known[self::REFERENCE_PREFIX . $id] ?? null;
            $context = Json::int($entry, 'context_length');
            $input = ['text'];
            if (($entry['supports_image_in'] ?? false) === true) {
                $input[] = 'image';
            }
            if (($entry['supports_video_in'] ?? false) === true) {
                $input[] = 'video';
            }
            $thinking = Json::string($entry, 'supports_thinking_type');
            $parameters = ($entry['supports_tool_use'] ?? true) === false ? [] : ['tools', 'tool_choice'];
            if (($entry['supports_reasoning'] ?? false) === true || $thinking === 'only' || $thinking === 'both') {
                $parameters[] = 'reasoning';
            }
            $models[] = new ModelInfo(
                $id,
                $ref->name ?? 'Moonshot: ' . $id,
                $context !== null && $context > 0 ? $context : $ref?->contextLength,
                $ref?->inputPricePerToken,
                $ref?->outputPricePerToken,
                $input,
                ['text'],
                $parameters,
                $ref?->maxCompletionTokens,
                $ref?->cacheReadPricePerToken,
                $ref?->cacheWritePricePerToken,
                $ref?->intelligenceIndex,
                $ref?->codingIndex,
                $ref?->agenticIndex,
                $thinking === 'only',
            );
        }

        return $models;
    }
}
