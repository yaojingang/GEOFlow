<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiVisibilityCompetitorDetection extends Model
{
    protected $fillable = [
        'run_id',
        'names_json',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'names_json' => 'array',
        ];
    }
}
