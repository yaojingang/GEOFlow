<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRun extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'task_runs';

    protected $fillable = [
        'task_id',
        'status',
        'article_id',
        'error_message',
        'duration_ms',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'article_id' => 'integer',
            'duration_ms' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
