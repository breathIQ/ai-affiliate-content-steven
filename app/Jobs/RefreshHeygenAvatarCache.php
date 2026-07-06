<?php

namespace App\Jobs;

use App\Services\HeygenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuilds the full avatar cache off the request path. Dispatched
 * whenever the cache is busted (photo avatar created/deleted) so the
 * community catalog reappears within about a minute instead of waiting
 * for the nightly heygen:refresh-avatars run.
 */
class RefreshHeygenAvatarCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 600;

    public function handle(HeygenService $heygen): void
    {
        $heygen->buildFullAvatarCache();
    }
}
