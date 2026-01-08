<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PostPublisherService
{
    public function publishPost($post)
    {
        $authUser = Auth::user();
        $account = $authUser->socialAccounts()->where('platform', 'instagram')->first();

        if (!$account) return $this->sendError('Account not linked', [], 403);

        $accessToken = $account->access_token;
        $igUserId = $account->social_id;
        $isCarousel = $post->media_assets === 'Carousel'; // Based on your UI toggle
        
        try {
            if ($isCarousel) {
                // Carousel Logic
                $mediaFiles = $post->media; // Array of files
                $itemIds = [];

                foreach ($mediaFiles as $file) {
                    $url = $this->uploadToPublicStorage($file);
                    // Har image ka item container banayein (is-carousel-item = true)
                    $itemIds[] = $this->createInstagramItemContainer($igUserId, $accessToken, $url);
                }

                // Sabko group karke carousel banayein
                $containerId = $this->createCarouselContainer($igUserId, $accessToken, $itemIds, $request->caption);
            } else {
                // Single Media Logic
                $url = $this->uploadToPublicStorage($request->file('file'));
                $containerId = $this->createSingleContainer($igUserId, $accessToken, $url, $request->caption);
            }

            // Final Step: Publish
            return $this->finalizePublish($igUserId, $accessToken, $containerId);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function createInstagramItemContainer($igUserId, $token, $url) {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media", [
            'image_url' => $url,
            'is_carousel_item' => true, // Yeh zaroori hai carousel ke liye
            'access_token' => $token,
        ]);
        return $response->json()['id'];
    }

    private function createCarouselContainer($igUserId, $token, $itemIds, $caption) {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $itemIds), // Saare item IDs ka comma separated string
            'caption' => $caption,
            'access_token' => $token,
        ]);
        return $response->json()['id'];
    }

    private function createSingleContainer($igUserId, $token, $url, $caption) {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media", [
            'image_url' => $url,
            'caption' => $caption,
            'access_token' => $token,
        ]);
        return $response->json()['id'];
    }

    private function finalizePublish($igUserId, $token, $containerId) {
        $response = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media_publish", [
            'media_id' => $containerId,
            'access_token' => $token,
        ]);
        return $response->json();
    }
}