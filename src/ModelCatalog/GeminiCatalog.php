<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\Gemini\GeminiClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;

/**
 * The models a Gemini API key can use (`GET …/openai/models`), described by the OpenRouter catalog: the address lists
 * ids only (`models/gemini-…`), while OpenRouter's public catalog has prices, windows, modalities and tool support
 * for `google/<id>`. A model the reference does not know, or one that does not answer with text (embeddings, images,
 * speech, music, video), is left out: nothing could choose it.
 *
 * Google prices a request above 200 thousand tokens higher; the reference carries the base price only, so the cost of
 * such a request is underestimated.
 *
 * ref: references/hermes-agent/agent/usage_pricing.py@e96221f:L223-L242 — hermes keeps its own table of Google
 *      prices; here the prices are the reference's
 */
final class GeminiCatalog
{
    private const REFERENCE_PREFIX = 'google/';

    private const NOT_CHAT = '/(embedding|imagen|image|veo|lyria|tts|audio|live|transcribe|aqa|robotics|computer-use)/i';

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        private readonly OpenRouterCatalog $reference,
        string $baseUrl = GeminiClient::DEFAULT_BASE_URL,
        float $timeout = 60.0,
        int $maxResponseBytes = 16 * 1024 * 1024,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, $baseUrl, null, null, \min(10.0, $timeout), $timeout, $timeout, $maxResponseBytes);
    }

    /**
     * @return list<ModelInfo> by the order of Google's list
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $response = $this->transport->send('GET', '/models', null, 'application/json', $cancellation);
        /** @var array<string, true> $ids */
        $ids = [];
        foreach (Json::objects($this->transport->decodeJson($response, $cancellation), 'data') as $entry) {
            $id = Json::string($entry, 'id') ?? '';
            $id = \str_starts_with($id, 'models/') ? \substr($id, \strlen('models/')) : $id;
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $known = [];
        foreach ($this->reference->fetch($cancellation) as $model) {
            $known[$model->id] = $model;
        }
        $models = [];
        foreach (\array_keys($ids) as $id) {
            $ref = $known[self::REFERENCE_PREFIX . $id] ?? null;
            if ($ref === null || \preg_match(self::NOT_CHAT, $id) === 1 || $ref->outputModalities !== ['text']) {
                continue;
            }
            $models[] = $ref->named($id);
        }

        return $models;
    }
}
