<?php

namespace App\Services;

class HeygenPricingService
{
    /**
     * Credits held upfront before a generation starts, since the actual
     * video duration (and therefore actual cost) isn't known until HeyGen
     * finishes rendering. Sized to cover heygen_estimated_max_seconds.
     */
    public function estimatedHoldCredits(): int
    {
        return $this->creditsForDuration((float) config('services.credits.heygen_estimated_max_seconds'));
    }

    /**
     * Convert a video duration into our own app credits: HeyGen's real cost
     * for that duration, marked up, converted from cents to credits.
     */
    public function creditsForDuration(float $seconds): int
    {
        $costCents = $seconds * (float) config('services.credits.heygen_cost_cents_per_second');
        $priceCents = $costCents * (float) config('services.credits.heygen_margin_multiplier');
        $ourCredits = $priceCents / (float) config('services.credits.price_cents_per_credit');

        return max((int) ceil($ourCredits), (int) config('services.credits.heygen_minimum_charge_credits'));
    }
}
