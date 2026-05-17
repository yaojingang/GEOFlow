<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandProfile extends Model
{
    protected $fillable = [
        'organization_id',
        'brand_name',
        'aliases',
        'products',
        'advantages',
        'cases',
        'pain_points',
        'service_area',
        'extra_facts',
        'status',
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

    public function geoTasks(): HasMany
    {
        return $this->hasMany(GeoTask::class);
    }
}
