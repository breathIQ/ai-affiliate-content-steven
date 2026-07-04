<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heygen_user_favorite_avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('avatar_id');
            $table->string('avatar_name');
            $table->string('preview_image_url')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'avatar_id']);
        });

        // Superseded by the per-user table above - global "favorites" are
        // now derived by counting per-user favorites per avatar, rather
        // than being their own separately-curated list. Carry over the
        // handful of rows that existed under the old shared-only model
        // before dropping it.
        if (Schema::hasTable('heygen_favorite_avatars')) {
            $firstUserId = DB::table('users')->orderBy('id')->value('id');

            if ($firstUserId) {
                $existing = DB::table('heygen_favorite_avatars')->get();

                foreach ($existing as $row) {
                    DB::table('heygen_user_favorite_avatars')->insertOrIgnore([
                        'user_id' => $firstUserId,
                        'avatar_id' => $row->avatar_id,
                        'avatar_name' => $row->avatar_name,
                        'preview_image_url' => $row->preview_image_url,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }
            }

            Schema::drop('heygen_favorite_avatars');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('heygen_user_favorite_avatars');
    }
};
