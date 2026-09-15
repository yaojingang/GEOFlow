<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeRelease extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin_id' => 'integer', 'binding_version' => 'integer', 'changes' => 'array'];
    }
}
