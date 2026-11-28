<?php

declare(strict_types=1);

namespace Mercurius\Llm\Moonshot;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenRouter\HttpTransport;
use Mercurius\Llm\OpenRouter\Json;

/**
 * The quota of the Kimi Code subscription (`GET {address}/usages`): how much of each window is used and when it
 * reopens, and the Extra Usage wallet. The official client asks it with its sign-in token; whether the address takes a
 * key of the console is checked live.
 *
 * spec: packages/llm/BRIEF.md#moonshot-и-kimi-code
 *
 * ref: references/kimi-code/packages/oauth/src/managed-usage.ts@a6ac07d:L21-L23 (kimiCodeUsageUrl), L108-L153
 *      (parseManagedUsagePayload, parseQuotaUsages, parseQuotaEntry), L155-L185 (parseBoosterWallet) — the address and
 *      the shape of the answer; the amounts of the wallet are millionths of a cent
 */
final class KimiCodeUsage
{
    private const WINDOWS = ['5h' => 'limit_5h', '7d' => 'limit_7d', 'month' => 'limit_month_total', 'month_code' => 'limit_month_code'];
    private const MICRO_CENTS = 1_000_000.0;

    private readonly HttpTransport $transport;

    public function __construct(
        HttpClient $http,
        #[\SensitiveParameter]
        string $apiKey,
        string $version,
        string $baseUrl = MoonshotClient::KIMI_CODE_URLS[0],
        float $timeout = 8.0,
    ) {
        $this->transport = new HttpTransport($http, $apiKey, MoonshotClient::address($baseUrl, MoonshotClient::KIMI_CODE_URLS), MoonshotClient::PROJECT_NAME, MoonshotClient::PROJECT_URL, \min(5.0, $timeout), $timeout, $timeout, 256 * 1024, MoonshotClient::headers($version));
    }

    /**
     * @return array{windows: array<string, array{used: float, resetsAt: ?int}>, extraUsageCents: ?array{left: int, total: int}}
     * @throws LlmException
     */
    public function fetch(?Cancellation $cancellation = null): array
    {
        $data = $this->transport->decodeJson($this->transport->send('GET', '/usages', null, 'application/json', $cancellation), $cancellation);
        $usages = Json::object($data, 'usages');
        $windows = [];
        foreach (self::WINDOWS as $name => $key) {
            $entry = Json::object($usages, $key);
            $used = self::number($entry['used_ratio'] ?? null);
            if ($used === null) {
                continue;
            }
            $reset = Json::string($entry, 'reset_time');
            $time = $reset !== null && $reset !== '' ? \strtotime($reset) : false;
            $windows[$name] = ['used' => $used, 'resetsAt' => $time === false ? null : $time];
        }
        $balance = Json::object(Json::object($data, 'boosterWallet'), 'balance');
        $total = self::number($balance['amount'] ?? null);
        $left = self::number($balance['amountLeft'] ?? null);
        $wallet = Json::string($balance, 'type') === 'BOOSTER' && $total !== null && $total > 0
            ? ['left' => (int) \round(($left ?? 0.0) / self::MICRO_CENTS), 'total' => (int) \round($total / self::MICRO_CENTS)]
            : null;

        return ['windows' => $windows, 'extraUsageCents' => $wallet];
    }

    /**
     * When the subscription can be called again: the latest reset among the used-up windows; null when none is used up.
     *
     * @param array{windows: array<string, array{used: float, resetsAt: ?int}>, extraUsageCents: ?array{left: int, total: int}} $quota
     */
    public static function reopens(array $quota): ?int
    {
        $latest = null;
        foreach ($quota['windows'] as $window) {
            if ($window['used'] >= 1.0 && $window['resetsAt'] !== null) {
                $latest = \max($latest ?? 0, $window['resetsAt']);
            }
        }

        return $latest;
    }

    private static function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value)) ? (float) $value : null;
    }
}
