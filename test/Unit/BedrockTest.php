<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Anthropic\AnthropicClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\Message;
use Mercurius\Llm\ModelCatalog\BedrockCatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\Test\Support\FakeHttp;

final class BedrockTest extends AsyncTestCase
{
    public function testClaudeInBedrockIsTheMessagesApiAtTheMantleAddressOfTheRegion(): void
    {
        $http = (new FakeHttp())->respond(200, '{"id":"msg_1","model":"anthropic.claude-opus-5-5","content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":3,"output_tokens":1}}');

        $response = AnthropicClient::bedrock($http->client(), 'bedrock-token', 'eu-central-1')
            ->complete(new ChatRequest('anthropic.claude-opus-5-5', [Message::user('x')]));

        $request = $http->requests[0];
        self::assertSame('https://bedrock-mantle.eu-central-1.api.aws/anthropic/v1/messages', (string) $request->getUri());
        self::assertSame('bedrock-token', $request->getHeader('x-api-key'));
        self::assertSame('anthropic.claude-opus-5-5', $http->lastPayload()['model']);
        self::assertSame('ok', $response->text);
    }

    public function testARegionThatIsNotARegionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AnthropicClient::bedrock((new FakeHttp())->client(), 'bedrock-token', 'evil.example/x');
    }

    public function testTheCatalogIsTheClaudeModelsOfTheReferenceInBedrockSpelling(): void
    {
        $http = (new FakeHttp())->respond(200, '{"data":['
            . '{"id":"anthropic/claude-opus-5.5","name":"Anthropic: Claude Opus 5.5","context_length":1000000,"pricing":{"prompt":"0.000004","completion":"0.00002"},"supported_parameters":["tools"]},'
            . '{"id":"anthropic/claude-opus-5.5:batch","name":"batch","context_length":1000000,"pricing":{"prompt":"0.000002","completion":"0.00001"}},'
            . '{"id":"anthropic/claude-sonnet-5","name":"Anthropic: Claude Sonnet 5","context_length":1000000,"pricing":{"prompt":"0.000003","completion":"0.000015"}},'
            . '{"id":"openai/gpt-6-luna","name":"GPT","context_length":1000,"pricing":{"prompt":"0.0000001","completion":"0.0000005"}}'
            . ']}');

        $models = (new BedrockCatalog(new OpenRouterCatalog($http->client())))->fetch();

        self::assertSame(['anthropic.claude-opus-5-5', 'anthropic.claude-sonnet-5'], \array_map(static fn($m) => $m->id, $models));
        self::assertSame('Anthropic: Claude Opus 5.5', $models[0]->name);
        self::assertTrue($models[0]->supportsTools());
    }
}
