<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    protected $fillable = [
        'idempotency_key',
        'route_key',
        'request_hash',
        'response_body',
        'response_status',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
        ];
    }
}
