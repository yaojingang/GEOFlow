<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KnowledgeBaseRevision extends Model
{
    public const SOURCE_OFFICIAL = 'official';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RESTORE = 'restore';

    protected $fillable = [
        'knowledge_base_id',
        'revision_number',
        'content',
        'content_hash',
        'source',
        'created_by_admin_id',
        'restored_from_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'integer',
            'revision_number' => 'integer',
            'created_by_admin_id' => 'integer',
            'restored_from_revision_id' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_revision_id');
    }
}
