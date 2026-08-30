<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeFactValue extends Model
{
    protected $fillable = ['fact_id', 'canonical_value_json', 'canonical_answer', 'temporal_kind', 'scope_json', 'scope_hash', 'valid_from', 'valid_to', 'observed_at', 'comparison_policy_json', 'review_status', 'conflict_status', 'lock_version', 'created_by_admin_id', 'updated_by_admin_id', 'origin_generation_run_id'];

    protected $attributes = ['temporal_kind' => 'timeless', 'review_status' => 'draft', 'conflict_status' => 'clear', 'lock_version' => 1];

    protected function casts(): array
    {
        return ['fact_id' => 'integer', 'canonical_value_json' => 'array', 'scope_json' => 'array', 'comparison_policy_json' => 'array', 'valid_from' => 'date', 'valid_to' => 'date', 'observed_at' => 'datetime', 'lock_version' => 'integer', 'origin_generation_run_id' => 'integer'];
    }

    public function fact(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFact::class, 'fact_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(KnowledgeFactEvidence::class, 'value_id')->orderByDesc('is_primary')->orderBy('id');
    }
}
