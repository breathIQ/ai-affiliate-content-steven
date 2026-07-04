<?php

namespace App\Services;

class GrokVideoPricingService
{
    /**
     * Convert a video duration + resolution into our own app credits: the
     * real fal.ai cost for that combination (per-second rate plus a flat
     * per-image-input surcharge), marked up, converted from cents to
     * credits. Unlike HeyGen, the user picks an exact duration upfront (6 or
     * 10s), so this is the final charge - no estimate-then-reconcile step.
     */
    public function creditsForDuration(float $seconds, string $resolution = '720p'): int
    {
        $perSecondCents = $resolution === '480p'
            ? (float) config('services.credits.grok_video_cost_cents_per_second_480p')
            : (float) config('services.credits.grok_video_cost_cents_per_second_720p');

        $costCents = ($seconds * $perSecondCents) + (float) config('services.credits.grok_video_image_input_cost_cents');
        $priceCents = $costCents * (float) config('services.credits.grok_video_margin_multiplier');
        $ourCredits = $priceCents / (float) config('services.credits.price_cents_per_credit');

        return max((int) ceil($ourCredits), (int) config('services.credits.grok_video_minimum_charge_credits'));
    }
}
