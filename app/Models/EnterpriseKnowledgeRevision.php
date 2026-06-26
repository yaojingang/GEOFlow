<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseKnowledgeRevision extends Model
{
    protected $fillable = [
        'enterprise_knowledge_project_id',
        'content',
        'summary',
        'source',
        'created_by_admin_id',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'enterprise_knowledge_project_id' => 'integer',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(EnterpriseKnowledgeProject::class, 'enterprise_knowledge_project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
