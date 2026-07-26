<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiVisibilityRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PROVIDER_DOUBAO_ARK_RESPONSES = 'doubao_ark_responses';

    public const PROVIDER_DOUBAO_SEARCH_CUSTOM = 'doubao_search_custom';

    public const PROVIDER_DEEPSEEK_ANALYSIS = 'deepseek_analysis';

    protected $fillable = [
        'uuid',
        'keyword',
        'prompt',
        'provider_type',
        'provider_key',
        'ai_model_id',
        'ai_source_provider_id',
        'model_id',
        'status',
        'answer_text',
        'locale',
        'latency_ms',
        'usage_json',
        'analysis_json',
        'raw_request_json',
        'raw_response_json',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_model_id' => 'integer',
            'ai_source_provider_id' => 'integer',
            'latency_ms' => 'integer',
            'usage_json' => 'array',
            'analysis_json' => 'array',
            'raw_request_json' => 'array',
            'raw_response_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiVisibilityRun $run): void {
            if (trim((string) $run->uuid) === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(AiSourceProvider::class, 'ai_source_provider_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiVisibilitySource::class, 'ai_visibility_run_id');
    }
}
