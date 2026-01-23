<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class PublishPostToSocialMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $post;
    public $tries = 3; // Retry 3 times if it fails
    public $backoff = 60; // Wait 60 seconds before retrying

    /**
     * Create a new job instance.
     */
    public function __construct(Post $post)
    {
        $this->post = $post;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        
        $platforms = $this->post->platforms()->where('status', 'pending')->get();   // need to change this to pending
        $user = $this->post->user;

        foreach ($platforms as $platformRecord) {
            try {
                $account = $user->socialAccounts()->where('provider', $platformRecord->platform)->first();
                Log::info("Social account detailsfor {$platformRecord->platform}: " . $account);
                if (!$account) {
                    throw new Exception("Social account for {$platformRecord->platform} not linked.");
                }

                if ($platformRecord->platform === 'instagram') {
                    $this->publishToInstagram($account);
                } elseif ($platformRecord->platform === 'tiktok') {
                    $this->publishToTikTok($account);
                }

                $platformRecord->update(['status' => 'published','published_at' => now()]);

            } catch (Exception $e) {
                $platformRecord->update([
                    'status' => 'failed',  //need to change this to failed
                ]);
                // Log error for internal debugging
                \Log::error("Publishing failed for Post {$this->post->id} on {$platformRecord->platform}: " . $e->getMessage());
            }
        }

        // Update main post status if all platforms are done
        $this->post->update(['status' => 'published','published_at' => now()]);
            
        
    }

    private function publishToInstagram($account)
    {
        $mediaItems = $this->post->media()->orderBy('media_order')->get();
        // $token = $account->access_token;
        // $igId = $account->provider_user_id ;
        \Log::info("Instagram account published section:");
        $getCredential = $this->getCredential();    //only for testing while real user not signup through instagram
        $token = $getCredential['data'][0]['access_token'];
        $igId = $getCredential['data'][0]['instagram_business_account']['id'];

        if ($mediaItems->count() > 1) {
            // Carousel Flow
            \Log::info("Instagram account published section: Carousel Flow");
            $itemIds = [];
            foreach ($mediaItems as $item) {
                $itemIds[] = $this->createIgContainer($igId, $token, $item, true);
            }
            $containerId = $this->createIgCarouselMaster($igId, $token, $itemIds);
        } else {
            // Single Post Flow
            \Log::info("Instagram account published section: Single Post Flow");
            $containerId = $this->createIgContainer($igId, $token, $mediaItems->first(), false);
        }

        // Wait for the container to be ready
        $this->waitForIgContainer($containerId, $token);

        return $this->finalizeIgPublish($igId, $token, $containerId);
    }

    private function createIgContainer($igId, $token, $media, $isCarouselItem)
    {
        $url =  asset(Storage::url($media->media_path));
        // $url =  'https://oaidalleapiprodscus.blob.core.windows.net/private/org-1PEkHWEBzTqfI9C4kUbWgcxU/user-pT8F2pFootq2dQJPc7oUmO1p/img-6OnW12kK4JVdFwYjqfnkITfT.png?st=2026-01-22T15%3A09%3A57Z&se=2026-01-22T17%3A09%3A57Z&sp=r&sv=2024-08-04&sr=b&rscd=inline&rsct=image/png&skoid=38e27a3b-6174-4d3e-90ac-d7d9ad49543f&sktid=a48cca56-e6da-484e-a814-9c849652bcb3&skt=2026-01-22T15%3A27%3A07Z&ske=2026-01-23T15%3A27%3A07Z&sks=b&skv=2024-08-04&sig=Z3z9J8/jGqHrcuaoAZa1aItqiFQgVO5R5Gx2Is65/j8%3D';
        //$url =  'https://aiaffiliate.betacvinfotech.com/ai-affiliate-content-steven/public/storage/posts/media/w0vp3jeS8hdsPUobfACCBoke4sOHEx78gTo9EJmp.mp4';
        \Log::info("Instagram account published section: createIgContainer");
        $fullCaption = $this->getFormattedCaption('instagram');
        $params = [
            'access_token' => $token,
            'is_carousel_item' => $isCarouselItem
        ];

        // If it's a single post, attach the caption here
        if (!$isCarouselItem) {
            $params['caption'] = $fullCaption;
        }

        if ($media->media_type === 'video') {
            $params['video_url'] = $url;
            $params['media_type'] = 'REELS';
        } else {
            $params['image_url'] = $url;
        }

        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media", $params);
        \Log::info("Instagram account published section: createIgContainer response: " . $response->body());
        if ($response->failed()) throw new Exception("IG Container Error: " . $response->body());
        
        return $response->json()['id'];
    }

    private function createIgCarouselMaster($igId, $token, $itemIds)
    {
        \Log::info("Instagram account published section: createIgCarouselMaster");
        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $itemIds),
            'caption' => $this->getFormattedCaption('instagram'),
            'access_token' => $token,
        ]);
        \Log::info("Instagram account published section: createIgCarouselMaster response: " . $response->body());
        if ($response->failed()) throw new Exception("IG Carousel Master Error: " . $response->body());
        return $response->json()['id'];
    }

    private function finalizeIgPublish($igId, $token, $containerId)
    {
        \Log::info("Instagram account published section: finalizeIgPublish");
        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]);
        \Log::info("Instagram account published section: finalizeIgPublish response: " . $response->body());
        if ($response->failed()) throw new Exception("IG Finalize Error: " . $response->body());
        return $response->json();
    }

    private function publishToTikTok($account)
    {
        \Log::info("TikTok account published section");
        $video = $this->post->media()->where('media_type', 'video')->first();
        if (!$video) throw new Exception("TikTok requires a video file.");

        // $url = asset(Storage::url($video->media_path));
        $url =  'https://aiaffiliate.betacvinfotech.com/ai-affiliate-content-steven/public/storage/posts/media/w0vp3jeS8hdsPUobfACCBoke4sOHEx78gTo9EJmp.mp4';

        \Log::info("TikTok account published section: video url: " . $url);
        // $response = Http::withToken($account->access_token)
        //     ->post("https://open.tiktokapis.com/v2/post/publish/video/init/", [
        //         "post_info" => [
        //             "caption" => $this->getFormattedCaption('tiktok'),
        //             "privacy_level" => "PUBLIC_TO_EVERYONE"
        //         ],
        //         "source_info" => [
        //             "source" => "PULL_FROM_URL",
        //             "video_url" => $url
        //         ]
        //     ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$account->access_token,
            'Content-Type'  => 'application/json',
        ])->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
            "post_info" => [
                "caption" => $this->getFormattedCaption('tiktok'),
                "privacy_level" => "PUBLIC_TO_EVERYONE"
            ],
            "source_info" => [
                "source" => "PULL_FROM_URL",
                "video_url" => $url
            ]
        ]);
        \Log::info("TikTok account published section: response: " . $response->body());
        if ($response->failed()) throw new Exception("TikTok API Error: " . $response->body());
        return $response->json();
    }

    private function getFormattedCaption($platform)
    {
        // Combines Script + Caption + Hashtags with line breaks
        $parts = array_filter([
            $this->post->script,   // The main content/story
            $this->post->caption,  // Short catchy description
            $this->post->hastag    // The hashtags (stored as 'hastag' in your DB)
        ]);

       $captionText = implode("\n\n", $parts);
       Log::info('affiliate link: ' . url('api/v1/user/affiliate-click/'.$this->post->id.'/'.$this->post->user->affiliate_id.'?ref='.$platform));
       
        // If an affiliate URL exists, append it at the bottom
        if ($this->post->affiliate_url) {
            $captionText .= "\n\n🔗 Shop / Link: " . url('api/v1/user/affiliate-click/'.$this->post->id.'/'.$this->post->user->affiliate_id.'?ref='.$platform);
        }
        Log::info("Caption Text: " . $captionText);
        return $captionText;
    }


    private function getCredential()
    {
        $accessToken = Config::get('constant.instagram_user_access_token.token');
        $response = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
            'fields' => 'name,access_token,tasks,instagram_business_account',
            'access_token' => $accessToken,
        ]); 
        \Log::info('Instagram Account: '.json_encode($response->json()));
        $instagramAccount = $response->json();

        return $instagramAccount;
    }

    private function waitForIgContainer($containerId, $token, $maxAttempts = 10)
    {
        $attempts = 0;

        do {
            sleep(3);

            $response = Http::get("https://graph.facebook.com/v19.0/{$containerId}", [
                'fields' => 'status_code',
                'access_token' => $token,
            ]);

            $status = $response->json()['status_code'] ?? null;

            \Log::info('IG container status check', [
                'container_id' => $containerId,
                'status' => $status,
                'attempt' => $attempts,
            ]);

            if ($status === 'ERROR') {
                throw new \Exception('IG container processing failed');
            }

            $attempts++;

        } while ($status !== 'FINISHED' && $attempts < $maxAttempts);

        if ($status !== 'FINISHED') {
            throw new \Exception('IG container not ready after waiting');
        }

        return true;
    }

    
}
