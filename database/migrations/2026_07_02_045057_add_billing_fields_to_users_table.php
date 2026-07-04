<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('credits_balance')->default(0)->after('status');
            $table->string('stripe_customer_id')->nullable()->after('credits_balance');
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
            $table->boolean('auto_recharge_enabled')->default(false)->after('stripe_payment_method_id');
            $table->integer('auto_recharge_threshold')->default(10)->after('auto_recharge_enabled');
            $table->integer('auto_recharge_topup_credits')->default(100)->after('auto_recharge_threshold');
            $table->integer('auto_recharge_price_cents')->nullable()->after('auto_recharge_topup_credits');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'credits_balance',
                'stripe_customer_id',
                'stripe_payment_method_id',
                'auto_recharge_enabled',
                'auto_recharge_threshold',
                'auto_recharge_topup_credits',
                'auto_recharge_price_cents',
            ]);
        });
    }
};
