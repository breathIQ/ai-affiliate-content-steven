<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the post actually lives on the platform, captured at publish
     * time. external_post_id is the platform's own id (IG media id, TikTok
     * post id, or the TikTok publish handle while processing);
     * external_url is a direct permalink when the platform gives us one.
     * Both are best-effort and nullable - posts published before this
     * migration simply have neither.
     */
    public function up(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->string('external_post_id', 191)->nullable()->after('clicks');
            $table->text('external_url')->nullable()->after('external_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->dropColumn(['external_post_id', 'external_url']);
        });
    }
};
