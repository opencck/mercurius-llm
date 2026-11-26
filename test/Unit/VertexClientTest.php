<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\Gemini\VertexAccount;
use Mercurius\Llm\Gemini\VertexClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Test\Support\FakeHttp;

use function Amp\async;
use function Amp\Future\await;

final class VertexClientTest extends AsyncTestCase
{
    private const OK_BODY = '{"id":"c1","model":"google/gemini-3.8-flash","choices":[{"index":0,"finish_reason":"stop",'
        . '"message":{"role":"assistant","content":"Hello"}}],"usage":{"prompt_tokens":5,"completion_tokens":1}}';

    private static ?string $pem = null;

    private static ?string $public = null;

    private static function credentials(string $project = 'my-project-1'): string
    {
        if (self::$pem === null) {
            // a key made for these tests only; it opens nothing
            $pem = \file_get_contents(__DIR__ . '/../Fixtures/test-only-rsa.pem');
            self::assertIsString($pem);
            $key = \openssl_pkey_get_private($pem);
            self::assertNotFalse($key);
            self::$pem = $pem;
            $details = \openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertIsString($details['key']);
            self::$public = $details['key'];
        }

        return \json_encode([
            'type' => 'service_account',
            'project_id' => $project,
            'client_email' => 'agent@my-project-1.iam.gserviceaccount.com',
            'private_key' => self::$pem,
        ], \JSON_THROW_ON_ERROR);
    }

    private static function decodePart(string $part): string
    {
        $bytes = \base64_decode(\strtr($part, '-_', '+/'), true);
        self::assertIsString($bytes);

        return $bytes;
    }

    public function testASignedAssertionIsExchangedAndTheTokenGoesWithTheRequest(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"access_token":"ya29.token-1","expires_in":3600,"token_type":"Bearer"}')
            ->respond(200, self::OK_BODY);
        $client = $http->client();
        $account = VertexAccount::fromJson($client, self::credentials(), clock: static fn (): int => 1_800_000_000);

        $response = (new VertexClient($client, $account, region: 'us-central1'))
            ->complete(new ChatRequest('google/gemini-3.8-flash', [Message::user('Hi')], reasoningEffort: 'high'));

        self::assertSame('https://oauth2.googleapis.com/token', (string) $http->requests[0]->getUri());
        \parse_str($http->bodies[0], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        self::assertIsString($form['assertion']);
        [$header, $claims, $signature] = \explode('.', $form['assertion']);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], \json_decode(self::decodePart($header), true, 4, \JSON_THROW_ON_ERROR));
        self::assertSame([
            'iss' => 'agent@my-project-1.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => 1_800_000_000,
            'exp' => 1_800_003_600,
        ], \json_decode(self::decodePart($claims), true, 4, \JSON_THROW_ON_ERROR));
        self::assertSame(1, \openssl_verify($header . '.' . $claims, self::decodePart($signature), (string) self::$public, \OPENSSL_ALGO_SHA256));

        self::assertSame(
            'https://us-central1-aiplatform.googleapis.com/v1beta1/projects/my-project-1/locations/us-central1/endpoints/openapi/chat/completions',
            (string) $http->requests[1]->getUri(),
        );
        self::assertSame('Bearer ya29.token-1', $http->requests[1]->getHeader('authorization'));
        self::assertSame(['google' => ['thinking_config' => ['include_thoughts' => false, 'thinking_level' => 'high']]], $http->lastPayload()['extra_body']);
        self::assertSame('Hello', $response->text);
    }

    public function testTheAddressOfTheGlobalRegionHasNoPrefixAndBadPartsAreRefused(): void
    {
        self::assertSame(
            'https://aiplatform.googleapis.com/v1beta1/projects/my-project-1/locations/global/endpoints/openapi',
            VertexClient::baseUrl('my-project-1'),
        );
        self::assertStringStartsWith('https://europe-west4-aiplatform.googleapis.com/', VertexClient::baseUrl('my-project-1', 'europe-west4'));
        $refused = 0;
        foreach ([['my-project-1', 'evil.example/x'], ['x/../y', 'global'], ['', 'global']] as [$project, $region]) {
            try {
                VertexClient::baseUrl($project, $region);
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(3, $refused);
    }

    public function testATokenIsKeptUntilFiveMinutesBeforeItExpires(): void
    {
        $now = 1_800_000_000;
        $http = (new FakeHttp())
            ->respond(200, '{"access_token":"ya29.token-1","expires_in":3600}')
            ->respond(200, '{"access_token":"ya29.token-2","expires_in":3600}');
        $account = VertexAccount::fromJson($http->client(), self::credentials(), clock: static function () use (&$now): int {
            return $now;
        });

        $tokens = [$account->token()];
        $now += 3299;
        $tokens[] = $account->token();
        $now += 2;
        $tokens[] = $account->token();
        self::assertSame(['ya29.token-1', 'ya29.token-1', 'ya29.token-2'], $tokens);
        self::assertCount(2, $http->requests);
    }

    public function testConcurrentCallersShareOneExchange(): void
    {
        $http = (new FakeHttp())->respond(200, '{"access_token":"ya29.token-1","expires_in":3600}');
        $account = VertexAccount::fromJson($http->client(), self::credentials());

        $tokens = await([async($account->token(...)), async($account->token(...)), async($account->token(...))]);

        self::assertSame(['ya29.token-1', 'ya29.token-1', 'ya29.token-1'], $tokens);
        self::assertCount(1, $http->requests);
    }

    public function testARefusedExchangeIsAnAuthErrorWithoutTheKey(): void
    {
        $http = (new FakeHttp())->respond(400, '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}');
        $account = VertexAccount::fromJson($http->client(), self::credentials());

        try {
            $account->token();
            self::fail('expected an error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertSame('Vertex (400): token exchange refused: invalid_grant: Invalid JWT Signature.', $e->getMessage());
            self::assertFalse($e->retryable());
        }
    }

    public function testCredentialsThatAreNotAServiceAccountAreRefusedWithoutBeingRepeated(): void
    {
        $http = (new FakeHttp())->client();
        foreach (['not json SECRET', '{"type":"authorized_user","refresh_token":"SECRET"}', '{"type":"service_account","client_email":"a@b","private_key":"SECRET"}'] as $json) {
            try {
                VertexAccount::fromJson($http, $json);
                self::fail('expected a refusal');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
    }
}
