<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\StreamEnd;

/**
 * Calls the real OpenRouter API. Runs only with OPENROUTER_API_KEY set; costs a fraction of a cent.
 * Model can be overridden with OPENROUTER_TEST_MODEL.
 *
 * @group integration
 */
final class OpenRouterLiveTest extends AsyncTestCase
{
    private function client(): OpenRouterClient
    {
        $key = \getenv('OPENROUTER_API_KEY');
        if (!\is_string($key) || $key === '') {
            self::markTestSkipped('OPENROUTER_API_KEY is not set');
        }

        return new OpenRouterClient(HttpClientBuilder::buildDefault(), $key, appName: 'mercurius-llm tests');
    }

    private static function request(): ChatRequest
    {
        $model = \getenv('OPENROUTER_TEST_MODEL');

        return new ChatRequest(
            \is_string($model) && $model !== '' ? $model : 'openai/gpt-4o-mini',
            [Message::user('Reply with the single word: pong')],
            maxTokens: 16,
        );
    }

    public function testComplete(): void
    {
        $response = $this->client()->complete(self::request());

        self::assertNotSame('', $response->text);
        self::assertGreaterThan(0, $response->usage->outputTokens);
        self::assertNotSame('', $response->id);
    }

    public function testStream(): void
    {
        $last = null;
        foreach ($this->client()->stream(self::request()) as $event) {
            $last = $event;
        }

        self::assertInstanceOf(StreamEnd::class, $last);
        self::assertNotSame('', $last->response->text);
        self::assertGreaterThan(0, $last->response->usage->promptTokens());
    }
}
