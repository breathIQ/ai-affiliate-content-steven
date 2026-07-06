<?php

namespace App\Console\Commands;

use App\Services\HeygenService;
use Illuminate\Console\Command;

class RefreshHeygenAvatars extends Command
{
    protected $signature = 'heygen:refresh-avatars';

    protected $description = 'Rebuild the cached avatar list including the full public/UGC/community photo-avatar catalog (too slow to fetch inside a web request)';

    public function handle(HeygenService $heygen): int
    {
        $avatars = collect($heygen->buildFullAvatarCache());

        $this->info(sprintf(
            'Avatar cache rebuilt: %d total (%d own, %d community, %d stock)',
            $avatars->count(),
            $avatars->where('is_my_avatar', true)->count(),
            $avatars->where('is_community', true)->count(),
            $avatars->filter(fn ($a) => ! ($a['is_my_avatar'] ?? false) && ! ($a['is_community'] ?? false))->count()
        ));

        return self::SUCCESS;
    }
}
