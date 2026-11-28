<?php

declare(strict_types=1);

namespace Mercurius\Llm\Moonshot;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\ChatCompletions;
use Mercurius\Llm\OpenRouter\Dialect;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\StreamEvent;

/**
 * Kimi models through Chat Completions: the Kimi Platform paid by the token (`platform()`) and the Kimi Code
 * subscription (`kimiCode()`). Both speak the Moonshot dialect; the subscription also learns, from its quota, when a
 * used-up window reopens. The APIs report no cost: `Usage::$costUsd` stays null.
 *
 * Every request names the client honestly — `User-Agent: Mercurius/<version>`, the address and the title of the
 * project. The rules of Kimi Code forbid changing the identifier of the client, so the device headers of the official
 * client are not sent.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/hermes-agent/plugins/model-providers/kimi-coding/__init__.py@e96221f:L12-L20 (_HEADERS) — the
 *      attribution of the agent itself; hermes sends `User-Agent: claude-code/0.1.0` on api.kimi.com elsewhere
 *      (agent/agent_init.py@e96221f:L963), which is not taken
 * ref: references/kimi-code/packages/oauth/src/region.ts@a6ac07d:L29-L46 (KIMI_REGION_PROFILES) — the two addresses
 *      of the subscription
 */
final class MoonshotClient implements LlmClient
{
    public const PLATFORM_URLS = ['https://api.moonshot.ai/v1', 'https://api.moonshot.cn/v1'];
    public const KIMI_CODE_URLS = ['https://api.kimi.ai/coding/v1', 'https://api.kimi.com/coding/v1'];
    public const PROJECT_URL = 'https://mercurius.opencck.org';
    public const PROJECT_NAME = 'Mercurius';

    /** Seconds until a used-up window is asked again when the quota does not say. */
    private const UNKNOWN_REOPEN = 3600;

    private function __construct(
        private readonly ChatCompletions $completions,
        private readonly ?KimiCodeUsage $usage,
    ) {
    }

    /**
     * @param string $version of the agent, for the user agent; anything but a plain version becomes "dev"
     */
    public static function platform(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        string $baseUrl = self::PLATFORM_URLS[0],
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
    ): self {
        $transport = self::transport($http, $apiKey, $version, self::address($baseUrl, self::PLATFORM_URLS), $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes);

        return new self(new ChatCompletions($transport, Dialect::Moonshot, 'Moonshot'), null);
    }

    /**
     * @param string $version of the agent, for the user agent; anything but a plain version becomes "dev"
     */
    public static function kimiCode(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        string $baseUrl = self::KIMI_CODE_URLS[0],
        float $connectTimeout = 10.0,
        float $inactivityTimeout = 300.0,
        float $transferTimeout = 900.0,
        int $maxResponseBytes = 32 * 1024 * 1024,
    ): self {
        $baseUrl = self::address($baseUrl, self::KIMI_CODE_URLS);
        $transport = self::transport($http, $apiKey, $version, $baseUrl, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes);

        return new self(new ChatCompletions($transport, Dialect::Moonshot, 'Kimi Code'), new KimiCodeUsage($http, $apiKey, $version, $baseUrl));
    }

    /**
     * The headers that name the agent to Kimi.
     *
     * @return array{user-agent: non-empty-string}
     */
    public static function headers(string $version): array
    {
        $version = \preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $version) === 1 ? $version : 'dev';

        return ['user-agent' => self::PROJECT_NAME . '/' . $version];
    }

    /**
     * One of the known addresses, without a trailing slash; another one is refused: the key would go to it.
     *
     * @param list<string> $known
     */
    public static function address(string $baseUrl, array $known): string
    {
        $baseUrl = \rtrim($baseUrl, '/');
        if (!\in_array($baseUrl, $known, true)) {
            throw new \InvalidArgumentException('Unknown address of Kimi: ' . \implode(' or ', $known) . ' expected');
        }

        return $baseUrl;
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        try {
            return $this->completions->complete($request, $cancellation);
        } catch (LlmException $e) {
            throw $this->windowed($e, $cancellation);
        }
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        try {
            yield from $this->completions->stream($request, $cancellation);
        } catch (LlmException $e) {
            throw $this->windowed($e, $cancellation);
        }
    }

    private static function transport(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        string $baseUrl,
        float $connectTimeout,
        float $inactivityTimeout,
        float $transferTimeout,
        int $maxResponseBytes,
    ): HttpTransport {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Kimi API key must not be empty');
        }

        return new HttpTransport($http, $apiKey, $baseUrl, self::PROJECT_NAME, self::PROJECT_URL, $connectTimeout, $inactivityTimeout, $transferTimeout, $maxResponseBytes, self::headers($version));
    }

    /**
     * A used-up window of the subscription (5 hours, a week, a month, Extra Usage) learns when it reopens from the
     * quota, or in an hour when the quota does not answer; the caller then keeps the subscription off the choice.
     *
     * ref: docs/kimi-code-error-reference.md:L276-L360 (fetched 2026-11-28) — the texts of the used-up windows
     * ref: references/kimi-code/packages/oauth/src/managed-usage.ts@a6ac07d:L218-L256 (fetchManagedUsage) — the quota
     */
    private function windowed(LlmException $e, ?Cancellation $cancellation): LlmException
    {
        if ($this->usage === null || $e->status !== 429 || $e->resetsAt !== null || !self::usedUp($e->getMessage())) {
            return $e;
        }
        try {
            $reopens = KimiCodeUsage::reopens($this->usage->fetch($cancellation));
        } catch (LlmException) {
            $reopens = null;
        }
        $reopens = $reopens !== null && $reopens > \time() ? $reopens : \time() + self::UNKNOWN_REOPEN;

        return new LlmException($e->kind, $e->getMessage(), $e->status, $e->retryAfter, $e, $reopens, $e->requestId);
    }

    private static function usedUp(string $message): bool
    {
        $lower = \strtolower($message);
        foreach (['usage limit', 'credit balance is insufficient', 'extra usage'] as $sign) {
            if (\str_contains($lower, $sign)) {
                return true;
            }
        }

        return false;
    }
}
