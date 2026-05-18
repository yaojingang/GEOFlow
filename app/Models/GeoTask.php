<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoTask extends Model
{
    protected $table = 'geo_tasks';

    protected $fillable = [
        'organization_id',
        'brand_profile_id',
        'created_by_admin_id',
        'name',
        'status',
        'total_score',
        'points_cost',
        'report_mode',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'brand_profile_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'total_score' => 'integer',
            'points_cost' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function brandProfile(): BelongsTo
    {
        return $this->belongsTo(BrandProfile::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(GeoTaskQuestion::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GeoAnswer::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(GeoReport::class);
    }
}
