<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeygenUserFavoriteAvatar extends Model
{
    protected $fillable = [
        'user_id',
        'avatar_id',
        'avatar_name',
        'preview_image_url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
