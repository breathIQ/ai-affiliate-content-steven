<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grok_video_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('grok_request_id')->nullable();
            $table->text('source_image_url');
            $table->text('prompt')->nullable();
            $table->unsignedTinyInteger('duration_seconds');
            $table->string('status')->default('pending'); // pending, done, failed, expired
            $table->text('video_url')->nullable();
            $table->integer('credits_charged')->default(0);
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grok_video_generations');
    }
};
