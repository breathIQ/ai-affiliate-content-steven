<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Automation campaigns: a user-defined sequence of content steps (day 1 post
// carousel about ch.6, day 2 HeyGen video about ch.8, ...) generated and
// published automatically by the automation:run-due command.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->date('start_date');
            $table->json('platforms'); // ["instagram","tiktok",...] applied to every step
            $table->json('defaults')->nullable(); // avatar_id, voice_id, image_model, model...
            $table->timestamps();
        });

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('day_number'); // day 1 = start_date
            $table->time('run_at_time')->default('09:00:00');
            $table->enum('content_type', ['carousel_images', 'heygen_video', 'image_to_video', 'product_promo']);
            $table->unsignedBigInteger('chapter_id')->nullable(); // book content steps
            $table->string('campaign_slug')->nullable(); // product_promo steps
            $table->json('params')->nullable(); // slides, duration_seconds, prompt hints...
            // pending -> running -> queued (handed to publish pipeline) | review
            // (promo held for compliance review) | failed | skipped
            $table->enum('status', ['pending', 'running', 'queued', 'review', 'failed', 'skipped'])->default('pending');
            $table->dateTime('scheduled_for'); // start_date + (day_number-1) @ run_at_time
            $table->unsignedBigInteger('post_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_campaigns');
    }
};
