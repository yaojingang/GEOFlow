<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeWorkspace extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['code_recovery_epoch', 'code_token_id', 'code_authorized_until', 'plan'];

    protected function casts(): array
    {
        return ['admin_id' => 'integer', 'code_token_id' => 'integer', 'lock_version' => 'integer', 'code_authorized_until' => 'datetime', 'plan' => 'array'];
    }
}
