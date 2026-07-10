<?php

namespace App\Observers;

use App\Jobs\ProvisionCarbogeneticsAffiliate;
use App\Models\User;
use App\Services\CarbogeneticsAffiliateService;

class UserObserver
{
    /**
     * Every new user becomes a carbogenetics.com affiliate automatically.
     * Runs on all creation paths (email signup, social signup, admin) —
     * skipped when register() already provisioned synchronously via a
     * claimed affiliate ID, or when provisioning isn't configured.
     */
    public function created(User $user): void
    {
        if (blank($user->email) || !blank($user->other_affiliate_id)) {
            return;
        }
        if (!app(CarbogeneticsAffiliateService::class)->isConfigured()) {
            return;
        }
        ProvisionCarbogeneticsAffiliate::dispatch($user->id);
    }
}
