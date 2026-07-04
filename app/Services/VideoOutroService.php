<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoOutroService
{
    /**
     * HeyGen's Video Agent has no reliable way to force a specific closing
     * frame - it only "autonomously determines visual composition" from a
     * prompt, the same reliability problem already visible in
     * AiPostGenerationController's many attempts to force AI compliance on
     * book cover placement. So instead of asking the AI, we deterministically
     * append the cover ourselves after HeyGen finishes rendering.
     */
    public function isAvailable(): bool
    {
        $ffmpeg = config('services.heygen.ffmpeg_path');

        return $ffmpeg && is_executable($ffmpeg);
    }

    /**
     * Downloads the HeyGen video and appends a fixed-length clip of the book
     * cover to the end. Returns a public URL to the combined file, or null
     * if ffmpeg isn't available or processing fails - callers should fall
     * back to serving the original HeyGen video_url in that case, rather
     * than failing the whole generation over an outro problem.
     */
    public function appendCoverOutro(string $sourceVideoUrl, string $orientation = 'portrait'): ?string
    {
        if (! $this->isAvailable()) {
            Log::warning('ffmpeg not configured/executable - skipping video outro, serving raw HeyGen video', [
                'configured_path' => config('services.heygen.ffmpeg_path'),
            ]);

            return null;
        }

        $workDir = storage_path('app/tmp/heygen-outro-'.Str::uuid());
        mkdir($workDir, 0755, true);

        try {
            $sourcePath = "{$workDir}/source.mp4";
            $this->download($sourceVideoUrl, $sourcePath);

            [$width, $height] = $orientation === 'landscape' ? [1920, 1080] : [1080, 1920];
            $outroClip = $this->getOrBuildOutroClip($orientation, $width, $height);

            $outputPath = "{$workDir}/final.mp4";

            $this->run([
                config('services.heygen.ffmpeg_path'), '-y',
                '-i', $sourcePath,
                '-i', $outroClip,
                '-filter_complex',
                "[0:v]scale={$width}:{$height},setsar=1[v0];[1:v]scale={$width}:{$height},setsar=1[v1];[v0][0:a][v1][1:a]concat=n=2:v=1:a=1[outv][outa]",
                '-map', '[outv]', '-map', '[outa]',
                '-c:v', 'libx264', '-c:a', 'aac', '-movflags', '+faststart',
                $outputPath,
            ]);

            $filename = 'heygen-videos/'.Str::uuid().'.mp4';
            Storage::disk('public')->put($filename, file_get_contents($outputPath));

            return Storage::disk('public')->url($filename);
        } catch (\Throwable $e) {
            Log::error('Video outro processing failed, falling back to raw HeyGen video', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $this->rrmdir($workDir);
        }
    }

    /**
     * The cover clip (image + silence, scaled/padded to the right
     * resolution) is identical every time for a given orientation, so it's
     * rendered once and reused rather than re-encoded per video.
     */
    protected function getOrBuildOutroClip(string $orientation, int $width, int $height): string
    {
        $cached = storage_path("app/heygen/outro-{$orientation}.mp4");

        if (file_exists($cached)) {
            return $cached;
        }

        if (! is_dir(dirname($cached))) {
            mkdir(dirname($cached), 0755, true);
        }

        $coverImage = config('services.heygen.outro_image_path')
            ?: Storage::disk('public')->path('assets/cover-image.png');
        $duration = (float) config('services.heygen.outro_duration_seconds', 3);

        $this->run([
            config('services.heygen.ffmpeg_path'), '-y',
            '-loop', '1', '-i', $coverImage,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-t', (string) $duration,
            '-vf', "scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1",
            '-c:v', 'libx264', '-c:a', 'aac', '-shortest',
            $cached,
        ]);

        return $cached;
    }

    protected function download(string $url, string $destination): void
    {
        $response = Http::timeout(120)->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to download source video for outro processing.');
        }

        file_put_contents($destination, $response->body());
    }

    protected function run(array $command): void
    {
        $result = Process::timeout(180)->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('ffmpeg command failed: '.$result->errorOutput());
        }
    }

    protected function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
