<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'user_id','chapter_id', 'caption', 'script', 'hastag',
        'status', 'scheduled_at', 'ai_model','ai_prompt','total_clicks','published_at','affiliate_url',
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

    public function affiliateClicks()
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
