<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeygenPhotoAvatar extends Model
{
    protected $fillable = [
        'user_id', 'group_id', 'look_id', 'name', 'status', 'preview_image_url', 'error_message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
