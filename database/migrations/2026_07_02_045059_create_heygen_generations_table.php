<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heygen_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('heygen_video_id')->nullable();
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->string('video_url')->nullable();
            $table->integer('credits_charged')->default(0);
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_generations');
    }
};
