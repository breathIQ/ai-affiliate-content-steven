<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-created voice clones. Like heygen_photo_avatars, every clone
     * lives in the single shared HeyGen account (heygen_voice_id) and/or
     * as a reference sample for Chatterbox (reference_audio_url), so this
     * table scopes each voice to its creating user. A "deleted" status is
     * a tombstone the same way photo avatars use one.
     *
     * Two independent capabilities are stored per voice so a user can pick,
     * per video, between "rich" mode (HeyGen Video Agent + b-roll, driven by
     * heygen_voice_id) and "talking-head" mode (open-source Chatterbox
     * synthesis from reference_audio_url):
     *  - heygen_voice_id: HeyGen native clone (null if HeyGen cloning failed
     *    or is unavailable on the plan - rich mode is then hidden for it).
     *  - reference_audio_url: persistent public URL of the user's sample,
     *    fed to Chatterbox as the zero-shot voice prompt each generation.
     */
    public function up(): void
    {
        Schema::create('heygen_voice_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('pending'); // pending | ready | failed | deleted
            $table->string('heygen_voice_id')->nullable();
            $table->text('reference_audio_url')->nullable();
            $table->text('reference_audio_path')->nullable(); // local backup copy
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_voice_clones');
    }
};
