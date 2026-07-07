<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes the two generation pipelines that now share this table:
     *  - 'agent': the Video Agent (/v3/video-agents), a two-stage session ->
     *    video_id flow, optionally driven by a cloned HeyGen voice_id.
     *  - 'audio': a Chatterbox-synthesised voice driving a photo avatar via
     *    /v2/video/generate, which returns a video_id synchronously (no
     *    session) and is polled the same way once that id exists.
     * The poller branches on this to pick the right status endpoint and to
     * skip duration reconciliation for audio mode (its charge bundles a
     * fixed synthesis cost that reconciliation must not refund away).
     */
    public function up(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->string('generation_mode')->default('agent')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('heygen_generations', function (Blueprint $table) {
            $table->dropColumn('generation_mode');
        });
    }
};
