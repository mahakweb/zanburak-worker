<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'path',
        'disk',
        'duration',
        'quality',
        'videoable_id',
        'videoable_type',
        'status',
        'progress',
        'error_message',
        'processing_started_at',
    ];

    protected $casts = [
        'processing_started_at' => 'datetime',
        'progress' => 'integer',
    ];

    public function videoable()
    {
        return $this->morphTo();
    }
}


