<?php

namespace App\Services;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class SocialTokenService
{
    /**
     * Ensures the token is valid, refreshes if necessary (TikTok).
     */
    public function getValidToken(SocialAccount $account)
    {
        // If token expires in more than 10 minutes, it's safe to use
        if ($account->token_expires_at && Carbon::parse($account->token_expires_at)->isFuture() && 
            Carbon::parse($account->token_expires_at)->diffInMinutes(now()) > 10) {
            return $account->access_token;
        }

        if ($account->platform === 'tiktok') {
            return $this->refreshTikTokToken($account);
        }

        // Instagram/Facebook tokens last 60 days; usually you just 
        // handle a 401 error by asking the user to re-link.
        return $account->access_token;
    }

    private function refreshTikTokToken(SocialAccount $account)
    {
        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/auth/token/refresh/', [
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $account->update([
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at'    => now()->addSeconds($data['expires_in']),
            ]);

            return $data['access_token'];
        }

        throw new \Exception("Could not refresh TikTok token. User must re-authenticate.");
    }
}