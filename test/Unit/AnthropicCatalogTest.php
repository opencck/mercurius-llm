<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\AnthropicCatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\Test\Support\FakeHttp;

final class AnthropicCatalogTest extends AsyncTestCase
{
    public function testModelsOfTheKeyAreFoundInTheReferenceByTheirDottedVersion(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"data":[{"type":"model","id":"claude-opus-5-5","display_name":"Claude Opus 5.5"},'
                . '{"type":"model","id":"claude-haiku-4-5-20251001","display_name":"Claude Haiku 4.5"},'
                . '{"type":"model","id":"claude-unknown-9","display_name":"?"}],"has_more":false}')
            ->respond(200, '{"data":['
                . '{"id":"anthropic/claude-opus-5.5","name":"Anthropic: Claude Opus 5.5","context_length":1000000,'
                . '"architecture":{"input_modalities":["text","image","file"],"output_modalities":["text"]},'
                . '"pricing":{"prompt":"0.000004","completion":"0.00002","input_cache_read":"0.0000002"},"supported_parameters":["tools","reasoning"],'
                . '"reasoning":{"mandatory":true}},'
                . '{"id":"anthropic/claude-haiku-4.5","name":"Anthropic: Claude Haiku 4.5","context_length":200000,'
                . '"pricing":{"prompt":"0.000001","completion":"0.000005"},"supported_parameters":["tools"]}'
                . ']}');
        $client = $http->client();

        $models = (new AnthropicCatalog($client, 'sk-ant', new OpenRouterCatalog($client)))->fetch();

        self::assertSame('https://api.anthropic.com/v1/models?limit=1000', (string) $http->requests[0]->getUri());
        self::assertSame('sk-ant', $http->requests[0]->getHeader('x-api-key'));
        self::assertNotNull($http->requests[0]->getHeader('anthropic-version'));
        self::assertSame(['claude-opus-5-5', 'claude-haiku-4-5-20251001'], \array_map(static fn($m) => $m->id, $models), 'a model the reference does not know is left out');
        self::assertSame('Anthropic: Claude Opus 5.5', $models[0]->name);
        self::assertTrue($models[0]->supportsTools(), 'the Messages API takes tools with thinking on');
        self::assertSame(0.0000002, $models[0]->cacheReadPricePerToken);
        self::assertSame(200000, $models[1]->contextLength);
    }
}
