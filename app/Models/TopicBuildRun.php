<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicBuildRun extends Model
{
    protected $fillable = ['dispatch_key', 'telemetry', 'request_key', 'site_key', 'topic_id', 'task_id', 'task_run_id', 'title_id', 'batch_id', 'row_number', 'owner_admin_id', 'identity', 'model_id', 'publication_completed_at', 'expected_execution_lease_token', 'expected_version', 'expected_control_version', 'config_version', 'input', 'status', 'phase', 'result', 'error', 'lease_token', 'lease_expires_at', 'finished_at'];

    protected $hidden = ['dispatch_key', 'identity', 'expected_execution_lease_token', 'lease_token', 'input', 'result'];

    protected function casts(): array
    {
        return ['topic_id' => 'integer', 'task_id' => 'integer', 'task_run_id' => 'integer', 'title_id' => 'integer', 'batch_id' => 'integer', 'owner_admin_id' => 'integer', 'model_id' => 'integer', 'telemetry' => 'array', 'identity' => 'array', 'input' => 'array', 'result' => 'array', 'expected_control_version' => 'integer', 'expected_version' => 'integer', 'config_version' => 'integer', 'lease_expires_at' => 'datetime', 'finished_at' => 'datetime', 'publication_completed_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
