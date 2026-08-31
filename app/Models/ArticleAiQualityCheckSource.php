<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAiQualityCheckSource extends Model
{
    protected $fillable = [
        'article_ai_quality_check_id',
        'knowledge_base_id',
        'knowledge_base_name_snapshot',
        'dependency_kind',
        'source_hash',
        'chunk_serving_generation',
        'chunk_manifest_hash',
        'fact_revision_id',
        'fact_library_hash',
        'readiness_status',
        'used_provider',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'article_ai_quality_check_id' => 'integer',
            'knowledge_base_id' => 'integer',
            'fact_revision_id' => 'integer',
            'used_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'article_ai_quality_check_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function factRevision(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibraryRevision::class, 'fact_revision_id');
    }
}
