<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;

/**
 * Sends requests to the OpenRouter API: authentication and attribution headers, timeouts, size limits and
 * classification of failed responses. The body of a failed response is always consumed.
 *
 * @internal
 */
final class HttpTransport
{
    /** Error bodies are small; reading more would only keep the connection busy. */
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter]
        private readonly ?string $apiKey,
        string $baseUrl,
        private readonly ?string $appName,
        private readonly ?string $appUrl,
        private readonly float $connectTimeout,
        private readonly float $inactivityTimeout,
        private readonly float $transferTimeout,
        public readonly int $maxResponseBytes,
        /** @var array<non-empty-string, string> Extra headers of every request, e.g. a key in a provider's own header. */
        #[\SensitiveParameter]
        private readonly array $headers = [],
    ) {
        $this->baseUrl = \rtrim($baseUrl, '/');
    }

    /**
     * Sends a request and returns the response if its status is 2xx.
     *
     * @param 'GET'|'POST' $method
     * @param array<string, mixed>|null $json Request body, encoded as JSON.
     * @throws LlmException
     */
    public function send(string $method, string $path, ?array $json, string $accept, ?Cancellation $cancellation): Response
    {
        $request = new Request($this->baseUrl . $path, $method);
        $request->setHeader('accept', $accept);
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request->setHeader('authorization', 'Bearer ' . $this->apiKey);
        }
        if ($this->appName !== null && $this->appName !== '') {
            $request->setHeader('x-title', $this->appName);
        }
        if ($this->appUrl !== null && $this->appUrl !== '') {
            $request->setHeader('http-referer', $this->appUrl);
        }
        foreach ($this->headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        if ($json !== null) {
            try {
                $request->setBody(\json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
            } catch (\JsonException $e) {
                throw new LlmException(ErrorKind::BadRequest, 'Request cannot be encoded as JSON: ' . $e->getMessage(), null, null, $e);
            }
            $request->setHeader('content-type', 'application/json');
        }
        $request->setTcpConnectTimeout($this->connectTimeout);
        $request->setTlsHandshakeTimeout($this->connectTimeout);
        $request->setInactivityTimeout($this->inactivityTimeout);
        $request->setTransferTimeout($this->transferTimeout);
        $request->setBodySizeLimit($this->maxResponseBytes);

        try {
            $response = $this->http->request($request, $cancellation);
        } catch (HttpException $e) {
            throw ErrorClassifier::fromHttpException($e);
        }

        $status = $response->getStatus();
        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $body = $response->getBody();
        try {
            $text = $body->buffer($cancellation, self::MAX_ERROR_BODY_BYTES);
        } catch (BufferException $e) {
            $text = $e->getBuffer();
        } catch (StreamException) {
            $text = '';
        } finally {
            $body->close();
        }

        throw ErrorClassifier::fromHttpResponse($status, $text, $response->getHeader('retry-after'), \microtime(true), [
            'request-id' => $response->getHeader('request-id'),
            'x-request-id' => $response->getHeader('x-request-id'),
            'anthropic-ratelimit-unified-status' => $response->getHeader('anthropic-ratelimit-unified-status'),
            'anthropic-ratelimit-unified-reset' => $response->getHeader('anthropic-ratelimit-unified-reset'),
        ]);
    }

    /**
     * Reads the whole body of a successful response and decodes it as a JSON object.
     *
     * @return array<array-key, mixed>
     * @throws LlmException
     */
    public function decodeJson(Response $response, ?Cancellation $cancellation): array
    {
        $body = $response->getBody();
        try {
            $text = $body->buffer($cancellation, $this->maxResponseBytes);
        } catch (BufferException) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: response exceeds the size limit');
        } catch (StreamException $e) {
            throw ErrorClassifier::fromStreamException($e);
        } finally {
            $body->close();
        }

        return self::decode($text);
    }

    /**
     * @return array<array-key, mixed>
     * @throws LlmException
     */
    public static function decode(string $text): array
    {
        try {
            $decoded = \json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: malformed JSON in response', null, null, $e);
        }
        if (!\is_array($decoded)) {
            throw new LlmException(ErrorKind::Unknown, 'OpenRouter: response is not a JSON object');
        }

        return $decoded;
    }
}
