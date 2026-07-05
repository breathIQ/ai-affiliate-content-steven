<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything needed to re-run image generation for a post with the
     * same approved text (chapter, model, design choices, approved_text,
     * image engine) - saved when the draft is auto-created so "Regenerate
     * image" can keep the words and only redo the picture.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('ai_generation_params')->nullable()->after('ai_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('ai_generation_params');
        });
    }
};
