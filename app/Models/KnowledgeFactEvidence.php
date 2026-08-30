<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeFactEvidence extends Model
{
    protected $table = 'knowledge_fact_evidences';

    protected $fillable = ['value_id', 'knowledge_chunk_id', 'source_hash', 'content_hash', 'source_locator_json', 'excerpt', 'excerpt_hash', 'is_primary', 'created_by_admin_id'];

    protected $attributes = ['is_primary' => false];

    protected function casts(): array
    {
        return ['value_id' => 'integer', 'knowledge_chunk_id' => 'integer', 'source_locator_json' => 'array', 'is_primary' => 'boolean'];
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactValue::class, 'value_id');
    }

    public function knowledgeChunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class);
    }
}
