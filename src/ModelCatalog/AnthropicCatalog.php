<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\Anthropic\AnthropicClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;

/**
 * The models an Anthropic key can use (`GET /v1/models`), described by the OpenRouter catalog: the Anthropic list
 * has ids and names only, while OpenRouter's public catalog has prices, windows, modalities and benchmark indices.
 * The two spell versions differently — `claude-opus-5-5` there is `anthropic/claude-opus-5.5` here, a dated id
 * `claude-haiku-4-5-20251001` is `anthropic/claude-haiku-4.5` — so the id is looked up as written, with dotted
 * versions and without the date. A model the reference does not know is left out.
 */
final class AnthropicCatalog
{
    private const REFERENCE_PREFIX = 'anthropic/';

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        private readonly OpenRouterCatalog $reference,
        string $baseUrl = AnthropicClient::DEFAULT_BASE_URL,
        float $timeout = 60.0,
        int $maxResponseBytes = 16 * 1024 * 1024,
    ) {
        $this->transport = new HttpTransport(
            $http,
            null,
            $baseUrl,
            null,
            null,
            \min(10.0, $timeout),
            $timeout,
            $timeout,
            $maxResponseBytes,
            ['x-api-key' => $apiKey, 'anthropic-version' => AnthropicClient::API_VERSION],
        );
    }

    /**
     * @return list<ModelInfo> by the order of the Anthropic list
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        // One page of the largest size: an account has a few dozen models.
        $response = $this->transport->send('GET', '/models?limit=1000', null, 'application/json', $cancellation);
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
            $ref = null;
            foreach (self::candidates($id) as $candidate) {
                $ref = $known[self::REFERENCE_PREFIX . $candidate] ?? null;
                if ($ref !== null) {
                    break;
                }
            }
            if ($ref === null) {
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
                $ref->reasoningMandatory,
            );
        }

        return $models;
    }

    /**
     * @return list<string> the id as written, then with dotted versions, then also without a trailing date
     */
    private static function candidates(string $id): array
    {
        $undated = \preg_replace('/-\d{8}$/', '', $id) ?? $id;
        $dotted = static fn(string $s): string => \preg_replace('/(?<=\d)-(?=\d(?!\d{7}))/', '.', $s) ?? $s;

        return \array_values(\array_unique([$id, $dotted($id), $undated, $dotted($undated)]));
    }
}
