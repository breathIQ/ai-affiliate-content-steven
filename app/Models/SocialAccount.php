<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'username',
        'access_token',
        'refresh_token',
        'token_expires_at'
    ];
}
