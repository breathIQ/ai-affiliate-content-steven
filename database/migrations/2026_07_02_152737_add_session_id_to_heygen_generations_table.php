<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->string('heygen_session_id')->nullable()->after('user_id');
            $table->text('prompt')->nullable()->after('heygen_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->dropColumn(['heygen_session_id', 'prompt']);
        });
    }
};
