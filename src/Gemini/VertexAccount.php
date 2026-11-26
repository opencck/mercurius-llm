<?php

declare(strict_types=1);

namespace Mercurius\Llm\Gemini;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\Json;

use function Amp\async;

/**
 * A service account of Google Cloud: signs a JWT with the account's key (RS256) and exchanges it for an access token
 * that lives an hour. The token is kept until five minutes before it expires; concurrent callers share one exchange.
 * Neither the key nor the token ever gets into a message of an exception.
 *
 * spec: packages/llm/BRIEF.md#gemini-и-vertex
 *
 * ref: references/hermes-agent/agent/vertex_adapter.py@e96221f:L53-L54 — the `cloud-platform` scope
 * ref: references/hermes-agent/agent/vertex_adapter.py@e96221f:L138-L144 (_needs_refresh) — a token is renewed five
 *      minutes before it expires. Hermes leaves the exchange to the blocking `google-auth` library; here the JWT is
 *      signed with OpenSSL and exchanged by the async HTTP client. Application Default Credentials are not read.
 */
final class VertexAccount
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const REFRESH_BEFORE = 300;

    /** @var non-empty-string|null */
    private ?string $token = null;

    private int $expiresAt = 0;

    /** @var Future<non-empty-string>|null */
    private ?Future $exchange = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock unix time, for tests
     */
    private function __construct(
        private readonly HttpClient $http,
        public readonly string $clientEmail,
        public readonly string $projectId,
        #[\SensitiveParameter]
        private readonly \OpenSSLAsymmetricKey $key,
        private readonly string $tokenUrl,
        private readonly float $timeout,
        ?\Closure $clock,
    ) {
        $this->clock = $clock ?? \time(...);
    }

    /**
     * @param string $json the key file of a service account, as Google Cloud gives it
     * @param (\Closure(): int)|null $clock
     * @throws \InvalidArgumentException when the JSON is not a key of a service account; the message never repeats it
     */
    public static function fromJson(
        HttpClient $http,
        #[\SensitiveParameter]
        string $json,
        string $tokenUrl = self::TOKEN_URL,
        float $timeout = 30.0,
        ?\Closure $clock = null,
    ): self {
        try {
            /** @var mixed $data */
            $data = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Vertex credentials are not valid JSON');
        }
        if (!\is_array($data) || ($data['type'] ?? null) !== 'service_account') {
            throw new \InvalidArgumentException('Vertex credentials are not a service account key (type must be "service_account")');
        }
        $email = $data['client_email'] ?? null;
        $pem = $data['private_key'] ?? null;
        $project = $data['project_id'] ?? '';
        if (!\is_string($email) || $email === '' || !\is_string($pem) || $pem === '' || !\is_string($project)) {
            throw new \InvalidArgumentException('Vertex credentials lack client_email or private_key');
        }
        $key = \openssl_pkey_get_private($pem);
        // the queue of OpenSSL errors may quote the key material: it is dropped, never reported
        while (\openssl_error_string() !== false) {
        }
        if ($key === false) {
            throw new \InvalidArgumentException('Vertex credentials: private_key is not a readable private key');
        }

        return new self($http, $email, $project, $key, $tokenUrl, $timeout, $clock);
    }

    /**
     * An access token good for at least five more minutes.
     *
     * @return non-empty-string
     * @throws LlmException
     */
    public function token(?Cancellation $cancellation = null): string
    {
        $token = $this->token;
        if ($token !== null && ($this->clock)() < $this->expiresAt - self::REFRESH_BEFORE) {
            return $token;
        }

        return $this->shared()->await($cancellation);
    }

    /**
     * One exchange for everyone who waits; it does not die with the caller that happened to start it.
     *
     * @return Future<non-empty-string>
     */
    private function shared(): Future
    {
        return $this->exchange ??= async(function (): string {
            try {
                return $this->exchange();
            } finally {
                $this->exchange = null;
            }
        });
    }

    /**
     * @return non-empty-string
     * @throws LlmException
     */
    private function exchange(): string
    {
        $now = ($this->clock)();
        $request = new Request($this->tokenUrl, 'POST');
        $request->setHeader('content-type', 'application/x-www-form-urlencoded');
        $request->setHeader('accept', 'application/json');
        $request->setBody(\http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion($now),
        ]));
        $request->setTcpConnectTimeout(\min(10.0, $this->timeout));
        $request->setTlsHandshakeTimeout(\min(10.0, $this->timeout));
        $request->setInactivityTimeout($this->timeout);
        $request->setTransferTimeout($this->timeout);
        $request->setBodySizeLimit(64 * 1024);

        $cancellation = new TimeoutCancellation($this->timeout + 5.0);
        try {
            $response = $this->http->request($request, $cancellation);
            $status = $response->getStatus();
            $body = $response->getBody()->buffer($cancellation, 64 * 1024);
        } catch (HttpException | StreamException | BufferException $e) {
            throw new LlmException(ErrorKind::Network, 'Vertex: token exchange failed: ' . $e::class);
        } catch (\Amp\CancelledException) {
            throw new LlmException(ErrorKind::Timeout, 'Vertex: token exchange timed out');
        }

        try {
            /** @var mixed $data */
            $data = \json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $data = \is_array($data) ? $data : [];
        if ($status < 200 || $status >= 300) {
            // Google answers {"error": "invalid_grant", "error_description": "…"}: neither holds the key or a token
            $error = Json::string($data, 'error') ?? 'HTTP ' . $status;
            $description = Json::string($data, 'error_description');
            $description = $description === null ? '' : ': ' . \mb_strcut($description, 0, 300, 'UTF-8');

            throw new LlmException(
                $status >= 500 ? ErrorKind::Server : ErrorKind::Auth,
                \sprintf('Vertex (%d): token exchange refused: %s%s', $status, $error, $description),
                $status,
            );
        }
        $token = Json::string($data, 'access_token');
        $lifetime = Json::int($data, 'expires_in');
        if ($token === null || $token === '' || $lifetime === null || $lifetime <= 0) {
            throw new LlmException(ErrorKind::Unknown, 'Vertex: token exchange answered without a token');
        }
        $this->token = $token;
        $this->expiresAt = $now + $lifetime;

        return $token;
    }

    /**
     * @throws LlmException
     */
    private function assertion(int $now): string
    {
        $unsigned = self::base64Url('{"alg":"RS256","typ":"JWT"}') . '.' . self::base64Url(\json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $signature = null;
        $signed = \openssl_sign($unsigned, $signature, $this->key, \OPENSSL_ALGO_SHA256);
        $bytes = $signed ? self::bytes($signature) : null;
        if ($bytes === null) {
            while (\openssl_error_string() !== false) {
            }
            throw new LlmException(ErrorKind::Auth, 'Vertex: the service account key cannot sign (RS256)');
        }

        return $unsigned . '.' . self::base64Url($bytes);
    }

    private static function bytes(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function base64Url(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }
}
