<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\{Post,AffiliateClick,PostPlatform,TotalClickByUser,AffiliateClickByUser,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        // dd($post_id, $affiliate_id,$platform,$request->userAgent());
        // 2. Determine Device Type from User Agent
        $device = $this->getDevice($request->userAgent());
        // Check for duplicate click from same IP for this post within 24 hours (or today)
        $existingClick = AffiliateClick::where('post_id', $post_id)
            ->where('ip_address', $request->ip())
            ->where('created_at', '>', now()->subHours(24))
            ->exists();

        // if (!$existingClick) {
        //     DB::beginTransaction();
        //     $affiliateClick = AffiliateClick::create([
        //         'post_id'      => $post_id,
        //         'referrer'     => $affiliate_id,
        //         'platform'     => $platform, // 'instagram', 'tiktok', or 'unknown'
        //         'device'       => $device,   // 'mobile' or 'desktop'
        //         'ip_address'   => $request->ip(),
        //         'user_agent'   => $request->userAgent(),
        //     ]);

        //     if($platform == 'instagram' || $platform == 'tiktok') {
        //         PostPlatform::where(['post_id'=>$post_id,'platform'=>$platform])->increment('clicks');
        //     }

        //     $post->increment('total_clicks');
        //     DB::commit();
        // }

        DB::transaction(function () use ($post_id, $affiliate_id, $platform, $device, $request, $post) {

            AffiliateClick::create([
                'post_id'    => $post_id,
                'referrer'   => $affiliate_id,
                'platform'   => $platform,
                'device'     => $device,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            if (in_array($platform, ['instagram', 'tiktok'])) {
                PostPlatform::where([
                    'post_id'  => $post_id,
                    'platform' => $platform
                ])->increment('clicks');
            }

            $post->increment('total_clicks');

        }, 5); // retry 5 times automatically if deadlock happens

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

    public function affiliateClicks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'affiliate_id' => 'required|string',
            'ip_address'  => 'required|ip',
            'platform'     => 'nullable|string',
            'device'       => 'nullable|string',
            'user_agent'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }

        $user = User::where('affiliate_id', $request->affiliate_id)->firstOrFail();

        $existingClick = AffiliateClickByUser::where('user_id', $user->id)
            ->where('ip_address', $request->ip_address)
            ->where('created_at', '>', now()->subHours(24))
            ->exists();

        if (!$existingClick) {
            DB::transaction(function () use ($user, $request) {

                AffiliateClickByUser::create([
                    'user_id'    => $user->id,
                    'referrer'   => $request->affiliate_id,
                    'platform'   => $request->platform,
                    'device'     => $request->device,
                    'ip_address' => $request->ip_address,
                    'user_agent' => $request->user_agent,
                ]);

                TotalClickByUser::updateOrCreate(
                    ['user_id' => $user->id],
                    []
                )->increment('total_clicks');
                
            }, 5); // retry 5 times automatically if deadlock happens
        }

        $redirecturl = "https://carbogenetics.com/ref/".$user->other_affiliate_id."?redirect=".$user->amazon_link;

        return redirect()->away($redirecturl);
    }


}
