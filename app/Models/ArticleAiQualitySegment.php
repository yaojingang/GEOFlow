<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAiQualitySegment extends Model
{
    protected $table = 'article_ai_quality_segments';

    protected $fillable = [
        'article_ai_quality_check_id',
        'segment_index',
        'start_offset',
        'end_offset',
        'input_hash',
        'status',
        'attempt_count',
        'model_result',
        'validated_result',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'queued',
        'attempt_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'article_ai_quality_check_id' => 'integer',
            'segment_index' => 'integer',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
            'attempt_count' => 'integer',
            'model_result' => 'array',
            'validated_result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'article_ai_quality_check_id');
    }
}
