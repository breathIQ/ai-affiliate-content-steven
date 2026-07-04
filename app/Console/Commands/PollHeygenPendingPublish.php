<?php

namespace App\Console\Commands;

use App\Models\HeygenGeneration;
use App\Services\HeygenGenerationPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollHeygenPendingPublish extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'heygen:poll-pending-publish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Advance HeyGen generations tied to a pending-render post (publish/schedule chosen without review), independent of any browser polling';

    public function handle(HeygenGenerationPoller $poller)
    {
        $generations = HeygenGeneration::whereNotIn('status', ['completed', 'failed'])
            ->whereHas('post', fn ($q) => $q->where('status', 'pending_render'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $poller->advance($generation);
            } catch (\Throwable $e) {
                Log::error('heygen:poll-pending-publish failed for a generation', [
                    'generation_id' => $generation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$generations->count()} pending HeyGen generation(s).");
    }
}
