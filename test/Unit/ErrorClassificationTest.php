<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\Http\Client\TimeoutException;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\ErrorClassifier;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\Test\Support\FakeHttp;

final class ErrorClassificationTest extends AsyncTestCase
{
    /**
     * @return iterable<string, array{int, string, ErrorKind, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'bad request' => [400, '{"error":{"code":400,"message":"model is required"}}', ErrorKind::BadRequest, false];
        yield 'context length' => [400, '{"error":{"code":400,"message":"This endpoint\'s maximum context length is 8192 tokens"}}', ErrorKind::ContextLength, false];
        yield 'payload too large' => [413, '', ErrorKind::ContextLength, false];
        yield 'auth' => [401, '{"error":{"code":401,"message":"No auth credentials found"}}', ErrorKind::Auth, false];
        yield 'credits' => [402, '{"error":{"code":402,"message":"Insufficient credits"}}', ErrorKind::InsufficientCredits, false];
        yield 'moderation' => [403, '{"error":{"code":403,"message":"Input was flagged by moderation","metadata":{"reasons":["x"]}}}', ErrorKind::ContentFilter, false];
        yield 'forbidden' => [403, '{"error":{"code":403,"message":"Key disabled"}}', ErrorKind::Auth, false];
        yield 'not found' => [404, '{"error":{"code":404,"message":"No endpoints found"}}', ErrorKind::BadRequest, false];
        yield 'timeout' => [408, '', ErrorKind::Timeout, true];
        yield 'rate limit' => [429, '{"error":{"code":429,"message":"Rate limit exceeded"}}', ErrorKind::RateLimited, true];
        yield 'internal' => [500, '', ErrorKind::Server, true];
        yield 'bad gateway' => [502, '{"error":{"code":502,"message":"Provider returned error"}}', ErrorKind::Server, true];
        yield 'unavailable' => [503, '<html>Service Unavailable</html>', ErrorKind::Server, true];
        yield 'overloaded' => [529, '', ErrorKind::Server, true];
    }

    /**
     * @dataProvider statuses
     */
    public function testStatusMapping(int $status, string $body, ErrorKind $kind, bool $retryable): void
    {
        $http = (new FakeHttp())->respond($status, $body);
        $client = new OpenRouterClient($http->client(), 'sk-very-secret-key');

        try {
            $client->complete(new ChatRequest('m/x', [Message::user('my private prompt')]));
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($status, $e->status);
            self::assertSame($retryable, $e->retryable());
            self::assertStringNotContainsString('sk-very-secret-key', $e->getMessage());
            self::assertStringNotContainsString('my private prompt', $e->getMessage());
            self::assertStringNotContainsString('<html>', $e->getMessage());
        }
    }

    public function testRetryAfterSeconds(): void
    {
        $e = ErrorClassifier::fromHttpResponse(429, '', '12', 1000.0);
        self::assertSame(12.0, $e->retryAfter);
    }

    public function testRetryAfterHttpDate(): void
    {
        $now = (float) \gmmktime(12, 0, 0, 11, 16, 2026);
        $e = ErrorClassifier::fromHttpResponse(503, '', 'Mon, 16 Nov 2026 12:00:30 GMT', $now);
        self::assertSame(30.0, $e->retryAfter);
    }

    public function testRetryAfterInvalidOrPast(): void
    {
        self::assertNull(ErrorClassifier::fromHttpResponse(429, '', 'soon', 0.0)->retryAfter);
        self::assertNull(ErrorClassifier::fromHttpResponse(429, '', null, 0.0)->retryAfter);
        $now = (float) \gmmktime(12, 0, 0, 11, 16, 2026);
        self::assertSame(0.0, ErrorClassifier::fromHttpResponse(429, '', 'Mon, 16 Nov 2026 11:00:00 GMT', $now)->retryAfter);
    }

    public function testLongMessagesAreTruncated(): void
    {
        $body = \json_encode(['error' => ['code' => 400, 'message' => \str_repeat('a', 5000)]], \JSON_THROW_ON_ERROR);
        $e = ErrorClassifier::fromHttpResponse(400, $body, null, 0.0);
        self::assertLessThan(600, \strlen($e->getMessage()));
    }

    public function testErrorObjectWithoutNumericCode(): void
    {
        $e = ErrorClassifier::fromErrorObject(['code' => 'server_error', 'message' => 'boom']);
        self::assertSame(ErrorKind::Unknown, $e->kind);
        self::assertNull($e->status);

        $e = ErrorClassifier::fromErrorObject(['message' => 'prompt is too long']);
        self::assertSame(ErrorKind::ContextLength, $e->kind);
    }

    public function testTransportTimeout(): void
    {
        $e = ErrorClassifier::fromHttpException(new TimeoutException('Inactivity timeout'));
        self::assertSame(ErrorKind::Timeout, $e->kind);
        self::assertTrue($e->retryable());
    }
}
