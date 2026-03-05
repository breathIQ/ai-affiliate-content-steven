<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('other_affiliate_id', 10)->nullable()->after('affiliate_id');

            $table->string('amazon_link')->nullable()->after('other_affiliate_id');

            $table->boolean('affiliate_id_editable')
                  ->default(1)
                  ->after('amazon_link')
                  ->comment('1 = editable, 0 = locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'other_affiliate_id',
                'amazon_link',
                'affiliate_id_editable'
            ]);
        });
    }
};
