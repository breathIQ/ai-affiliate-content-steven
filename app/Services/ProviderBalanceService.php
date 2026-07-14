<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls remaining balances / month-to-date spend from every external AI
 * provider the platform depends on, for the admin "API Credits" panel.
 *
 * Providers fall into two camps:
 *  - real remaining balance available (HeyGen, fal.ai)
 *  - spend-only (Anthropic, OpenAI expose cost reports but deliberately no
 *    prepaid-balance endpoint; Gemini has no usable billing API at all)
 *
 * Results are cached briefly so the admin page doesn't hammer four external
 * APIs on every load; ?fresh=1 busts the cache.
 */
class ProviderBalanceService
{
    const CACHE_KEY = 'provider_api_balances_v1';
    const CACHE_TTL_SECONDS = 600;

    // Below these values the panel shows the provider in red. HeyGen and
    // fal.ai have both actually run dry in production before.
    const LOW_HEYGEN_CREDITS = 20;
    const LOW_FAL_USD = 5.0;

    public function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return [
                'fetched_at' => now()->toIso8601String(),
                'month' => now()->format('F Y'),
                'providers' => [
                    $this->heygen(),
                    $this->fal(),
                    $this->anthropic(),
                    $this->openai(),
                    $this->gemini(),
                ],
            ];
        });
    }

    /**
     * HeyGen exposes a true remaining quota. The raw unit is quota points
     * where 60 points = 1 API credit (a credit is ~1 minute of avatar video).
     */
    protected function heygen(): array
    {
        $base = [
            'key' => 'heygen',
            'name' => 'HeyGen',
            'kind' => 'balance',
            'unit' => 'credits',
            'dashboard_url' => 'https://app.heygen.com/settings?nav=Subscriptions%20%26%20API',
        ];

        $apiKey = config('services.heygen.api_key');
        if (!$apiKey) {
            return $base + ['status' => 'missing_key', 'detail' => 'HEYGEN_API_KEY is not set.'];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Api-Key' => $apiKey, 'Accept' => 'application/json'])
                ->get(rtrim(config('services.heygen.base_url'), '/') . '/v2/user/remaining_quota');

            if (!$response->successful()) {
                return $this->errorResult($base, 'heygen', $response->status(), $response->body());
            }

            $quota = data_get($response->json(), 'data.remaining_quota');
            if ($quota === null) {
                return $this->errorResult($base, 'heygen', $response->status(), 'remaining_quota missing from response');
            }

            $credits = round($quota / 60, 1);

            return $base + [
                'status' => 'ok',
                'balance' => $credits,
                'low' => $credits < self::LOW_HEYGEN_CREDITS,
                'detail' => 'Roughly ' . round($credits) . ' minutes of avatar video left (raw quota ' . $quota . ').',
            ];
        } catch (\Throwable $e) {
            return $this->errorResult($base, 'heygen', null, $e->getMessage());
        }
    }

    /**
     * fal.ai account billing API returns the real credit balance in USD.
     * Note: fal documents this under an "admin API key"; if the regular
     * FAL_KEY lacks the scope this surfaces as a 401/403 error result.
     */
    protected function fal(): array
    {
        $base = [
            'key' => 'fal',
            'name' => 'fal.ai (Grok video + Chatterbox)',
            'kind' => 'balance',
            'unit' => 'USD',
            'dashboard_url' => 'https://fal.ai/dashboard/billing',
        ];

        $apiKey = config('services.fal.admin_api_key') ?: config('services.fal.api_key');
        if (!$apiKey) {
            return $base + ['status' => 'missing_key', 'detail' => 'FAL_ADMIN_KEY is not set.'];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Authorization' => 'Key ' . $apiKey, 'Accept' => 'application/json'])
                ->get('https://api.fal.ai/v1/account/billing', ['expand' => 'credits']);

            if ($response->status() === 403 && !config('services.fal.admin_api_key')) {
                return $base + [
                    'status' => 'missing_key',
                    'detail' => 'The regular FAL_KEY cannot read billing. Create a key with ADMIN scope at fal.ai/dashboard/keys and add it to .env as FAL_ADMIN_KEY.',
                ];
            }

            if (!$response->successful()) {
                return $this->errorResult($base, 'fal', $response->status(), $response->body());
            }

            $balance = data_get($response->json(), 'credits.current_balance');
            if ($balance === null) {
                return $this->errorResult($base, 'fal', $response->status(), 'credits.current_balance missing from response');
            }

            return $base + [
                'status' => 'ok',
                'balance' => round((float) $balance, 2),
                'low' => (float) $balance < self::LOW_FAL_USD,
                'detail' => 'Prepaid balance for account "' . data_get($response->json(), 'username', '?') . '".',
            ];
        } catch (\Throwable $e) {
            return $this->errorResult($base, 'fal', null, $e->getMessage());
        }
    }

    /**
     * Anthropic has no balance endpoint (only the console shows it), so this
     * reports month-to-date spend from the Admin API cost report instead.
     * Needs a separate admin key (sk-ant-admin...), not the normal API key.
     */
    protected function anthropic(): array
    {
        $base = [
            'key' => 'anthropic',
            'name' => 'Anthropic (Claude text)',
            'kind' => 'spend',
            'unit' => 'USD',
            'dashboard_url' => 'https://console.anthropic.com/settings/billing',
        ];

        $adminKey = config('services.anthropic.admin_api_key');
        if (!$adminKey) {
            return $base + [
                'status' => 'missing_key',
                'detail' => 'Set ANTHROPIC_ADMIN_API_KEY (create an Admin API key at console.anthropic.com). Anthropic has no remaining-balance endpoint, so this panel shows month-to-date spend.',
            ];
        }

        try {
            $spendCents = 0.0;
            $page = null;

            // 1d buckets, so one page of 31 covers the month; paginate
            // defensively anyway.
            for ($i = 0; $i < 4; $i++) {
                $params = [
                    'starting_at' => now()->startOfMonth()->toRfc3339String(),
                    'bucket_width' => '1d',
                    'limit' => 31,
                ];
                if ($page) {
                    $params['page'] = $page;
                }

                $response = Http::timeout(20)
                    ->withHeaders([
                        'x-api-key' => $adminKey,
                        'anthropic-version' => '2023-06-01',
                        'Accept' => 'application/json',
                    ])
                    ->get('https://api.anthropic.com/v1/organizations/cost_report', $params);

                if (!$response->successful()) {
                    return $this->errorResult($base, 'anthropic', $response->status(), $response->body());
                }

                $json = $response->json();
                foreach (data_get($json, 'data', []) as $bucket) {
                    foreach (data_get($bucket, 'results', []) as $result) {
                        // "amount" is a decimal string in cents.
                        $spendCents += (float) data_get($result, 'amount', 0);
                    }
                }

                if (!data_get($json, 'has_more')) {
                    break;
                }
                $page = data_get($json, 'next_page');
            }

            return $base + [
                'status' => 'ok',
                'spend_month_usd' => round($spendCents / 100, 2),
                'detail' => 'Anthropic exposes spend, not remaining balance. Check the console for the credit balance.',
            ];
        } catch (\Throwable $e) {
            return $this->errorResult($base, 'anthropic', null, $e->getMessage());
        }
    }

    /**
     * OpenAI likewise has no supported balance endpoint; the Costs API
     * (admin key required) gives month-to-date spend.
     */
    protected function openai(): array
    {
        $base = [
            'key' => 'openai',
            'name' => 'OpenAI (GPT text + images)',
            'kind' => 'spend',
            'unit' => 'USD',
            'dashboard_url' => 'https://platform.openai.com/settings/organization/billing/overview',
        ];

        $adminKey = config('services.openai.admin_api_key');
        if (!$adminKey) {
            return $base + [
                'status' => 'missing_key',
                'detail' => 'Set OPENAI_ADMIN_API_KEY (create an Admin key at platform.openai.com). OpenAI has no remaining-balance endpoint, so this panel shows month-to-date spend.',
            ];
        }

        try {
            $spendUsd = 0.0;
            $page = null;

            for ($i = 0; $i < 4; $i++) {
                $params = [
                    'start_time' => now()->startOfMonth()->timestamp,
                    'bucket_width' => '1d',
                    'limit' => 31,
                ];
                if ($page) {
                    $params['page'] = $page;
                }

                // OpenAI's costs endpoint is far slower than their inference
                // APIs; 20s was timing out from this server.
                $response = Http::timeout(45)
                    ->withToken($adminKey)
                    ->acceptJson()
                    ->get('https://api.openai.com/v1/organization/costs', $params);

                if (!$response->successful()) {
                    return $this->errorResult($base, 'openai', $response->status(), $response->body());
                }

                $json = $response->json();
                foreach (data_get($json, 'data', []) as $bucket) {
                    foreach (data_get($bucket, 'results', []) as $result) {
                        $spendUsd += (float) data_get($result, 'amount.value', 0);
                    }
                }

                if (!data_get($json, 'has_more')) {
                    break;
                }
                $page = data_get($json, 'next_page');
            }

            return $base + [
                'status' => 'ok',
                'spend_month_usd' => round($spendUsd, 2),
                'detail' => 'OpenAI exposes spend, not remaining balance. Check the billing page for credit balance.',
            ];
        } catch (\Throwable $e) {
            return $this->errorResult($base, 'openai', null, $e->getMessage());
        }
    }

    /**
     * Google offers no simple balance/spend REST call for Gemini API keys
     * (billing lives in Cloud Billing + BigQuery exports), so this is a
     * permanent link-out row rather than a live number.
     */
    protected function gemini(): array
    {
        return [
            'key' => 'gemini',
            'name' => 'Google Gemini (images)',
            'kind' => 'unavailable',
            'unit' => 'USD',
            'status' => 'unsupported',
            'detail' => 'Google has no balance or simple spend API for Gemini. Billing is pay-as-you-go on the linked Cloud project.',
            'dashboard_url' => 'https://console.cloud.google.com/billing',
        ];
    }

    protected function errorResult(array $base, string $provider, ?int $status, string $message): array
    {
        Log::warning("ProviderBalanceService {$provider} lookup failed", [
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
        ]);

        return $base + [
            'status' => 'error',
            'detail' => trim(($status ? "HTTP {$status}: " : '') . mb_substr($message, 0, 200)),
        ];
    }
}
