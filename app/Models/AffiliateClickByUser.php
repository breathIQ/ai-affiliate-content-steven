<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateClickByUser extends Model
{
    protected $table = 'affiliate_click_by_users';
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'referrer',
        'platform',
        'device',
    ];
}
