<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LinkPoolThemeAdaptation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin_id' => 'integer', 'model_id' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
