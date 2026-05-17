<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoWritingTask extends Model
{
    protected $table = 'geo_writing_tasks';

    protected $fillable = [
        'organization_id',
        'geo_report_id',
        'geo_keyword_id',
        'title',
        'status',
        'brief',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_report_id' => 'integer',
            'geo_keyword_id' => 'integer',
            'brief' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function articleDrafts(): HasMany
    {
        return $this->hasMany(GeoArticleDraft::class);
    }
}
