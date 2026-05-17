<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoReport extends Model
{
    protected $table = 'geo_reports';

    protected $fillable = [
        'geo_task_id',
        'title',
        'summary',
        'total_score',
        'markdown_report',
        'html_report',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'geo_task_id' => 'integer',
            'total_score' => 'integer',
        ];
    }

    public function geoTask(): BelongsTo
    {
        return $this->belongsTo(GeoTask::class);
    }
}
