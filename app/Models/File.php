<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected $fillable = [
        'original_name',
        'file_path',
        'mime_type',
        'pages',
        'words',
        'status',
    ];

    protected $casts = [
        'pages' => 'integer',
        'words' => 'integer',
        'status' => 'integer',
    ];
}
