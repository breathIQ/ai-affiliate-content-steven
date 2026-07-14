<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationCampaign extends Model
{
    protected $fillable = [
        'user_id', 'name', 'status', 'start_date', 'platforms', 'defaults',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'platforms' => 'array',
            'defaults' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function steps()
    {
        return $this->hasMany(AutomationStep::class)->orderBy('day_number')->orderBy('run_at_time');
    }
}
