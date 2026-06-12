<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteThemeReplicationLog extends Model
{
    protected $fillable = [
        'replication_id',
        'level',
        'step',
        'message',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'replication_id' => 'integer',
            'context_json' => 'array',
        ];
    }

    public function replication(): BelongsTo
    {
        return $this->belongsTo(SiteThemeReplication::class, 'replication_id');
    }
}
