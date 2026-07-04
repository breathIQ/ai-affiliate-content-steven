<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreditService
{
    /**
     * Deduct credits from a user's balance atomically. Returns false if the
     * user does not have enough balance (no partial deduction occurs).
     *
     * Uses forceFill() rather than update() deliberately: credits_balance is
     * intentionally excluded from User::$fillable so it can never be mass
     * assigned from a public request (registration, profile update, etc).
     * This service is the only code path allowed to change it.
     */
    public function deduct(User $user, int $amount, string $description, ?object $reference = null): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive.');
        }

        $deducted = DB::transaction(function () use ($user, $amount, $description, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if ($locked->credits_balance < $amount) {
                return false;
            }

            $newBalance = $locked->credits_balance - $amount;
            $locked->forceFill(['credits_balance' => $newBalance])->save();

            CreditTransaction::create([
                'user_id' => $user->id,
                'type' => 'deduction',
                'credits' => -$amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
            ]);

            $user->credits_balance = $newBalance;

            return true;
        });

        if ($deducted) {
            // Resolved lazily via the container rather than constructor
            // injection - AutoRechargeService depends on CreditService, so
            // injecting it here directly would create a circular dependency.
            // Runs after the transaction above has committed, since the
            // Stripe API call shouldn't happen while holding a DB lock.
            app(AutoRechargeService::class)->maybeRecharge($user);
        }

        return $deducted;
    }

    /**
     * Add credits to a user's balance (purchase, auto-recharge, refund, or
     * manual adjustment) and record the transaction.
     */
    public function add(
        User $user,
        int $amount,
        string $type,
        ?string $description = null,
        ?string $stripePaymentIntentId = null,
        ?object $reference = null
    ): CreditTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $description, $stripePaymentIntentId, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            $newBalance = $locked->credits_balance + $amount;
            $locked->forceFill(['credits_balance' => $newBalance])->save();

            $transaction = CreditTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'credits' => $amount,
                'balance_after' => $newBalance,
                'stripe_payment_intent_id' => $stripePaymentIntentId,
                'description' => $description,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
            ]);

            $user->credits_balance = $newBalance;

            return $transaction;
        });
    }

    public function hasSufficientBalance(User $user, int $amount): bool
    {
        return $user->credits_balance >= $amount;
    }

    /**
     * Refund credits that were previously deducted for a failed/cancelled
     * generation, referencing the original object that triggered the charge.
     */
    public function refund(User $user, int $amount, string $description, ?object $reference = null): CreditTransaction
    {
        return $this->add($user, $amount, 'refund', $description, null, $reference);
    }
}
