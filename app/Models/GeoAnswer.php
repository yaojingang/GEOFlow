<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoAnswer extends Model
{
    protected $table = 'geo_answers';

    protected $fillable = [
        'geo_task_id',
        'geo_task_question_id',
        'platform_code',
        'prompt',
        'raw_answer',
        'status',
        'error_message',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'geo_task_id' => 'integer',
            'geo_task_question_id' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    public function geoTask(): BelongsTo
    {
        return $this->belongsTo(GeoTask::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(GeoTaskQuestion::class, 'geo_task_question_id');
    }

    public function score(): HasOne
    {
        return $this->hasOne(GeoScore::class);
    }
}
