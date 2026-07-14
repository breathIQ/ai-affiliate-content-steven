<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostPlatform extends Model
{
    protected $fillable = [
        'post_id', 'platform', 'status', 'tiktok_payload',
        'external_post_id', 'external_url',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
