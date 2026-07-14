<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationStep extends Model
{
    protected $fillable = [
        'automation_campaign_id', 'day_number', 'run_at_time', 'content_type',
        'chapter_id', 'campaign_slug', 'params', 'status', 'scheduled_for',
        'post_id', 'error', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'scheduled_for' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(AutomationCampaign::class, 'automation_campaign_id');
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
