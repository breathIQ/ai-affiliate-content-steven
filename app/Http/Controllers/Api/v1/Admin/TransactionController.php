<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\CreditTransaction;
use Illuminate\Http\Request;

class TransactionController extends ResponseController
{
    /**
     * All credit transactions across the platform, newest first, with the
     * user attached. Supports ?user_id= (per-user view), ?type= and
     * ?search= (user name/email) filters.
     */
    public function index(Request $request)
    {
        $query = CreditTransaction::query()
            ->with('user:id,name,email')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->whereHas('user', function ($sub) use ($search) {
                    $sub->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc');

        $perPage = $request->get('per_page', 20);
        $transactions = $query->paginate($perPage);

        $priceCents = (int) config('services.credits.price_cents_per_credit');

        $transactions->getCollection()->transform(function ($t) use ($priceCents) {
            $isMoney = in_array($t->type, ['purchase', 'auto_recharge']);

            return [
                'id' => $t->id,
                'user' => $t->user ? [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'email' => $t->user->email,
                ] : null,
                'type' => $t->type,
                'credits' => (int) $t->credits,
                'balance_after' => (int) $t->balance_after,
                // Transactions store credits, not dollars; purchases are
                // valued at the current per-credit price (same derivation
                // the Stats page uses).
                'amount_cents' => $isMoney ? (int) $t->credits * $priceCents : null,
                'description' => $t->description,
                'stripe_payment_intent_id' => $t->stripe_payment_intent_id,
                'reference_type' => $t->reference_type ? class_basename($t->reference_type) : null,
                'reference_id' => $t->reference_id,
                'created_at' => $t->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return $this->sendResponse($transactions, 'Transactions fetched successfully.', 200);
    }
}
