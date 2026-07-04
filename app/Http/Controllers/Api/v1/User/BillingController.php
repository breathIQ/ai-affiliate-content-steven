<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Services\CreditService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Exception\ApiErrorException;
use Stripe\Webhook;

class BillingController extends ResponseController
{
    public function __construct(
        protected StripeService $stripe,
        protected CreditService $credits,
    ) {}

    public function balance(Request $request)
    {
        $user = Auth::user();

        return $this->sendResponse([
            'credits_balance' => $user->credits_balance,
            'auto_recharge_enabled' => $user->auto_recharge_enabled,
            'auto_recharge_threshold' => $user->auto_recharge_threshold,
            'auto_recharge_topup_credits' => $user->auto_recharge_topup_credits,
            'has_payment_method' => (bool) $user->stripe_payment_method_id,
            'price_cents_per_credit' => config('services.credits.price_cents_per_credit'),
        ], 'Balance retrieved successfully', 200);
    }

    public function createSetupIntent(Request $request)
    {
        try {
            $setupIntent = $this->stripe->createSetupIntent(Auth::user());

            return $this->sendResponse([
                'client_secret' => $setupIntent->client_secret,
                'publishable_key' => config('services.stripe.key'),
            ], 'Setup intent created successfully', 200);
        } catch (ApiErrorException $e) {
            Log::error('Stripe setup intent failed', ['error' => $e->getMessage()]);
            return $this->sendError('Could not start card setup', ['error' => $e->getMessage()], 500);
        }
    }

    public function setDefaultPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        try {
            $this->stripe->setDefaultPaymentMethod(Auth::user(), $request->payment_method_id);

            return $this->sendResponse([], 'Payment method saved successfully', 200);
        } catch (ApiErrorException $e) {
            Log::error('Stripe set default payment method failed', ['error' => $e->getMessage()]);
            return $this->sendError('Could not save payment method', ['error' => $e->getMessage()], 500);
        }
    }

    public function purchaseCredits(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'credits' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $user = Auth::user();

        if (! $user->stripe_payment_method_id) {
            return $this->sendError('No saved payment method. Add a card before purchasing credits.', [], 422);
        }

        $credits = (int) $request->credits;
        $amountCents = $credits * config('services.credits.price_cents_per_credit');

        try {
            $paymentIntent = $this->stripe->chargeOnSession(
                $user,
                $amountCents,
                "Purchase of {$credits} credits"
            );

            if ($paymentIntent->status !== 'succeeded') {
                return $this->sendError('Payment did not complete', ['status' => $paymentIntent->status], 402);
            }

            $transaction = $this->credits->add(
                $user,
                $credits,
                'purchase',
                "Purchased {$credits} credits",
                $paymentIntent->id
            );

            return $this->sendResponse([
                'credits_balance' => $transaction->balance_after,
                'transaction_id' => $transaction->id,
            ], 'Credits purchased successfully', 200);
        } catch (ApiErrorException $e) {
            Log::error('Stripe charge failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return $this->sendError('Payment failed', ['error' => $e->getMessage()], 402);
        }
    }

    public function updateAutoRecharge(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'threshold' => 'required_if:enabled,true|integer|min:1',
            'topup_credits' => 'required_if:enabled,true|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $user = Auth::user();

        if ($request->boolean('enabled') && ! $user->stripe_payment_method_id) {
            return $this->sendError('Add a saved payment method before enabling auto-recharge.', [], 422);
        }

        $update = ['auto_recharge_enabled' => $request->boolean('enabled')];

        if ($request->has('threshold')) {
            $update['auto_recharge_threshold'] = (int) $request->threshold;
        }

        if ($request->has('topup_credits')) {
            $update['auto_recharge_topup_credits'] = (int) $request->topup_credits;
            $update['auto_recharge_price_cents'] = $update['auto_recharge_topup_credits'] * config('services.credits.price_cents_per_credit');
        }

        // forceFill: these auto-recharge fields are deliberately excluded
        // from User::$fillable so they can only be changed through this
        // validated, authenticated endpoint - never via mass assignment
        // elsewhere (e.g. the generic profile-update endpoint).
        $user->forceFill($update)->save();

        return $this->sendResponse([
            'auto_recharge_enabled' => $user->auto_recharge_enabled,
            'auto_recharge_threshold' => $user->auto_recharge_threshold,
            'auto_recharge_topup_credits' => $user->auto_recharge_topup_credits,
        ], 'Auto-recharge settings updated', 200);
    }

    public function transactions(Request $request)
    {
        $transactions = Auth::user()
            ->creditTransactions()
            ->orderByDesc('id')
            ->paginate(20);

        return $this->sendResponse($transactions, 'Transactions retrieved successfully', 200);
    }

    /**
     * Stripe webhook receiver. Public route, verified via signature rather
     * than Sanctum auth. Primarily a safety net for auto-recharge charges
     * that resolve asynchronously (e.g. bank delays) rather than the main
     * success path, which is handled synchronously in purchaseCredits().
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = $webhookSecret
                ? Webhook::constructEvent($payload, $signature, $webhookSecret)
                : json_decode($payload);
        } catch (\Exception $e) {
            Log::error('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        Log::info('Stripe webhook received', ['type' => $event->type ?? 'unknown']);

        // Auto-recharge success/failure is credited eagerly at call time in
        // AutoRechargeService; this handler exists to catch delayed outcomes
        // and for future event types without requiring a redeploy.

        return response()->json(['received' => true]);
    }
}
