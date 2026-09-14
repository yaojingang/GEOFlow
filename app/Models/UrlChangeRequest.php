<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UrlChangeRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['nonce_hash'];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer', 'auth_version' => 'integer', 'target_id' => 'integer',
            'versions' => 'array', 'sites' => 'array', 'progress' => 'array', 'summary' => 'array',
            'expires_at' => 'datetime', 'applied_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
