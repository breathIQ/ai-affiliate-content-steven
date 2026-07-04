<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrokVideoGeneration extends Model
{
    protected $fillable = [
        'user_id',
        'post_id',
        'grok_request_id',
        'source_image_url',
        'prompt',
        'duration_seconds',
        'status',
        'video_url',
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
