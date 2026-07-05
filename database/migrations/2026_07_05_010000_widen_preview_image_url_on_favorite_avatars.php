<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Photo-avatar looks ("My avatars") have signed preview URLs ~700
     * characters long - far past the default VARCHAR(255) - so favoriting
     * one failed with "Data too long for column preview_image_url".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE heygen_user_favorite_avatars MODIFY preview_image_url TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE heygen_user_favorite_avatars MODIFY preview_image_url VARCHAR(255) NULL');
    }
};
