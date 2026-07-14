<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('instagram:refresh-tokens')->dailyAt('02:00');

Schedule::command('tiktok:refresh-tokens')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('posts:publish-due')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('heygen:poll-pending-publish')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('grok:poll-pending-publish')
    ->everyMinute()
    ->withoutOverlapping();

// Fire any automation-campaign step whose scheduled time has arrived
// (day-N sequences of auto-generated, auto-published content).
Schedule::command('automation:run-due')
    ->everyMinute()
    ->withoutOverlapping();

// Full avatar-list rebuild (stock + own + the public/UGC/community
// photo-avatar catalog). Hundreds of HeyGen calls, so it lives here
// rather than in any web request; users always read the warm cache.
Schedule::command('heygen:refresh-avatars')
    ->dailyAt('04:00')
    ->withoutOverlapping();