<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionChannelOperation extends Model
{
    protected $fillable = [
        'distribution_channel_id',
        'token',
        'operation',
        'started_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'distribution_channel_id' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }
}
