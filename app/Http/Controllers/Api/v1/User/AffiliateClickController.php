<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\{Post,AffiliateClick,PostPlatform};
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function saveRemoteFile(Request $request)
    {
        // Download file
        $response = Http::timeout(30)->get($request->file_url);

        if (!$response->successful()) {
            throw new \Exception('Failed to download file');
        }

        // Get mime type
        $mime = $response->header('Content-Type'); 
        // example: image/png, video/mp4

        // Decide folder & extension
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => 'bin',
        };

        $folder = str_starts_with($mime, 'video')
            ? 'videos'
            : 'images';

        // Generate filename
        $filename = Str::uuid().'.'.$extension;
        $path = "$folder/$filename";

        // Save to storage
        Storage::disk('public')->put($path, $response->body());

        $url = asset('storage/'.$path);
        return $this->sendResponse($url, 'File saved successfully', 200);
    }

}
