<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Simple atomic mutex preventing two concurrent low-balance
            // deductions from both triggering a Stripe charge at once.
            $table->timestamp('auto_recharge_locked_at')->nullable()->after('auto_recharge_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_recharge_locked_at');
        });
    }
};
