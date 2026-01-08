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
        
        $platforms = $this->post->platforms()->where('status', 'published')->get();   // need to change this to pending
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
                    'status' => 'published',  //need to change this to failed
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
        $token = $account->access_token;
        $igId = $account->provider_user_id ;

        if ($mediaItems->count() > 1) {
            // Carousel Flow
            $itemIds = [];
            foreach ($mediaItems as $item) {
                $itemIds[] = $this->createIgContainer($igId, $token, $item, true);
            }
            $containerId = $this->createIgCarouselMaster($igId, $token, $itemIds);
        } else {
            // Single Post Flow
            $containerId = $this->createIgContainer($igId, $token, $mediaItems->first(), false);
        }

        return $this->finalizeIgPublish($igId, $token, $containerId);
    }

    private function createIgContainer($igId, $token, $media, $isCarouselItem)
    {
        $url =  asset(Storage::url($media->media_path));
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
            $params['media_type'] = 'VIDEO';
        } else {
            $params['image_url'] = $url;
        }

        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media", $params);
        
        if ($response->failed()) throw new Exception("IG Container Error: " . $response->body());
        
        return $response->json()['id'];
    }

    private function createIgCarouselMaster($igId, $token, $itemIds)
    {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $itemIds),
            'caption' => $this->getFormattedCaption('instagram'),
            'access_token' => $token,
        ]);

        if ($response->failed()) throw new Exception("IG Carousel Master Error: " . $response->body());
        return $response->json()['id'];
    }

    private function finalizeIgPublish($igId, $token, $containerId)
    {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igId}/media_publish", [
            'media_id' => $containerId,
            'access_token' => $token,
        ]);

        if ($response->failed()) throw new Exception("IG Finalize Error: " . $response->body());
        return $response->json();
    }

    private function publishToTikTok($account)
    {
        $video = $this->post->media()->where('media_type', 'video')->first();
        if (!$video) throw new Exception("TikTok requires a video file.");

        $url = asset(Storage::url($video->media_path));

        $response = Http::withToken($account->access_token)
            ->post("https://open.tiktokapis.com/v2/post/publish/video/init/", [
                "post_info" => [
                    "caption" => $this->getFormattedCaption('tiktok'),
                    "privacy_level" => "PUBLIC_TO_EVERYONE"
                ],
                "source_info" => [
                    "source" => "PULL_FROM_URL",
                    "video_url" => $url
                ]
            ]);

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
}
