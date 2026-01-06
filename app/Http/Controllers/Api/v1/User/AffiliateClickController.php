<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\{Post,AffiliateClick,PostPlatform};
use Illuminate\Support\Facades\DB;
class AffiliateClickController extends ResponseController
{
    public function track(Request $request, $post_id, $affiliate_id)
    {
        $post = Post::where('id', $post_id)->firstOrFail();
        
        // 1. Detect Platform from URL parameter OR Referrer header
        $platform = $request->query('ref') ?? $this->parseReferrer($request->header('referer'));

        // 2. Determine Device Type from User Agent
        $device = $this->getDevice($request->userAgent());
        // Check for duplicate click from same IP for this post within 24 hours (or today)
        $existingClick = AffiliateClick::where('post_id', $post_id)
            ->where('ip_address', $request->ip())
            ->where('created_at', '>', now()->subHours(24))
            ->exists();

        if (!$existingClick) {
            DB::beginTransaction();
            $affiliateClick = AffiliateClick::create([
                'post_id'      => $post_id,
                'referrer'     => $affiliate_id,
                'platform'     => $platform, // 'instagram', 'tiktok', or 'unknown'
                'device'       => $device,   // 'mobile' or 'desktop'
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            if($platform == 'instagram' || $platform == 'tiktok') {
                PostPlatform::where(['post_id'=>$post_id,'platform'=>$platform])->increment('clicks');
            }

            $post->increment('total_clicks');
            DB::commit();
        }
        return redirect()->away($post->affiliate_url);
    }

    private function parseReferrer($url) {
        if (!$url) return 'direct';
        if (str_contains($url, 'instagram.com')) return 'instagram';
        if (str_contains($url, 'tiktok.com')) return 'tiktok';
        return 'other';
    }

    private function getDevice($ua) {
        return preg_match('/(android|iphone|ipad)/i', $ua) ? 'mobile' : 'desktop';
    }
}
