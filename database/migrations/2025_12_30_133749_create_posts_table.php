<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->foreign('chapter_id')->references('id')->on('chapters')->onDelete('set null');         
            $table->text('caption')->nullable();
            $table->longText('script')->nullable();
            $table->text('hastag')->nullable();
            $table->string('ai_model')->nullable();         
            $table->longText('ai_prompt')->nullable();            // prompt, etc
            $table->integer('total_clicks')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->enum('media_assets', ['single', 'carousel'])->default('single');
            $table->enum('status', ['draft', 'scheduled', 'published'])->default('draft');
            $table->text('affiliate_url')->nullable(); 
            $table->string('chapter_name')->nullable();
            $table->text('chapter_title')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
