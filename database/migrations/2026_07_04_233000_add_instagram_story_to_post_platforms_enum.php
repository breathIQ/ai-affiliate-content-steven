<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen the platform enum so a post can also target Instagram Stories.
     * Stories publish through the same connected Instagram account but use
     * a different API media_type, so they're tracked as their own platform
     * row (own status/published_at/clicks) rather than a flag on instagram.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE post_platforms MODIFY platform ENUM('instagram', 'instagram_story', 'tiktok') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM post_platforms WHERE platform = 'instagram_story'");
        DB::statement("ALTER TABLE post_platforms MODIFY platform ENUM('instagram', 'tiktok') NOT NULL");
    }
};
