<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'owner_admin_id',
        'plan_code',
        'points',
        'balance',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_admin_id' => 'integer',
            'points' => 'integer',
            'balance' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function brandProfiles(): HasMany
    {
        return $this->hasMany(BrandProfile::class);
    }

    public function geoKeywords(): HasMany
    {
        return $this->hasMany(GeoKeyword::class);
    }

    public function geoTasks(): HasMany
    {
        return $this->hasMany(GeoTask::class);
    }

    public function pointLogs(): HasMany
    {
        return $this->hasMany(PointLog::class);
    }
}
