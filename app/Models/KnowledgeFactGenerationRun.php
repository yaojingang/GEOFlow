<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeFactGenerationRun extends Model
{
    protected $fillable = ['library_id', 'mode', 'target_count', 'source_hash', 'base_working_version', 'status', 'ai_model_id', 'created_by_admin_id', 'request_key', 'active_key', 'job_batch_id', 'prompt_version', 'result_json', 'batch_meta_json', 'coverage_json', 'usage_json', 'result_hash', 'error_code', 'error_message', 'cancel_requested_at', 'started_at', 'completed_at', 'failed_at', 'cancelled_at', 'diagnostic_payload_pruned_at'];

    protected $attributes = ['status' => 'queued', 'prompt_version' => 'knowledge-facts-1.0.0'];

    protected function casts(): array
    {
        return ['library_id' => 'integer', 'target_count' => 'integer', 'base_working_version' => 'integer', 'result_json' => 'array', 'batch_meta_json' => 'array', 'coverage_json' => 'array', 'usage_json' => 'array', 'cancel_requested_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime', 'diagnostic_payload_pruned_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibrary::class, 'library_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
