<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE heygen_generations MODIFY video_url TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE heygen_generations MODIFY video_url VARCHAR(255) NULL');
    }
};
