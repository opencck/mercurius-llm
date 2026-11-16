<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\Test\Support\FakeHttp;
use Mercurius\Llm\Usage;

final class OpenRouterCatalogTest extends AsyncTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixture(): iterable
    {
        $json = \file_get_contents(__DIR__ . '/../Fixtures/models.json');
        if ($json === false) {
            throw new \RuntimeException('Fixture is missing');
        }

        yield 'models.json' => [$json];
    }

    /**
     * @dataProvider fixture
     */
    public function testFetchParsesModels(string $json): void
    {
        $http = (new FakeHttp())->respond(200, $json);
        $catalog = new OpenRouterCatalog($http->client(), 'sk-key');
        $models = $catalog->fetch();

        $sent = $http->requests[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://openrouter.ai/api/v1/models', (string) $sent->getUri());
        self::assertSame('Bearer sk-key', $sent->getHeader('authorization'));

        self::assertCount(2, $models);
        $sonnet = $models[0];
        self::assertSame('anthropic/claude-sonnet-4', $sonnet->id);
        self::assertSame('Anthropic: Claude Sonnet 4', $sonnet->name);
        self::assertSame(1000000, $sonnet->contextLength);
        self::assertSame(0.000003, $sonnet->inputPricePerToken);
        self::assertSame(0.000015, $sonnet->outputPricePerToken);
        self::assertSame(0.0000003, $sonnet->cacheReadPricePerToken);
        self::assertSame(['image', 'text', 'file'], $sonnet->inputModalities);
        self::assertSame(['text'], $sonnet->outputModalities);
        self::assertSame(64000, $sonnet->maxCompletionTokens);
        self::assertTrue($sonnet->supportsTools());
        self::assertTrue($sonnet->supports('reasoning'));
        self::assertFalse($sonnet->supports('logprobs'));

        $cost = $sonnet->estimateCost(new Usage(inputTokens: 1000, outputTokens: 100, cacheReadTokens: 10000));
        self::assertNotNull($cost);
        self::assertEqualsWithDelta(0.003 + 0.0015 + 0.003, $cost, 1e-12);

        self::assertSame(44.2, $sonnet->intelligenceIndex);
        self::assertSame(78.0, $sonnet->codingIndex);
        self::assertSame(56.5, $sonnet->agenticIndex);

        $auto = $models[1];
        self::assertNull($auto->intelligenceIndex, 'no benchmarks published');
        self::assertNull($auto->inputPricePerToken, 'negative price means variable');
        self::assertNull($auto->maxCompletionTokens);
        self::assertNull($auto->estimateCost(new Usage(1, 1)));
        self::assertSame([], $auto->supportedParameters);
    }

    public function testWorksWithoutKey(): void
    {
        $http = (new FakeHttp())->respond(200, '{"data":[]}');
        self::assertSame([], (new OpenRouterCatalog($http->client()))->fetch());
        self::assertNull($http->requests[0]->getHeader('authorization'));
    }

    public function testErrorsAreClassified(): void
    {
        $http = (new FakeHttp())->respond(503, '');

        try {
            (new OpenRouterCatalog($http->client()))->fetch();
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
        }
    }
}
