<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagementOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin_id' => 'integer', 'task_id' => 'integer', 'task_run_id' => 'integer', 'required_scopes' => 'array', 'result' => 'array'];
    }
}
