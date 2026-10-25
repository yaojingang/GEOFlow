<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array', 'settings' => 'array', 'dependencies' => 'array', 'total_bytes' => 'integer'];
    }
}
