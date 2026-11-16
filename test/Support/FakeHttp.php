<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Support;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

/**
 * Fake transport: returns queued canned responses and records the requests it received.
 */
final class FakeHttp implements DelegateHttpClient
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<string> Request bodies, in order. */
    public array $bodies = [];

    /** @var list<ReadableIterableStream> Streamed response bodies, to check that they were closed. */
    public array $streams = [];

    /** @var list<\Closure(Request): Response> */
    private array $queue = [];

    public function client(): HttpClient
    {
        return new HttpClient($this, []);
    }

    /**
     * @param string|list<string> $body A list is streamed chunk by chunk.
     * @param array<non-empty-string, string> $headers
     */
    public function respond(int $status, string|array $body, array $headers = []): self
    {
        $this->queue[] = function (Request $request) use ($status, $body, $headers): Response {
            $stream = \is_array($body) ? new ReadableIterableStream($body) : $body;
            if ($stream instanceof ReadableIterableStream) {
                $this->streams[] = $stream;
            }

            return new Response('1.1', $status, null, $headers, $stream, $request);
        };

        return $this;
    }

    public function fail(HttpException $exception): self
    {
        $this->queue[] = static function () use ($exception): never {
            throw $exception;
        };

        return $this;
    }

    #[\Override]
    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = $request;
        $this->bodies[] = \Amp\ByteStream\buffer($request->getBody()->getContent());
        $next = \array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('No response queued');
        }

        return $next($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function lastPayload(): array
    {
        $body = \end($this->bodies);
        if ($body === false) {
            throw new \LogicException('No request was sent');
        }
        $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \LogicException('Request body is not a JSON object');
        }

        return $decoded;
    }
}
