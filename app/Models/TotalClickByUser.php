<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TotalClickByUser extends Model
{
    protected $table = 'total_click_by_users';
    protected $fillable = [
        'user_id',
        'total_clicks',
    ];
}
