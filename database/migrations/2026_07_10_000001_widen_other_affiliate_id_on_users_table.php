<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// other_affiliate_id was string(10) digits-only for the old AffiliateWP
// numeric IDs. The new carbogenetics.com affiliate system uses text ref
// codes (up to 32 chars, [a-zA-Z0-9_-]), so the column must fit those.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('other_affiliate_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('other_affiliate_id', 10)->nullable()->change();
        });
    }
};
