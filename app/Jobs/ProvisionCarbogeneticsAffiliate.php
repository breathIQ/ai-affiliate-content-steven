<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\CarbogeneticsAffiliateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Enrolls a user as a carbogenetics.com affiliate off the request path.
 * Dispatched from UserObserver whenever a user is created without an
 * other_affiliate_id (email signup with no claimed code, social signup,
 * or a signup where the sync provisioning call was unreachable).
 *
 * Idempotent: the provision endpoint matches by email first, so retries
 * and duplicate dispatches converge on the same affiliate row.
 */
class ProvisionCarbogeneticsAffiliate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    /** Retry over ~1.5h so a brief Vercel/API blip never loses an enrollment. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public int $userId)
    {
    }

    public function handle(CarbogeneticsAffiliateService $carbogenetics): void
    {
        if (!$carbogenetics->isConfigured()) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user || blank($user->email) || !blank($user->other_affiliate_id)) {
            return; // gone, unprovisionable, or already enrolled
        }

        $result = $carbogenetics->provision($user->email, $user->name);

        if ($result['ok']) {
            // Guard against a concurrent profile update having set it meanwhile.
            User::where('id', $user->id)
                ->whereNull('other_affiliate_id')
                ->update(['other_affiliate_id' => $result['ref_code']]);
            return;
        }

        if ($result['error'] === 'unreachable') {
            $this->release($this->backoff()[min($this->attempts() - 1, 3)]);
            return;
        }

        Log::warning('Carbogenetics affiliate provisioning gave up', [
            'user_id' => $user->id,
            'error' => $result['error'],
        ]);
    }
}
