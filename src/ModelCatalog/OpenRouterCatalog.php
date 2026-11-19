<?php

declare(strict_types=1);

namespace Mercurius\Llm\ModelCatalog;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;
use Mercurius\Llm\OpenRouter\OpenRouterClient;

/**
 * Reads the OpenRouter model catalog (`GET /models`). The endpoint is public; the API key is optional.
 */
final class OpenRouterCatalog
{
    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        ?string $apiKey = null,
        string $baseUrl = OpenRouterClient::DEFAULT_BASE_URL,
        float $timeout = 60.0,
        int $maxResponseBytes = 64 * 1024 * 1024,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, $baseUrl, null, null, \min(10.0, $timeout), $timeout, $timeout, $maxResponseBytes);
    }

    /**
     * @return list<ModelInfo>
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $response = $this->transport->send('GET', '/models', null, 'application/json', $cancellation);

        return self::parse($this->transport->decodeJson($response, $cancellation));
    }

    /**
     * The models whose tool calls OpenRouter saw pass their schemas at least this share of the time (0..1). The share
     * itself is not in the catalog: the filter of the endpoint gives the models above it.
     *
     * @return list<string> model ids
     * @throws LlmException
     */
    public function toolReliable(float $minShare, ?Cancellation $cancellation = null): array
    {
        $share = \rtrim(\rtrim(\sprintf('%.4F', \max(0.0, \min(1.0, $minShare))), '0'), '.');
        $response = $this->transport->send('GET', '/models?min_tool_success_rate=' . $share, null, 'application/json', $cancellation);

        return \array_map(static fn (ModelInfo $m): string => $m->id, self::parse($this->transport->decodeJson($response, $cancellation)));
    }

    /**
     * Parses a decoded `/models` response. Entries without an id are skipped.
     *
     * @param array<array-key, mixed> $data
     * @return list<ModelInfo>
     */
    public static function parse(array $data): array
    {
        $models = [];
        foreach (Json::objects($data, 'data') as $entry) {
            $id = Json::string($entry, 'id');
            if ($id === null || $id === '') {
                continue;
            }
            $pricing = Json::object($entry, 'pricing');
            $architecture = Json::object($entry, 'architecture');
            $topProvider = Json::object($entry, 'top_provider');
            $scores = Json::object(Json::object($entry, 'benchmarks'), 'artificial_analysis');
            $reasoning = Json::object($entry, 'reasoning');

            $models[] = new ModelInfo(
                $id,
                Json::string($entry, 'name') ?? $id,
                self::positiveInt($entry, 'context_length'),
                self::price($pricing, 'prompt'),
                self::price($pricing, 'completion'),
                Json::strings($architecture, 'input_modalities'),
                Json::strings($architecture, 'output_modalities'),
                Json::strings($entry, 'supported_parameters'),
                self::positiveInt($topProvider, 'max_completion_tokens'),
                self::price($pricing, 'input_cache_read'),
                self::price($pricing, 'input_cache_write'),
                Json::float($scores, 'intelligence_index'),
                Json::float($scores, 'coding_index'),
                Json::float($scores, 'agentic_index'),
                ($reasoning['mandatory'] ?? false) === true,
            );
        }

        return $models;
    }

    /**
     * Prices come as decimal strings; negative values mean "variable" (routers).
     *
     * @param array<array-key, mixed> $data
     */
    private static function price(array $data, string $key): ?float
    {
        $price = Json::float($data, $key);

        return $price !== null && $price >= 0.0 ? $price : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function positiveInt(array $data, string $key): ?int
    {
        $value = Json::int($data, $key);

        return $value !== null && $value > 0 ? $value : null;
    }
}
