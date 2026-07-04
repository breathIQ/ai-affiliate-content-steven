<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Post;
use App\Jobs\PublishPostToSocialMedia;

class PublishDuePosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'posts:publish-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch scheduled posts whose scheduled time has arrived';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $posts = Post::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($posts as $post) {
            try {
                $post->update(['status' => 'processing']);

                PublishPostToSocialMedia::dispatch($post);

                Log::info('Scheduled post dispatched for publishing', [
                    'post_id' => $post->id,
                    'scheduled_at' => $post->scheduled_at,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch scheduled post', [
                    'post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Publish-due check completed. {$posts->count()} post(s) dispatched.");
    }
}
