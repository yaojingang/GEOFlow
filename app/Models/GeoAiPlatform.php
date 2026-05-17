<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoAiPlatform extends Model
{
    protected $table = 'geo_ai_platforms';

    protected $fillable = [
        'name',
        'code',
        'api_mode',
        'base_url',
        'cost_per_query',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'cost_per_query' => 'integer',
            'settings' => 'array',
        ];
    }
}
