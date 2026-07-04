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
                    $this->tiktokPublish($account,$platformRecord->tiktok_payload);
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
             Log::info("Post {$this->post->id} published successfully");
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
        
        sleep(2);
       
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
        
        // $url = asset(Storage::url($media->media_path));
        // $url = "https://co2body.com/storage/posts/media/90d4ad8a-b515-4ab4-94f4-399541c10ecd.jpg";
        // $url = "https://upload.wikimedia.org/wikipedia/commons/thumb/6/6a/PNG_Test.png/500px-PNG_Test.png?20260224095916";
        //  $url = "https://co2body.com/storage/".$media->media_path;
        
        $url = Config::get('constant.media_base_url') . config('constant.media_base_path').$media->media_path;
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


    // private function getFormattedCaption($platform)
    // {
    //     // Combines Script + Caption + Hashtags with line breaks
    //     $parts = array_filter([
    //         $this->post->caption . "\n🔗 Copy The Link: " . Config::get('constant.frontend_url').'/'.$this->post->user->affiliate_id,  // Short catchy caption
    //         $this->post->hastag    // The hashtags 
    //     ]);

    //   $captionText = implode("\n\n", $parts);
    // //   Log::info('affiliate link: ' . Config::get('constant.frontend_url').'/'.$this->post->user->affiliate_id);
       
    //     // // If an affiliate URL exists, append it at the bottom
    //     // if ($this->post->affiliate_url) {
    //     //     $captionText .= "\n\n🔗 Copy The Link: " . url('al/'.$this->post->id.'/'.$this->post->user->affiliate_id.'?ref='.$platform);
    //     // }
    //     // Log::info("Caption Text: " . $captionText);
    //     return $captionText;
    // }
    
    private function getFormattedCaption($platform)
    {
        $affiliateLink = "🔗 Copy The Link: " . Config::get('constant.frontend_url') . '/' . $this->post->user->affiliate_id;
        $mainCaption = $this->post->caption;
        $hashtags = $this->post->hastag;

        if (strtolower($platform) === 'tiktok') {
            return [
                // Title: Max 90 chars (Short & Hooky)
                'title' => mb_strimwidth($mainCaption, 0, 80, "..."),
                
                // Description: Max 4000 chars (The link + full text + hashtags)
                'description' => $mainCaption . "\n\n" . $affiliateLink . "\n\n" . $hashtags
            ];
        }

        // Instagram: Keep the original long format
        $parts = array_filter([
            $mainCaption . "\n" . $affiliateLink,
            $hashtags
        ]);

        return mb_strimwidth(implode("\n\n", $parts), 0, 2100, "...");
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

    // 60 attempts at 2s = up to 2 minutes. Images finish almost instantly so
    // this doesn't slow them down, but real videos need real transcoding
    // time on Instagram's side - 10 attempts (20s) was only ever enough for
    // images and silently failed every video post.
    private function waitForIgContainer($containerId, $token, $maxAttempts = 60)
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
    
    
     //**************************Tiktok publishing code********************************************* */

    private function tiktokPublish($account,$tiktokPayload)
    {
        $tiktokPayload = json_decode($tiktokPayload ?? '');
        $mediaItems = $this->post->media()->orderBy('media_order')->get();

        $images = [];
        $videos = [];

        $tempFiles = [];
        foreach ($mediaItems as $media) {
            
            if ($media->media_type === 'image') {
                $localPath = Storage::disk('public')->path($media->media_path);
                $webpData = $this->convertToWebp($localPath);
                // $images[] = asset('storage/posts/temp/' . $webpData['filename']);
                $images[] = Config::get('constant.media_base_url') . config('constant.media_base_path').'posts/temp/' . $webpData['filename'];
                $tempFiles[] = $webpData['full_path']; // Track for cleanup
            }

            if ($media->media_type === 'video') {
                // $videos[] = Storage::disk('public')->path($media->media_path);
                $videos[] = Config::get('constant.media_base_url') . config('constant.media_base_path') . $media->media_path;
                
            }
        }

        $caption = $this->getFormattedCaption('tiktok');


        $responses = [];

        /*
        |----------------------------------------
        | IMAGE SLIDESHOW
        |----------------------------------------
        */
        if (!empty($images)) {
            // $images = ["https://co2body.com/storage/posts/media/nature.webp","https://co2body.com/storage/posts/media/tree.webp"];
            Log::info('images--',[$images]);
            $initData = $this->initPost("PHOTO", $images, $caption, $account,$tiktokPayload);

            Log::info('TikTok Image Init Success', [$initData]);
            $publish_id = $initData['publish_id'] ?? null;
            
            $responses[] = $this->checkPublishStatus(
                $publish_id,
                $account
            );

            Log::info('TikTok Image Publish Success', [$responses]);

            $this->cleanupTempFiles();

            $currentStatus = $responses['data']['status'] ?? 'UNKNOWN';
            if ($currentStatus == 'FAILED') {
                // ERROR: Log the reason and fail the job
                $reason = $responses['data']['fail_reason'] ?? 'Unknown error';
                Log::error("TikTok Publish Failed: " . $reason);
                throw new Exception("TikTok image publish failed: " . $reason);
            }
        }

        /*
        |----------------------------------------
        |  VIDEO POSTS
        |----------------------------------------
        */
        if (!empty($videos)) {
            Log::info('videos--',[$videos]);
            foreach ($videos as $videoPath) {

                $initData = $this->initPost("video", $videoPath, $caption, $account,$tiktokPayload);

                $uploadUrl = $initData['upload_url'] ?? null;

                $uploadId = null;

                if ($uploadUrl) {
                    $parts = parse_url($uploadUrl);
                    parse_str($parts['query'], $query);
                    $uploadId = $query['upload_id'] ?? null;
                }

                if (!$uploadId || !$uploadUrl) {
                    throw new Exception("TikTok video init failed");
                }

                $this->uploadVideo($videoPath, $uploadUrl);

                $responses[] = $this->publishPost(
                    "video",
                    $caption,
                    $uploadId,
                    [],
                    $account,
                    $tiktokPayload
                );
            }
        }

        return $responses;
    }

    protected function initPost($mediaType, $mediaPaths, $captionData,$account,$tiktokPayload)
    {
        if ($mediaType == 'PHOTO') {
            $payload = [
                "post_info" => [
                    "title" => $captionData['title'],
                    "description" => $captionData['description'],
                    "privacy_level" => $tiktokPayload->privacy_level ?? "SELF_ONLY",
                    "auto_add_music" => true,
                    "disable_comment" => !filter_var($tiktokPayload->allow_comment ?? false, FILTER_VALIDATE_BOOLEAN),
                    "brand_content_toggle" => filter_var($tiktokPayload->branded_content ?? false, FILTER_VALIDATE_BOOLEAN),
                    "brand_organic_toggle" => filter_var($tiktokPayload->brand_organic ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
                "source_info" => [
                    "source" => "PULL_FROM_URL",
                    "photo_cover_index" => 0,
                    "photo_images" => $mediaPaths
                ],
                "post_mode" => "DIRECT_POST",
                "media_type" => "PHOTO"
            ];

            
        } elseif ($mediaType == 'video') {
            $videoPath = $mediaPaths;
            Log::info('video path--'.$videoPath);
            if (!file_exists($videoPath)) {
                throw new Exception("Video file not found: $videoPath");
            }

            $videoSize = (int)filesize($videoPath);
            $chunkSize = min($videoSize, 10 * 1024 * 1024); // 10MB max

            $payload = [
                "post_info" => [
                    "title" => $captionData['description'],
                    // "description" => $captionData['description'],
                    "privacy_level" => $tiktokPayload->privacy_level ?? "SELF_ONLY",
                    "disable_duet" => !filter_var($tiktokPayload->allow_duet ?? false, FILTER_VALIDATE_BOOLEAN),
                    "disable_stitch" => !filter_var($tiktokPayload->allow_stitch ?? false, FILTER_VALIDATE_BOOLEAN),
                    "disable_comment" => !filter_var($tiktokPayload->allow_comment ?? false, FILTER_VALIDATE_BOOLEAN),
                    "brand_content_toggle" => filter_var($tiktokPayload->branded_content ?? false, FILTER_VALIDATE_BOOLEAN),
                    "brand_organic_toggle" => filter_var($tiktokPayload->brand_organic ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
                "source_info" => [
                    "source" => "FILE_UPLOAD",
                    "video_size" => $videoSize,
                    "chunk_size" => $chunkSize,
                    "total_chunk_count" => (int) ceil($videoSize / $chunkSize),
                ],
                "post_mode" => "MEDIA_UPLOAD", 
                "media_type" => $mediaType
            ];
            

        }
        Log::info('payload--', [$payload]);
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json',
            ])
            ->post(
                $mediaType == "PHOTO"
                    ? 'https://open.tiktokapis.com/v2/post/publish/content/init/'
                    : 'https://open.tiktokapis.com/v2/post/publish/video/init/',
                $payload
            );

        if ($response->failed()) {
            throw new Exception("tiktok init Error: " . $response->body());
        }
        $data = $response->json();
        
        Log::info("TikTok init response", $data);

        return $data['data'] ?? [];
    }

    protected function uploadVideo($videoPath, $uploadUrl)
    {
        $videoBinary = file_get_contents($videoPath);
        $videoSize = strlen($videoBinary);
        
        // For a single chunk, the range is 0 to (total - 1)
        $rangeStart = 0;
        $rangeEnd = $videoSize - 1;
        $contentRange = "bytes {$rangeStart}-{$rangeEnd}/{$videoSize}";

        Log::info("Uploading with Range: " . $contentRange);

        $response = Http::withHeaders([
            "Content-Type" => "video/mp4",
            "Content-Length" => $videoSize,
            "Content-Range" => $contentRange, // <--- REQUIRED for TikTok
        ])->withBody($videoBinary, 'video/mp4')->put($uploadUrl);

        Log::info("TikTok upload status: " . $response->status());

        if ($response->failed()) {
            throw new Exception("TikTok upload failed: " . $response->body() . " Status: " . $response->status());
        }
        return true;
    }

    protected function publishPost($mediaType, $caption, $uploadId = null, $mediaPaths = [],$account,$tiktokPayload)
    {
        Log::info('upload ids--',[$uploadId]);
        Log::info('media paths--',[$mediaPaths]);
        $payload = [
            "post_info" => [
                "title" => $caption['description'],
                "privacy_level" => $tiktokPayload->privacy_level ?? "SELF_ONLY",
                "disable_duet" => !filter_var($tiktokPayload->allow_duet ?? false, FILTER_VALIDATE_BOOLEAN),
                "disable_stitch" => !filter_var($tiktokPayload->allow_stitch ?? false, FILTER_VALIDATE_BOOLEAN),
                "disable_comment" => !filter_var($tiktokPayload->allow_comment ?? false, FILTER_VALIDATE_BOOLEAN),
                "brand_content_toggle" => filter_var($tiktokPayload->branded_content ?? false, FILTER_VALIDATE_BOOLEAN),
                "brand_organic_toggle" => filter_var($tiktokPayload->brand_organic ?? false, FILTER_VALIDATE_BOOLEAN),
            ]
        ];

        if ($mediaType === 'video') {
            $payload['source_info'] = [
                "source" => "FILE_UPLOAD",
                "upload_id" => $uploadId
            ];
            $url = 'https://open.tiktokapis.com/v2/post/publish/video/';
            // $url = 'https://open.tiktokapis.com/v2/post/publish/video/complete/';
        } else {
            $payload['source_info'] = [
                "source" => "PULL_FROM_URL",
                "photo_cover_index" => 0,
                "photo_images" => $mediaPaths
            ];
            $url = 'https://open.tiktokapis.com/v2/post/publish/content/';
        }
        Log::info('publish post payloads--',[$payload]);
        $response = Http::withToken($account->access_token)
            ->post($url, $payload);

        $data = $response->json() ?? [];
        Log::info("TikTok publish response", [$data]);

        if ($response->successful() || $data == []) {
            Log::info("TikTok Publish Success! Video is now processing.");
            return $response->json() ?? ["status" => "success"];
        }

        if ($response->failed() || (isset($data['error']) && $data['error']['code'] !== 'ok')) {
            throw new Exception("TikTok publish failed: " . ($response->body() ?: 'Unknown Error'));
        }
        return $data;
    }


    public function checkPublishStatus($publishId, $account)
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json',
            ])
            ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                "publish_id" => $publishId
            ]);

        return $response->json();
    }

    protected function convertToWebp($sourcePath)
    {
        $image = imagecreatefromstring(file_get_contents($sourcePath));
        $filename = 'tiktok_' . uniqid() . '.webp';
        $destination = storage_path('app/public/posts/temp/' . $filename);
        
        imagewebp($image, $destination, 80);
        imagedestroy($image);

        return [
            'filename' => $filename,
            'full_path' => $destination
        ];
    }

    public function cleanupTempFiles()
    {
        $files = glob(storage_path('app/public/posts/temp/*'));
        $now = time();
        Log::info('Temp files cleaned');
        foreach ($files as $file) {
            // If file is older than 60 minutes
            if ($now - filemtime($file) >= 3600) { 
                unlink($file);
            }
        }
    }

    
}
