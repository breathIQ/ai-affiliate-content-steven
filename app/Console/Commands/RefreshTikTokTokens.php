<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Config;

class RefreshTikTokTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tiktok:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh TikTok access tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accounts = SocialAccount::where('provider', 'tiktok')
            ->whereNotNull('refresh_token')
            ->where('token_expires_at', '<=', now()->addMinutes(30))
            ->get();

        foreach ($accounts as $account) {
            try {
                // Refresh token still valid?
                if (
                    $account->refresh_token_expires_at &&
                    now()->greaterThan($account->refresh_token_expires_at)
                ) {
                    Log::warning('TikTok refresh token expired', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                $response = Http::asForm()->post(
                    'https://open.tiktokapis.com/v2/oauth/token/',
                    [
                        'client_key' => Config::get('services.tiktok.client_key'),
                        'client_secret' => Config::get('services.tiktok.client_secret'),
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $account->refresh_token,
                    ]
                );

                if (!$response->successful()) {
                    Log::error('TikTok token refresh failed', [
                        'account_id' => $account->id,
                        'response' => $response->json(),
                    ]);
                    continue;
                }

                $data = $response->json();

                $account->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
                    'token_expires_at' => now()->addSeconds($data['expires_in']),
                    // 'refresh_token_expires_at' => now()->addSeconds(
                    //     $data['refresh_expires_in'] ?? 0
                    // ),
                ]);

                Log::info('TikTok token refreshed', [
                    'account_id' => $account->id,
                ]);

            } catch (\Throwable $e) {
                Log::error('TikTok token refresh exception', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('TikTok token refresh completed.');
    }
}
