<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'user_id','chapter_id', 'campaign_id', 'caption', 'script', 'hastag',
        'status', 'scheduled_at', 'ai_model','ai_prompt','ai_generation_params','total_clicks','published_at','affiliate_url',
        'media_assets','chapter_name','chapter_title',
        'requires_human_review','review_status','review_note','review_reasons','compliance_meta','reviewed_by','reviewed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'requires_human_review' => 'boolean',
        'review_reasons' => 'array',
        'compliance_meta' => 'array',
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

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * True when a campaign post is flagged and not yet approved, so it must
     * not publish. Always false for book posts (campaign_id null).
     */
    public function isBlockedByReview(): bool
    {
        return $this->campaign_id
            && $this->requires_human_review
            && $this->review_status !== 'approved';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
