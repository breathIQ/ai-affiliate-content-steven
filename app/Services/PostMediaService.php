<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostMediaService
{
    /**
     * Reads $request->input('media') (each item: an uploaded file, a URL to
     * download, or a base64 data: string) and creates a PostMedia row for
     * each one that resolves successfully. Unsupported/failed items are
     * silently skipped - a carousel can partially fail without blocking the
     * slides that did succeed. Moved out of PostController so store(),
     * updateMedia(), and PostAutoPublishService can all share it.
     */
    public function attachFromRequest(Post $post, Request $request): void
    {
        if (! $request->input('media')) {
            return;
        }

        foreach ($request->input('media') as $index => $mediaItem) {
            $order = $mediaItem['media_order'] ?? 0;
            $uploadedFile = $request->file("media.$index.file");

            if ($uploadedFile instanceof UploadedFile) {
                $this->attachUploadedFile($post, $uploadedFile, $order);
                continue;
            }

            if (is_string($mediaItem['file']) && filter_var($mediaItem['file'], FILTER_VALIDATE_URL)) {
                $this->attachFromUrl($post, $mediaItem['file'], $order);
                continue;
            }

            if (is_string($mediaItem['file']) && str_starts_with($mediaItem['file'], 'data:')) {
                $this->attachBase64($post, $mediaItem['file'], $order);
            }
        }
    }

    protected function attachUploadedFile(Post $post, UploadedFile $file, int $order): void
    {
        if (! $file->isValid()) {
            return;
        }

        $mediaType = $this->mediaTypeFromMime($file->getMimeType());

        if (! $mediaType) {
            return;
        }

        $path = $file->store('posts/media', 'public');

        $post->media()->create([
            'media_type' => $mediaType,
            'media_path' => $path,
            'media_order' => $order,
        ]);
    }

    /**
     * Download a media URL and attach it to a post. Used both by
     * attachFromRequest above (image/video URLs already hosted elsewhere)
     * and by PostAutoPublishService once a HeyGen/Grok render finishes -
     * that path runs from a console command, with no HTTP request in scope
     * at all, so this can't depend on $request.
     */
    public function attachFromUrl(Post $post, string $url, int $order = 0): void
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::error('Failed to download media - non-successful response', ['url' => $url, 'status' => $response->status()]);
                return;
            }

            $mime = $response->header('Content-Type');
            $mediaType = $this->mediaTypeFromMime($mime);

            if (! $mediaType) {
                Log::error('Unsupported media type when downloading media URL', ['url' => $url, 'mime' => $mime]);
                return;
            }

            $extension = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'video/mp4' => 'mp4',
                default => 'jpg',
            };

            $filename = Str::uuid().'.'.$extension;
            $path = "posts/media/$filename";

            Storage::disk('public')->writeStream($path, fopen($url, 'r'));

            if (str_contains($url, 'posts/temp/')) {
                $tempPath = 'posts/temp/'.basename($url);

                if (Storage::disk('public')->exists($tempPath)) {
                    Storage::disk('public')->delete($tempPath);
                }
            }

            $post->media()->create([
                'media_type' => $mediaType,
                'media_path' => $path,
                'media_order' => $order,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to download media: '.$e->getMessage(), ['url' => $url]);
        }
    }

    protected function attachBase64(Post $post, string $data, int $order): void
    {
        preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/', $data, $matches);

        if (count($matches) !== 3) {
            Log::error('Invalid base64 data for post media');
            return;
        }

        $mime = $matches[1];
        $base64Data = preg_replace('/\s+/', '', $matches[2]);
        $mediaType = $this->mediaTypeFromMime($mime);

        if (! $mediaType) {
            Log::error('Unsupported media type: '.$mime);
            return;
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => 'jpg',
        };

        $binaryData = base64_decode($base64Data, true);

        if ($binaryData === false) {
            Log::error('Base64 decode failed for mediaItem');
            return;
        }

        $filename = Str::uuid().'.'.$extension;
        $path = "posts/media/$filename";

        Storage::disk('public')->put($path, $binaryData);

        if (! Storage::disk('public')->exists($path)) {
            Log::error("Failed to store base64 image at $path");
            return;
        }

        $post->media()->create([
            'media_type' => $mediaType,
            'media_path' => $path,
            'media_order' => $order,
        ]);
    }

    protected function mediaTypeFromMime(?string $mime): ?string
    {
        if (! $mime) {
            return null;
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return null;
    }

    /**
     * Delete all existing media rows+files for a post - used before
     * replacing a draft's media (updateMedia) so nothing orphaned is left
     * on disk.
     */
    public function clearExisting(Post $post): void
    {
        foreach ($post->media as $media) {
            if (Storage::disk('public')->exists($media->media_path)) {
                Storage::disk('public')->delete($media->media_path);
            }
        }

        $post->media()->delete();
    }
}
