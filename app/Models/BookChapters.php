<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookChapters extends Model
{
    protected $fillable = [
        'chapter',
        'chapter_title',
    ];
}
