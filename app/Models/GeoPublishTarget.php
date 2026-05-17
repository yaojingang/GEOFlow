<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoPublishTarget extends Model
{
    protected $table = 'geo_publish_targets';

    protected $fillable = [
        'organization_id',
        'type',
        'name',
        'endpoint',
        'encrypted_token',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
