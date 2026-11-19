<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\OpenAICatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\Test\Support\FakeHttp;

final class OpenAICatalogTest extends AsyncTestCase
{
    public function testModelsOfTheKeyGetPricesFromTheReference(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"object":"list","data":[{"id":"gpt-6-luna","object":"model","owned_by":"openai"},'
                . '{"id":"text-embedding-4","object":"model","owned_by":"openai"},{"id":"gpt-6.1-sol","object":"model","owned_by":"openai"},'
                . '{"id":"gpt-6-luna-pro","object":"model"},{"id":"gpt-6.1-codex","object":"model"}]}')
            ->respond(200, '{"data":['
                . '{"id":"openai/gpt-6-luna","name":"OpenAI: GPT-6 Luna","context_length":1050000,'
                . '"architecture":{"input_modalities":["text","image"],"output_modalities":["text"]},'
                . '"pricing":{"prompt":"0.0000001","completion":"0.0000005"},"supported_parameters":["tools","max_completion_tokens"],'
                . '"top_provider":{"max_completion_tokens":128000},'
                . '"benchmarks":{"artificial_analysis":{"intelligence_index":27.9,"coding_index":null,"agentic_index":null}}},'
                . '{"id":"openai/gpt-6-luna:batch","name":"batch","context_length":1050000,"pricing":{"prompt":"0.00000005","completion":"0.00000025"}},'
                . '{"id":"openai/gpt-6.1-sol","name":"OpenAI: GPT-6.1 Sol","context_length":1050000,'
                . '"pricing":{"prompt":"0.000002","completion":"0.00001"},"supported_parameters":["tools","reasoning"],"reasoning":{"mandatory":true}},'
                . '{"id":"openai/gpt-6-luna-pro","name":"pro","context_length":1050000,"pricing":{"prompt":"0.0000001","completion":"0.0000005"},"supported_parameters":["tools"]},'
                . '{"id":"openai/gpt-6.1-codex","name":"codex","context_length":1050000,"pricing":{"prompt":"0.000001","completion":"0.000005"},"supported_parameters":["tools"]},'
                . '{"id":"anthropic/claude-opus-5","name":"Claude","context_length":1000000,"pricing":{"prompt":"0.000005","completion":"0.000025"}}'
                . ']}');
        $client = $http->client();
        $catalog = new OpenAICatalog($client, 'sk-proj-secret', new OpenRouterCatalog($client));

        $models = $catalog->fetch();

        self::assertSame('https://api.openai.com/v1/models', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer sk-proj-secret', $http->requests[0]->getHeader('authorization'));
        self::assertNull($http->requests[1]->getHeader('authorization'), 'the reference catalog is public');
        self::assertSame(['gpt-6-luna', 'gpt-6.1-sol'], \array_map(static fn($m) => $m->id, $models), 'only chat models known to the reference; Responses-only families are left out');
        $luna = $models[0];
        self::assertSame('OpenAI: GPT-6 Luna', $luna->name);
        self::assertSame(1050000, $luna->contextLength);
        self::assertSame(0.0000001, $luna->inputPricePerToken);
        self::assertSame(['text', 'image'], $luna->inputModalities);
        self::assertTrue($luna->supportsTools());
        self::assertSame(27.9, $luna->intelligenceIndex);
        self::assertSame(128000, $luna->maxCompletionTokens);
        self::assertFalse($models[1]->supportsTools(), 'always reasoning: no tools through Chat Completions');
        self::assertTrue($models[1]->reasoningMandatory);
    }

    public function testTheResponsesApiKeepsTheCodexFamilyAndToolsOfModelsThatAlwaysReason(): void
    {
        $http = (new FakeHttp())
            ->respond(200, '{"data":[{"id":"gpt-6.1-codex"},{"id":"gpt-6.1-sol"},{"id":"gpt-6-tts"}]}')
            ->respond(200, '{"data":['
                . '{"id":"openai/gpt-6.1-codex","name":"codex","context_length":1000,"pricing":{"prompt":"0.000001","completion":"0.000005"},"supported_parameters":["tools"]},'
                . '{"id":"openai/gpt-6.1-sol","name":"sol","context_length":1000,"pricing":{"prompt":"0.000002","completion":"0.00001"},"supported_parameters":["tools","reasoning"],"reasoning":{"mandatory":true}},'
                . '{"id":"openai/gpt-6-tts","name":"tts","context_length":1000,"pricing":{"prompt":"0.000001","completion":"0.000001"}}'
                . ']}');
        $client = $http->client();

        $models = (new OpenAICatalog($client, 'sk-proj', new OpenRouterCatalog($client), responses: true))->fetch();

        self::assertSame(['gpt-6.1-codex', 'gpt-6.1-sol'], \array_map(static fn($m) => $m->id, $models));
        self::assertTrue($models[1]->supportsTools());
    }
}
