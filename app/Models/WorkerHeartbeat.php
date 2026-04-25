<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerHeartbeat extends Model
{
    protected $table = 'worker_heartbeats';

    protected $primaryKey = 'worker_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'worker_id',
        'status',
        'last_seen_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
