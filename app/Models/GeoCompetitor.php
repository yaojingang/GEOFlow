<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoCompetitor extends Model
{
    protected $table = 'geo_competitors';

    protected $fillable = [
        'organization_id',
        'name',
        'aliases',
        'website',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'aliases' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
