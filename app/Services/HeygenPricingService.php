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

    /**
     * One-time credit cost to create/store a personal voice clone. Flat,
     * config-driven - creation cost (a HeyGen clone call plus a short fal
     * synthesis at generation time) doesn't vary enough to meter.
     */
    public function creditsForVoiceClone(): int
    {
        return (int) config('services.credits.voice_clone_cost_credits');
    }

    /**
     * Credits for a Chatterbox synthesis of a script of the given character
     * count: fal's per-1k-char cost, marked up, converted to credits. This
     * is added on top of creditsForDuration() for talking-head videos. Always
     * at least 1 credit so a short line still carries a nominal charge.
     */
    public function creditsForSynthesis(int $chars): int
    {
        $costCents = ($chars / 1000) * (float) config('services.credits.chatterbox_cost_cents_per_1k_chars');
        $priceCents = $costCents * (float) config('services.credits.heygen_margin_multiplier');
        $ourCredits = $priceCents / (float) config('services.credits.price_cents_per_credit');

        return max((int) ceil($ourCredits), 1);
    }
}
