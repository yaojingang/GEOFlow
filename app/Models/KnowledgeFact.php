<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeFact extends Model
{
    protected $fillable = ['library_id', 'stable_key', 'label', 'subject', 'predicate', 'value_type', 'locale', 'aliases_json', 'importance', 'usage_scope', 'review_status', 'is_enabled', 'lock_version', 'created_by_admin_id', 'updated_by_admin_id', 'origin_generation_run_id'];

    protected $attributes = ['locale' => 'zh_CN', 'importance' => 'normal', 'usage_scope' => 'quality_only', 'review_status' => 'draft', 'is_enabled' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['library_id' => 'integer', 'aliases_json' => 'array', 'is_enabled' => 'boolean', 'lock_version' => 'integer', 'origin_generation_run_id' => 'integer'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibrary::class, 'library_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(KnowledgeFactValue::class, 'fact_id')->orderBy('id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('review_status', 'reviewed');
    }
}
