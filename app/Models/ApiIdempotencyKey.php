<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    protected $attributes = [
        'fingerprint_version' => 1,
    ];

    protected $fillable = [
        'idempotency_key',
        'route_key',
        'request_hash',
        'response_body',
        'response_status',
        'fingerprint_version',
        'state',
        'owner_token',
        'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'fingerprint_version' => 'integer',
            'lease_expires_at' => 'datetime',
        ];
    }
}
