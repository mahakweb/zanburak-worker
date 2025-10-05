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
    ];

    public function videoable()
    {
        return $this->morphTo();
    }
}


