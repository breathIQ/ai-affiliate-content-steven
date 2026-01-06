<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostPlatform extends Model
{
    protected $fillable = [
        'post_id', 'platform', 'status'
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
