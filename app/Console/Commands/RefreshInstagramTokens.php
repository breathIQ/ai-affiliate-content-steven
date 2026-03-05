<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class RefreshInstagramTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'instagram:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Instagram long-lived access tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Refresh tokens expiring in next 7 days
        $accounts = SocialAccount::where('provider', 'instagram')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No Instagram tokens need refreshing.');
            return;
        }

        foreach ($accounts as $account) {
            try {
                $response = Http::get('https://graph.instagram.com/refresh_access_token', [
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $account->access_token,
                ]);

                if (!$response->successful()) {
                    Log::error('Instagram token refresh failed', [
                        'account_id' => $account->id,
                        'response' => $response->json(),
                    ]);
                    continue;
                }

                $data = $response->json();

                $account->update([
                    'access_token' => $data['access_token'],
                    'token_expires_at' => now()->addSeconds($data['expires_in']),
                ]);

                Log::info('Instagram token refreshed', [
                    'account_id' => $account->id,
                ]);

            } catch (\Throwable $e) {
                Log::error('Instagram token refresh exception', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Instagram token refresh completed.');
    }
}
