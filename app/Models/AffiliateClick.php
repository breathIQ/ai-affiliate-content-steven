<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateClick extends Model
{
    protected $fillable = [
        'post_id',
        'ip_address',
        'user_agent',
        'referrer',
    ];  

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
