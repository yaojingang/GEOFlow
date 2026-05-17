<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoKeyword extends Model
{
    protected $table = 'geo_keywords';

    protected $fillable = [
        'organization_id',
        'type',
        'keyword',
        'intent',
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

    public function questions(): HasMany
    {
        return $this->hasMany(GeoTaskQuestion::class);
    }
}
