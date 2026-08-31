<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeFactLibraryRevision extends Model
{
    protected $fillable = ['library_id', 'version', 'library_hash', 'source_hash', 'manifest_json', 'published_by_admin_id', 'published_at', 'restored_from_revision_id'];

    protected function casts(): array
    {
        return ['library_id' => 'integer', 'version' => 'integer', 'manifest_json' => 'array', 'published_at' => 'datetime', 'restored_from_revision_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('knowledge_fact_revision_is_immutable');
        });
        static::deleting(static function (): never {
            throw new \LogicException('knowledge_fact_revision_is_immutable');
        });
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibrary::class, 'library_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'published_by_admin_id');
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_revision_id');
    }
}
