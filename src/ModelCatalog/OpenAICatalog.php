<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenAI\OpenAIClient;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;

/**
 * The models an OpenAI key can use (`GET /v1/models`), described by the OpenRouter catalog: the OpenAI API lists ids
 * only, while OpenRouter's public catalog has prices, windows, modalities, tool support and benchmark indices for
 * `openai/<id>`. A model the reference does not know (embeddings, speech, images) is left out: without a price and
 * traits nothing could choose it.
 */
final class OpenAICatalog
{
    private const REFERENCE_PREFIX = 'openai/';
    /**
     * Families the OpenAI API serves only through `/v1/responses` or for one purpose: Chat Completions answers them
     * with 400, so they must not be chosen.
     */
    private const NOT_CHAT = '/(codex|-pro(-|$)|search|deep-research|realtime|audio|transcribe|tts|image)/i';

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        private readonly OpenRouterCatalog $reference,
        string $baseUrl = OpenAIClient::DEFAULT_BASE_URL,
        float $timeout = 60.0,
        int $maxResponseBytes = 16 * 1024 * 1024,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, $baseUrl, null, null, \min(10.0, $timeout), $timeout, $timeout, $maxResponseBytes);
    }

    /**
     * @return list<ModelInfo> by the order of the OpenAI list
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $response = $this->transport->send('GET', '/models', null, 'application/json', $cancellation);
        $ids = [];
        foreach (Json::objects($this->transport->decodeJson($response, $cancellation), 'data') as $entry) {
            $id = Json::string($entry, 'id');
            if ($id !== null && $id !== '') {
                $ids[] = $id;
            }
        }
        $known = [];
        foreach ($this->reference->fetch($cancellation) as $model) {
            $known[$model->id] = $model;
        }
        $models = [];
        foreach ($ids as $id) {
            $ref = $known[self::REFERENCE_PREFIX . $id] ?? null;
            if ($ref === null || \preg_match(self::NOT_CHAT, $id) === 1) {
                continue;
            }
            $models[] = new ModelInfo(
                $id,
                $ref->name,
                $ref->contextLength,
                $ref->inputPricePerToken,
                $ref->outputPricePerToken,
                $ref->inputModalities,
                $ref->outputModalities,
                $ref->supportedParameters,
                $ref->maxCompletionTokens,
                $ref->cacheReadPricePerToken,
                $ref->cacheWritePricePerToken,
                $ref->intelligenceIndex,
                $ref->codingIndex,
                $ref->agenticIndex,
            );
        }

        return $models;
    }
}
