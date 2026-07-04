<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoRechargeService
{
    public function __construct(
        protected StripeService $stripe,
        protected CreditService $credits,
    ) {}

    /**
     * Called after every credit deduction. Fires an off-session charge if
     * the user has auto-recharge on and just dropped below their threshold.
     * Uses an atomic lock column rather than the deducting transaction's own
     * row lock, since the Stripe API call shouldn't happen while holding a
     * DB transaction open.
     */
    public function maybeRecharge(User $user): void
    {
        $user = $user->fresh();

        if (! $user->auto_recharge_enabled || ! $user->stripe_payment_method_id) {
            return;
        }

        if ($user->credits_balance >= $user->auto_recharge_threshold) {
            return;
        }

        $acquired = DB::table('users')
            ->where('id', $user->id)
            ->whereNull('auto_recharge_locked_at')
            ->update(['auto_recharge_locked_at' => now()]);

        if (! $acquired) {
            // Another request already picked this up - avoid a double charge.
            return;
        }

        try {
            $credits = $user->auto_recharge_topup_credits;
            $amountCents = $user->auto_recharge_price_cents;

            $paymentIntent = $this->stripe->chargeOffSession(
                $user,
                $amountCents,
                "Auto-recharge: {$credits} credits"
            );

            if ($paymentIntent->status === 'succeeded') {
                $this->credits->add(
                    $user,
                    $credits,
                    'auto_recharge',
                    "Auto-recharge: {$credits} credits",
                    $paymentIntent->id
                );
            } else {
                Log::warning('Auto-recharge payment did not complete', [
                    'user_id' => $user->id,
                    'status' => $paymentIntent->status,
                ]);
            }
        } catch (\Throwable $e) {
            // Card declined, needs authentication, network error, etc. - turn
            // auto-recharge off rather than retrying against a failing card
            // on every subsequent deduction.
            Log::error('Auto-recharge failed, disabling it for this user', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $user->forceFill(['auto_recharge_enabled' => false])->save();
        } finally {
            DB::table('users')->where('id', $user->id)->update(['auto_recharge_locked_at' => null]);
        }
    }
}
