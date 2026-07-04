<?php

namespace App\Services;

class ImageGenerationPricingService
{
    /**
     * $engine is 'gemini' or 'openai' - the two image-generation backends
     * AiPostGenerationController can use, with very different real costs.
     */
    public function imageCostCredits(string $engine): int
    {
        $costCentsPerImage = $engine === 'gemini'
            ? (float) config('services.credits.image_gemini_cost_cents')
            : (float) config('services.credits.image_openai_cost_cents');

        $priceCents = $costCentsPerImage * (float) config('services.credits.image_margin_multiplier');
        $credits = $priceCents / (float) config('services.credits.price_cents_per_credit');

        return max((int) ceil($credits), (int) config('services.credits.image_minimum_charge_credits'));
    }

    public function textGenerationCostCredits(): int
    {
        return (int) config('services.credits.image_text_generation_cost_credits');
    }

    /**
     * Unlike HeyGen video duration, the number of images and which engine
     * generates them are both known upfront, so the hold equals the exact
     * cost of every slide succeeding - only refunded down if some fail.
     */
    public function estimatedHoldCredits(string $engine, int $slidesCount): int
    {
        return $this->textGenerationCostCredits() + ($this->imageCostCredits($engine) * $slidesCount);
    }
}
