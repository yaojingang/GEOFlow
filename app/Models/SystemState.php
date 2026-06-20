<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemState extends Model
{
    protected $table = 'system_states';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
