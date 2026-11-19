<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\ImageRequest;
use Mercurius\Llm\ImageResult;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Usage;

/**
 * Pictures from the image models of OpenRouter (`POST /api/v1/images`). The pictures come back as base64 in the
 * response (`response_format: b64_json`), not as links that expire; OpenRouter reports the cost.
 */
final class ImageClient
{
    private const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = OpenRouterClient::DEFAULT_BASE_URL,
        ?string $appName = null,
        ?string $appUrl = null,
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 180.0,
        float $transferTimeout = 300.0,
        int $maxResponseBytes = 64 * 1024 * 1024,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenRouter API key must not be empty');
        }
        $this->transport = new HttpTransport($http, $apiKey, $baseUrl, $appName, $appUrl, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes);
    }

    /**
     * @throws LlmException When the request fails or no picture comes back.
     */
    public function generate(ImageRequest $request, ?Cancellation $cancellation = null): ImageResult
    {
        $payload = ['model' => $request->model, 'prompt' => $request->prompt, 'n' => $request->count, 'response_format' => 'b64_json'];
        if ($request->aspectRatio !== null) {
            $payload['aspect_ratio'] = $request->aspectRatio;
        }
        if ($request->resolution !== null) {
            $payload['resolution'] = $request->resolution;
        }
        $data = $this->transport->decodeJson($this->transport->send('POST', '/images', $payload, 'application/json', $cancellation), $cancellation);

        $images = [];
        foreach (Json::objects($data, 'data') as $entry) {
            $bytes = \base64_decode(Json::string($entry, 'b64_json') ?? '', true);
            if ($bytes === false || $bytes === '') {
                continue; // a link or a broken part: only pictures in the response are taken
            }
            $mime = Json::string($entry, 'media_type');
            $images[] = MediaPart::image($mime !== null && \in_array($mime, self::MIMES, true) ? $mime : 'image/png', $bytes);
        }
        if ($images === []) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: the image model returned no picture');
        }
        $usage = Json::object($data, 'usage');

        return new ImageResult(
            $images,
            new Usage(
                Json::int($usage, 'prompt_tokens') ?? 0,
                Json::int($usage, 'completion_tokens') ?? 0,
                costUsd: Json::float($usage, 'cost'),
            ),
            $request->model,
        );
    }
}
