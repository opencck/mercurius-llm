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
 * The models of the Kimi Code subscription (`GET {address}/models`) with their traits: the window, what they read,
 * whether they always think, whether they call tools. A model without a window is left out: the official client
 * refuses it too. The subscription is paid by the plan: every price is zero.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/kimi-code/packages/oauth/src/managed-kimi-code.ts@a6ac07d:L394-L431 (toModelInfo), L433-L472
 *      (parseModelReasoning) — `limit.context`, `modalities.input`, `reasoning.type` only/both/none,
 *      `supports_tool_use` (yes when absent)
 */
final class KimiCodeCatalog
{
    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        string $baseUrl = MoonshotClient::KIMI_CODE_URLS[0],
        float $timeout = 60.0,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, MoonshotClient::address($baseUrl, MoonshotClient::KIMI_CODE_URLS), MoonshotClient::PROJECT_NAME, MoonshotClient::PROJECT_URL, \min(10.0, $timeout), $timeout, $timeout, 4 * 1024 * 1024, MoonshotClient::headers($version));
    }

    /**
     * @return list<ModelInfo> by the order of the list
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $data = $this->transport->decodeJson($this->transport->send('GET', '/models', null, 'application/json', $cancellation), $cancellation);
        $models = [];
        foreach (Json::objects($data, 'data') as $entry) {
            $id = Json::string($entry, 'id') ?? '';
            $context = Json::int(Json::object($entry, 'limit'), 'context');
            if ($id === '' || $context === null || $context <= 0) {
                continue;
            }
            $input = ['text'];
            foreach (Json::strings(Json::object($entry, 'modalities'), 'input') as $modality) {
                if (\in_array($modality, ['image', 'video'], true) && !\in_array($modality, $input, true)) {
                    $input[] = $modality;
                }
            }
            $thinking = Json::string(Json::object($entry, 'reasoning'), 'type');
            $parameters = ($entry['supports_tool_use'] ?? true) === false ? [] : ['tools', 'tool_choice'];
            if ($thinking === 'only' || $thinking === 'both') {
                $parameters[] = 'reasoning';
            }
            $models[] = new ModelInfo(
                $id,
                'Kimi Code: ' . (Json::string($entry, 'display_name') ?? $id),
                $context,
                0.0,
                0.0,
                $input,
                ['text'],
                $parameters,
                null,
                0.0,
                0.0,
                reasoningMandatory: $thinking === 'only',
            );
        }

        return $models;
    }
}
