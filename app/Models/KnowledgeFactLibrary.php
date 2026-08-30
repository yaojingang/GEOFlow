<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeFactLibrary extends Model
{
    protected $fillable = ['knowledge_base_id', 'workflow_status', 'serving_status', 'working_version', 'active_revision_id', 'active_hash', 'source_hash', 'active_health_json'];

    protected $attributes = ['workflow_status' => 'idle', 'serving_status' => 'unavailable', 'working_version' => 1];

    protected function casts(): array
    {
        return ['knowledge_base_id' => 'integer', 'working_version' => 'integer', 'active_revision_id' => 'integer', 'active_health_json' => 'array'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(KnowledgeFact::class, 'library_id')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(KnowledgeFactLibraryRevision::class, 'library_id')->orderByDesc('version');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibraryRevision::class, 'active_revision_id');
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(KnowledgeFactGenerationRun::class, 'library_id');
    }
}
