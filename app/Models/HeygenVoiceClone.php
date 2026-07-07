<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeygenVoiceClone extends Model
{
    protected $fillable = [
        'user_id', 'name', 'status', 'heygen_voice_id',
        'reference_audio_url', 'reference_audio_path', 'error_message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
