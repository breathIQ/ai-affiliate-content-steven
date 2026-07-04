<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeygenGeneration extends Model
{
    protected $fillable = [
        'user_id',
        'post_id',
        'heygen_session_id',
        'heygen_video_id',
        'prompt',
        'status',
        'video_url',
        'duration_seconds',
        'credits_charged',
        'error_message',
        'request_payload',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
