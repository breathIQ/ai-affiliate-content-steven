<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'user_id','chapter_id', 'caption', 'script', 'hastag',
        'status', 'scheduled_at', 'ai_model','ai_prompt','total_clicks','published_at',
        'media_assets'
    ];

    public function media()
    {
        return $this->hasMany(PostMedia::class);
    }

    public function platforms()
    {
        return $this->hasMany(PostPlatform::class);
    }
}
