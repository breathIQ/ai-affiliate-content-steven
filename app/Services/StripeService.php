<?php

namespace App\Services;

use App\Models\User;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;

class StripeService
{
    // Nullable rather than a plain StripeClient: this service is
    // constructor-injected into BillingController, and StripeClient itself
    // throws if given a null secret - so a non-nullable type here would
    // fatal the *entire controller* (including endpoints that don't need
    // Stripe, like reading a cached balance) whenever STRIPE_SECRET isn't
    // set yet. Left null, only the specific request that actually calls
    // into Stripe fails, with a clear "call on null" error instead of a
    // confusing crash on unrelated endpoints.
    protected ?StripeClient $client;

    public function __construct()
    {
        $secret = config('services.stripe.secret');
        $this->client = $secret ? new StripeClient($secret) : null;
    }

    public function getOrCreateCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        // forceFill: stripe_customer_id is deliberately excluded from
        // User::$fillable so it can never be set via a public request.
        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * Create a SetupIntent so the frontend can collect and save a card via
     * Stripe.js/Elements without charging it yet. Used both for the first
     * card-on-file and for updating the saved card later.
     */
    public function createSetupIntent(User $user): SetupIntent
    {
        $customerId = $this->getOrCreateCustomer($user);

        return $this->client->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session', // needed so we can charge it later for auto-recharge
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ]);
    }

    public function setDefaultPaymentMethod(User $user, string $paymentMethodId): PaymentMethod
    {
        $customerId = $this->getOrCreateCustomer($user);

        $paymentMethod = $this->client->paymentMethods->retrieve($paymentMethodId);

        if ($paymentMethod->customer !== $customerId) {
            $this->client->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        }

        $this->client->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $user->forceFill(['stripe_payment_method_id' => $paymentMethodId])->save();

        return $paymentMethod;
    }

    /**
     * Charge the user's saved card on-session (during an active request from
     * the user themselves, e.g. a manual "buy credits" click).
     */
    public function chargeOnSession(User $user, int $amountCents, string $description): PaymentIntent
    {
        $customerId = $this->getOrCreateCustomer($user);

        return $this->client->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => 'usd',
            'customer' => $customerId,
            'payment_method' => $user->stripe_payment_method_id,
            'off_session' => false,
            'confirm' => true,
            'description' => $description,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ]);
    }

    /**
     * Charge the user's saved card off-session, for auto-recharge triggered
     * by the system rather than a direct user action. Throws if the card
     * requires additional authentication (caller should disable auto-recharge
     * and notify the user in that case).
     */
    public function chargeOffSession(User $user, int $amountCents, string $description): PaymentIntent
    {
        if (! $user->stripe_payment_method_id) {
            throw new \RuntimeException('User has no saved payment method for auto-recharge.');
        }

        $customerId = $this->getOrCreateCustomer($user);

        return $this->client->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => 'usd',
            'customer' => $customerId,
            'payment_method' => $user->stripe_payment_method_id,
            'off_session' => true,
            'confirm' => true,
            'description' => $description,
        ]);
    }

    public function client(): StripeClient
    {
        return $this->client;
    }
}
