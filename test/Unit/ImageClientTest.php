<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\ImageRequest;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\ImageClient;
use Mercurius\Llm\Test\Support\FakeHttp;

final class ImageClientTest extends AsyncTestCase
{
    public function testAPictureIsAskedForAndComesBackAsBytesWithItsCost(): void
    {
        $http = (new FakeHttp())->respond(200, '{"created":1,"data":[{"b64_json":"' . \base64_encode('PNGDATA') . '","media_type":"image/png"},{"b64_json":"!!not base64!!"}],'
            . '"usage":{"prompt_tokens":0,"completion_tokens":4175,"total_tokens":4175,"cost":0.04}}');

        $result = (new ImageClient($http->client(), 'sk-or', appName: 'Mercurius'))
            ->generate(new ImageRequest('bytedance-seed/seedream-4.5', 'красная панда в космосе', 1, '16:9'));

        self::assertSame('https://openrouter.ai/api/v1/images', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer sk-or', $http->requests[0]->getHeader('authorization'));
        self::assertSame(['model' => 'bytedance-seed/seedream-4.5', 'prompt' => 'красная панда в космосе', 'n' => 1, 'response_format' => 'b64_json', 'aspect_ratio' => '16:9'], $http->lastPayload());
        self::assertCount(1, $result->images, 'a part that is not base64 is dropped');
        self::assertSame(['image', 'image/png', 'PNGDATA'], [$result->images[0]->kind, $result->images[0]->mime, $result->images[0]->bytes]);
        self::assertSame(4175, $result->usage->outputTokens);
        self::assertSame(0.04, $result->usage->costUsd);
    }

    public function testNoPictureIsAnErrorAndARequestIsChecked(): void
    {
        $http = (new FakeHttp())->respond(200, '{"data":[]}')->respond(402, '{"error":{"code":402,"message":"Insufficient credits"}}');
        $client = new ImageClient($http->client(), 'sk-or');

        try {
            $client->generate(new ImageRequest('m/x', 'кот'));
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::Unknown, $e->kind);
        }
        try {
            $client->generate(new ImageRequest('m/x', 'кот'));
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::InsufficientCredits, $e->kind);
        }

        $this->expectException(\InvalidArgumentException::class);
        new ImageRequest('m/x', 'кот', 11);
    }
}
