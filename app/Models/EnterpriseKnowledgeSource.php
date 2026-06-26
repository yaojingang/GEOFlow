<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseKnowledgeSource extends Model
{
    protected $fillable = [
        'enterprise_knowledge_project_id',
        'original_name',
        'file_path',
        'file_type',
        'content',
        'character_count',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'enterprise_knowledge_project_id' => 'integer',
            'character_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(EnterpriseKnowledgeProject::class, 'enterprise_knowledge_project_id');
    }
}
