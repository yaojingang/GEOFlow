<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TopicRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['path_generation', 'topic_id', 'number', 'draft_version', 'payload', 'freshness_snapshot_json', 'request_id', 'created_by_admin_id'];

    protected function casts(): array
    {
        return ['path_generation' => 'integer', 'topic_id' => 'integer', 'number' => 'integer', 'draft_version' => 'integer', 'payload' => 'array', 'freshness_snapshot_json' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Topic revisions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Topic revisions are immutable.'));
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class)->withTrashed();
    }

    public function sourceInvalidations(): HasMany
    {
        return $this->hasMany(TopicSourceInvalidation::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(TopicRevisionArticle::class)->useWritePdo()->orderBy('sort_order');
    }
}
