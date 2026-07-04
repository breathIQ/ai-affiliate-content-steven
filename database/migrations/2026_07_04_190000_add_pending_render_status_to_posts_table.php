<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'scheduled', 'published', 'failed', 'processing', 'pending_render') NOT NULL DEFAULT 'processing'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'scheduled', 'published', 'failed', 'processing') NOT NULL DEFAULT 'processing'");
    }
};
