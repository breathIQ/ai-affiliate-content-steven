<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InviteUser extends Model
{
    protected $fillable = [
        'email',
        'token',
        'is_used',
        'expires_at',
    ];
}
