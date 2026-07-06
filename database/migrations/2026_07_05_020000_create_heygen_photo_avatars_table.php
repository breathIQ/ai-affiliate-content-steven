<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-created HeyGen photo avatars (selfie -> avatar). All avatars
     * live in the single shared HeyGen account, so this table is what
     * scopes visibility: a group claimed here is shown only to its
     * creating user, while unclaimed groups (curated ones made directly
     * in HeyGen's UI) stay visible to everyone.
     */
    public function up(): void
    {
        Schema::create('heygen_photo_avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('group_id')->unique();
            $table->string('look_id')->nullable();
            $table->string('name');
            $table->string('status')->default('pending'); // pending | ready | failed
            $table->text('preview_image_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_photo_avatars');
    }
};
