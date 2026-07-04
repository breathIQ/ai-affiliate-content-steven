<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heygen_favorite_avatars', function (Blueprint $table) {
            $table->id();
            $table->string('avatar_id')->unique();
            $table->string('avatar_name');
            $table->string('preview_image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_favorite_avatars');
    }
};
