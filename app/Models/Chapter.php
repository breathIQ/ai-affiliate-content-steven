<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = [
        'book_id',
        'chapter_number',
        'chapter_title',
        'content',
    ];

}
