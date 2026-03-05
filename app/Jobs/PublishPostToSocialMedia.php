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
        // if (function_exists('opcache_reset')) {
        //     opcache_reset();
        //     \Log::info("OPcache has been reset!");
        // } else {
        //     \Log::info("OPcache is not enabled.");
        // }
       
        $platforms = $this->post->platforms()->whereIn('status', ['processing','failed'])->get();  
        $user = $this->post->user;
        $hasFailure = false;
        
        foreach ($platforms as $platformRecord) {
            try {
               
            $platform = $platformRecord->platform;
            \Log::info("Social account details for: {$platform}");
                $account = $user->socialAccounts()->where('provider', $platformRecord->platform)->first();
                \Log::info("Social account details for:= {$platformRecord->platform}", [$account]);
                if (!$account) {
                    throw new Exception("Social account for {$platformRecord->platform} not linked.");
                }

                if ($platform == 'instagram') {
                    \Log::info("Publishing to instagram for Post");

                    $this->publishToInstagram($account);
                } elseif ($platform === 'tiktok') {
                    \Log::info("Publishing to TikTok for Post {$this->post->id}");
                    //$this->publishToTikTok($account);
                }

                $platformRecord->update(['status' => 'published','published_at' => now()]);

            } catch (Exception $e) {
                $hasFailure = true;

                $platformRecord->update([
                    'status' => 'failed',  //need to change this to failed
                ]);
                // Log error for internal debugging
                \Log::error("Publishing failed for Post {$this->post->id} on {$platformRecord->platform}: " . $e->getMessage());
            }
        }

        // Update main post status if all platforms are done
        // $this->post->update(['status' => 'published','published_at' => now()]);
        if ($hasFailure) {
            $this->post->update([
                'status' => 'failed',
            ]);
        } else {
            $this->post->update([
                'status' => 'published',
                'published_at' => now()
            ]);
        }
            
        
    }
    
    // private function checkLog(){
    //     \Log::info('Testing cache issues log-123');
    //     return true;
    // }

    private function publishToInstagram($account)
    {
        \Log::info("Instagram account details: ". $account);
        $mediaItems = $this->post->media()->orderBy('media_order')->get();
       
        $token = $account->access_token;
        $igId = $account->provider_user_id ;
        // \Log::info("Instagram account published section:");
        
        if ($mediaItems->count() > 1) {
            // Carousel Flow
            \Log::info("Instagram account published section: Carousel Flow");
            $itemIds = [];
            foreach ($mediaItems as $item) {
                
            \Log::info("Instagram account published section: mediaItems");
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

    // private function createIgContainer($igId, $token, $media, $isCarouselItem)
    // {
        
    //     $url = asset(Storage::url($media->media_path));
    //     \Log::info("media path-sleep:".$url);
    //     // $fullCaption = $this->getFormattedCaption('instagram');
    //     $params = [
    //         'access_token' => $token,
    //     ];
        
    //     if ($isCarouselItem) {
    //         $params['is_carousel_item'] = true;
    //     } else {
    //         $params['caption'] = $this->getFormattedCaption('instagram');
    //     }

    //     if ($media->media_type === 'video') {
    //         $params['video_url'] = $url;
    //         $params['media_type'] = 'REELS';
    //     } else {
    //         // \Log::info("media path:image_url--".$url);
    //         $params['image_url'] = $url;
    //     }
        
    //     Log::info('params--',['param'=>$params]);
    //     $response = Http::post("https://graph.instagram.com/v19.0/{$igId}/media", $params);
    //     \Log::info("Instagram account published section: createIgContainer response: " . $response->body());
    //     if ($response->failed()) throw new Exception("IG Container Error: " . $response->body());
        
    //     return $response->json()['id'];
    // }
    
    private function createIgContainer($igId, $token, $media, $isCarouselItem)
    {
        
        $url = asset(Storage::url($media->media_path));
        //  $url = "https://co2body.com/storage/".$media->media_path;
        \Log::info("IG media url--le: ".$url);
    
        $params = [
            'access_token'     => $token,
            'is_carousel_item' => $isCarouselItem,
        ];
    
        if (!$isCarouselItem) {
            $params['caption'] = $this->getFormattedCaption('instagram');
        }
    
        if ($media->media_type === 'video') {
            $params['video_url']  = $url;
            $params['media_type'] = 'REELS';
        } else {
            $params['image_url'] = $url;
        }
    
        \Log::info('IG params', $params);
    
        $response = Http::post("https://graph.instagram.com/v19.0/{$igId}/media", $params);
        \Log::info("IG create container response: ".$response->body());
    
        if ($response->failed()) {
            throw new Exception("IG Container Error: " . $response->body());
        }
    
        return $response->json()['id'];
    }
  

    private function createIgCarouselMaster($igId, $token, $itemIds)
    {
        \Log::info("Instagram account published section: createIgCarouselMaster");
    
        $response = Http::post("https://graph.instagram.com/v19.0/{$igId}/media", [
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
        
        $response = Http::post("https://graph.instagram.com/v19.0/{$igId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]);
        \Log::info("Instagram account published section: finalizeIgPublish response: " . $response->body());
        if ($response->failed()) throw new Exception("IG Finalize Error: " . $response->body());
        return $response->json();
    }

    // private function publishToTikTok($account)
    // {
    //     \Log::info("TikTok account published section Account:");
    //     $video = $this->post->media()->where('media_type', 'video')->first();
    //     if (!$video) throw new Exception("TikTok requires a video file.");

    //     // $url = asset(Storage::url($video->media_path));
    //     $url = Storage::disk('public')->path($video->media_path);
    //     //$url =  'https://aiaffiliate.betacvinfotech.com/ai-affiliate-content-steven/public/storage/posts/media/w0vp3jeS8hdsPUobfACCBoke4sOHEx78gTo9EJmp.mp4';

    //     \Log::info("TikTok account published section: video url: " . $url);
    //     // $response = Http::withToken($account->access_token)
    //     //     ->post("https://open.tiktokapis.com/v2/post/publish/video/init/", [
    //     //         "post_info" => [
    //     //             "caption" => $this->getFormattedCaption('tiktok'),
    //     //             "privacy_level" => "PUBLIC_TO_EVERYONE"
    //     //         ],
    //     //         "source_info" => [
    //     //             "source" => "PULL_FROM_URL",
    //     //             "video_url" => $url
    //     //         ]
    //     //     ]);

    //     $response = Http::withHeaders([
    //         'Authorization' => 'Bearer '.$account->access_token,
    //         'Content-Type'  => 'application/json',
    //     ])->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
    //         "post_info" => [
    //             "caption" => $this->getFormattedCaption('tiktok'),
    //             "privacy_level" => "PUBLIC_TO_EVERYONE"
    //         ],
    //         "source_info" => [
    //             "source" => "PULL_FROM_URL",
    //             "video_url" => $url
    //         ]
    //     ]);
    //     \Log::info("TikTok account published section: response: " . $response->body());
    //     $publishId = $response['data']['publish_id'] ?? null;
    //     Log::info("TikTok account published section: publishId: " ,$publishId);
    //     if ($response->failed()) throw new Exception("TikTok API Error: " . $response->body());
    //     return $response->json();
    // }

    //******post published code****** */
    // private function publishToTikTok($account)
    // {
    //     $video = $this->post->media()->where('media_type', 'video')->first();
    //     if (!$video) {
    //         throw new Exception("TikTok requires a video file.");
    //     }

    //     $response = Http::withToken($account->access_token)
    //         ->get("https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id");

    //     Log::info("TikTok account published section: response: " . $response->body());

    //     $videoPath = Storage::disk('public')->path($video->media_path);
    //     Log::info("TikTok account published section: video path: " . $videoPath);
    //     // STEP 1: INIT Upload
    //     $initResponse = Http::withToken($account->access_token)
    //         ->post("https://open.tiktokapis.com/v2/post/publish/video/init/", [
    //             "post_info" => [
    //                 "title" => "My Upload Video"
    //             ],
    //             "source_info" => [
    //                 "source" => "FILE_UPLOAD",
    //                 "video_size" => filesize($videoPath),
    //                 "chunk_size" => filesize($videoPath),
    //                 "total_chunk_count" => 1,
    //             ]
    //         ]);

    //     $initData = $initResponse->json();
    //     Log::info("TikTok account published section: init data", ['data' => $initData]);
    //     if (empty($initData['data']['upload_url'])) {
    //         throw new Exception("Init failed: " . $initResponse->body());
    //     }

    //     $uploadUrl = $initData['data']['upload_url'];
    //     $uploadId  = $initData['data']['upload_id'];

    //     // STEP 2: Upload binary video (PUT request)
    //     $uploadResponse = Http::withHeaders([
    //         "Content-Type" => "video/mp4",
    //     ])->put($uploadUrl, file_get_contents($videoPath));

    //     if ($uploadResponse->failed()) {
    //         throw new Exception("Upload failed: " , $uploadResponse->body());
    //     }

    //     // STEP 3: Publish
    //     $publishResponse = Http::withToken($account->access_token)
    //         ->post("https://open.tiktokapis.com/v2/post/publish/video/", [
    //             "post_info" => [
    //                 "title" => $this->getFormattedCaption('tiktok'),
    //                 "privacy_level" => "SELF_ONLY" // PUBLIC_TO_EVERYONE or PRIVATE_TO_FOLLOWERS
    //             ],
    //             "source_info" => [
    //                 "source" => "FILE_UPLOAD",
    //                 "upload_id" => $uploadId
    //             ]
    //         ]);

    //     $publishData = $publishResponse->json();
    //     Log::info("TikTok account published section: publish data", ['data' => $publishData]);
    //     if ($publishResponse->failed()) {
    //         throw new Exception("Publish failed: " , $publishResponse->body());
    //     }

    //     return $publishData;
    // }

    //********draft code*** */
    private function publishToTikTok($account)
    {
        $video = $this->post->media()->where('media_type', 'video')->first();
        if (!$video) {
            throw new Exception("TikTok requires a video file.");
        }

        // STEP 0: Get user info (optional)
        $response = Http::withToken($account->access_token)
            ->get("https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id");

        Log::info("TikTok account published section: user info", ['response' => $response->json()]);

        $videoPath = Storage::disk('public')->path($video->media_path);
        Log::info("TikTok account published section: video path", ['path' => $videoPath]);

        // STEP 1: INIT Upload
        $initResponse = Http::withToken($account->access_token)
            ->post("https://open.tiktokapis.com/v2/post/publish/video/init/", [
                "post_info" => [
                    "title" => $this->getFormattedCaption('tiktok')
                ],
                "source_info" => [
                    "source" => "FILE_UPLOAD",
                    "video_size" => filesize($videoPath),
                    "chunk_size" => filesize($videoPath),
                    "total_chunk_count" => 1,
                ]
            ]);

        $initData = $initResponse->json();
        Log::info("TikTok account published section: init data", ['data' => $initData]);

        if (empty($initData['data']['upload_url'])) {
            throw new Exception("Init failed: " . $initResponse->body());
        }

        $uploadUrl = $initData['data']['upload_url'];
        $uploadId  = $initData['data']['upload_id'];

        // STEP 2: Upload binary video (PUT request)
        $uploadResponse = Http::withHeaders([
            "Content-Type" => "video/mp4",
        ])->put($uploadUrl, file_get_contents($videoPath));

        if ($uploadResponse->failed()) {
            throw new Exception("Upload failed: " . $uploadResponse->body());
        }

        // STEP 3: Publish as DRAFT
        $publishResponse = Http::withToken($account->access_token)
            ->post("https://open.tiktokapis.com/v2/post/publish/video/", [
                "post_info" => [
                    "title" => $this->getFormattedCaption('tiktok'),
                    "privacy_level" => "PRIVATE_TO_ME" // This ensures the video goes to Drafts
                ],
                "source_info" => [
                    "source" => "FILE_UPLOAD",
                    "upload_id" => $uploadId
                ]
            ]);

        $publishData = $publishResponse->json();
        Log::info("TikTok account published section: publish data", ['data' => $publishData]);

        if ($publishResponse->failed() || !empty($publishData['error'])) {
            throw new Exception("Publish failed: " . $publishResponse->body());
        }

        return $publishData;
    }


    private function getFormattedCaption($platform)
    {
        // Combines Script + Caption + Hashtags with line breaks
        $parts = array_filter([
            $this->post->caption . "\n🔗 Copy The Link: " . Config::get('constant.frontend_url').'/'.$this->post->user->affiliate_id,  // Short catchy caption
            $this->post->hastag    // The hashtags 
        ]);

       $captionText = implode("\n\n", $parts);
    //   Log::info('affiliate link: ' . Config::get('constant.frontend_url').'/'.$this->post->user->affiliate_id);
       
        // // If an affiliate URL exists, append it at the bottom
        // if ($this->post->affiliate_url) {
        //     $captionText .= "\n\n🔗 Copy The Link: " . url('al/'.$this->post->id.'/'.$this->post->user->affiliate_id.'?ref='.$platform);
        // }
        // Log::info("Caption Text: " . $captionText);
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
            sleep(2);

            $response = Http::get("https://graph.instagram.com/v19.0/{$containerId}", [
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
