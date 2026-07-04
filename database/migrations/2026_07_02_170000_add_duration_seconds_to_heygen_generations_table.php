<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->decimal('duration_seconds', 8, 4)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });
    }
};
