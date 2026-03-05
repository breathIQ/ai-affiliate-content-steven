<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Storage;

class PublishInstagramStory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public $post;
    public $tries = 3; // Retry 3 times if it fails
    public $backoff = 60; // Wait 60 seconds before retrying
    public function __construct($post)
    {
        $this->post = $post;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try{
            $platform = $this->post->platforms()->where('platform', 'instagram')->whereIn('status', ['processing','failed'])->first();  
            $user = $this->post->user;
            $account = $user->socialAccounts()->where('provider', $platform->platform)->first();
            Log::info("Instagram account details for {$user->id}", [$account]);
            if (!$account) {
                throw new Exception("Instagram account not linked.");
            }

           $result = $this->publishMultipleInstagramStories($account);
           Log::info("Instagram Story result", [$result]);
        }catch (Exception $e) {
            $hasFailure = true;

            // $platformRecord->update([
            //     'status' => 'failed',  //need to change this to failed
            // ]);
            // Log error for internal debugging
            \Log::error("Story Publishing failed for Post {$this->post->id} on {$platform->platform}: " . $e->getMessage());
        }
       
    }

    private function publishMultipleInstagramStories($account)
    {
        $mediaItems = $this->post->media()
            ->orderBy('media_order')
            ->get();

        if ($mediaItems->isEmpty()) {
            throw new \Exception('At least one media item required for Stories.');
        }

        $token = $account->access_token;
        $igId  = $account->provider_user_id;

        $results = [];

        foreach ($mediaItems as $media) {

            $containerId = $this->createStoryContainer($igId, $token, $media);

            $this->waitForIgContainer($containerId, $token, 25);

            $publishResponse = $this->finalizeIgPublish($igId, $token, $containerId);

            $results[] = $publishResponse;

            // Optional small delay to avoid aggressive rate triggering
            sleep(2);
        }

        return $results;
    }

    private function createStoryContainer($igId, $token, $media)
    {
        // $url = asset(Storage::url($media->media_path));
        //Log::info("Story URL", [$url]);
        $params = [
            'media_type'   => 'STORIES',
            'access_token' => $token,
            'mention' => json_encode([
                [
                    'username' => 'ssgupta_1',
                    'x' => 0.3,
                    'y' => 0.4
                ]
            ]),

            'link' => 'https://co2body.com',
            'hashtag' => '#co2body'
        ];

        if ($media->media_type == 'video') {
            $url = "https://co2body.com/storage/posts/media/Yx59MEchD2JCJLidI2ia5ohWkmIIWR43nuX0dRmj.mp4";
            $params['video_url'] = $url;
            Log::info("Story Video URL", [$url]);
        } else {
            $url = "https://co2body.com/storage/posts/media/54fb848b-49ef-4843-8c11-697b171fc6db.jpg";
            $params['image_url'] = $url;
            Log::info("Story Image URL", [$url]);
        }

        $response = $this->igPost("{$igId}/media", $params);
        Log::info("Story Container Response", [$response]);
        return $response['id'] ?? null;
    }

    private function finalizeIgPublish($igId, $token, $containerId)
    {
        Log::info("Story Finalize Publish", [$containerId]);
        return $this->igPost("{$igId}/media_publish", [
            'creation_id' => $containerId,
            'access_token'=> $token,
        ]);
    }


    private function igPost($endpoint, $params)
    {
        $response = Http::timeout(60)
            ->retry(3, 2000)
            ->post("https://graph.instagram.com/v19.0/{$endpoint}", $params);

        if ($response->failed()) {
            \Log::error('Instagram API Error', [
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            throw new \Exception("Instagram API Error: " . $response->body());
        }

        return $response->json();
    }

    private function igGet($endpoint, $params)
    {
        $response = Http::timeout(30)
            ->retry(3, 1500)
            ->get("https://graph.instagram.com/v19.0/{$endpoint}", $params);

        $body = $response->json();

        Log::info("IG Story get response", $body);

        //  HTTP level failure
        if ($response->failed()) {
            Log::error('Instagram API HTTP Error', [
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            throw new \Exception("Instagram API HTTP Error: " . $response->body());
        }

        //  API level failure
        if (
            isset($body['status_code']) && $body['status_code'] === 'ERROR'
            || isset($body['error'])
        ) {
            Log::error('Instagram API Logical Error', [
                'endpoint' => $endpoint,
                'response' => $body,
            ]);

            throw new \Exception("Instagram API Logical Error: " . json_encode($body));
        }

        return $body;
    }

    // private function waitForIgContainer($containerId, $token, $maxAttempts = 25)
    // {
    //     $attempt = 1;

    //     while ($attempt <= $maxAttempts) {

    //         sleep(5);

    //         $response = $this->igGet($containerId, [
    //             'fields'       => 'status_code',
    //             'access_token' => $token,
    //         ]);

    //         Log::info("IG Story container response", [$response]);
    //         $status = $response['status_code'] ?? null;

    //         \Log::info('IG Story container status', [
    //             'container_id' => $containerId,
    //             'status'       => $status,
    //             'attempt'      => $attempt,
    //         ]);

    //         if ($status === 'FINISHED') {
    //             return true;
    //         }

    //         if ($status === 'ERROR') {
    //             throw new \Exception("Instagram Story processing failed.");
    //         }

    //         $attempt++;
    //     }

    //     throw new \Exception("Instagram Story not ready after {$maxAttempts} attempts.");
    // }

    private function waitForIgContainer($containerId, $token, $maxAttempts = 25)
    {
        $attempt = 1;

        while ($attempt <= $maxAttempts) {

            sleep(5);

            try {
                $response = $this->igGet($containerId, [
                    'fields'       => 'status_code,status',
                    'access_token' => $token,
                ]);
            } catch (\Exception $e) {
                Log::error('IG container fetch failed', [
                    'container_id' => $containerId,
                    'attempt'      => $attempt,
                    'error'        => $e->getMessage(),
                ]);

                throw $e; // stop immediately if API fails
            }

            Log::info("IG Story container response", $response);

            $status = $response['status_code'] ?? null;

            Log::info('IG Story container status', [
                'container_id' => $containerId,
                'status'       => $status,
                'attempt'      => $attempt,
            ]);

            if ($status === 'FINISHED') {
                return true;
            }

            if ($status === 'ERROR') {
                throw new \Exception("Instagram Story processing failed. Container ID: {$containerId}");
            }

            $attempt++;
        }

        throw new \Exception("Instagram Story not ready after {$maxAttempts} attempts. Container ID: {$containerId}");
    }
}
