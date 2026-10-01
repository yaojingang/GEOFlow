<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TopicRevisionArticle extends Model
{
    public $timestamps = false;

    protected $fillable = ['topic_revision_id', 'article_id', 'sort_order', 'group', 'reason', 'content_hash', 'snapshot'];

    protected function casts(): array
    {
        return ['topic_revision_id' => 'integer', 'article_id' => 'integer', 'sort_order' => 'integer', 'snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Topic revision sources are immutable.'));
        static::deleting(static fn () => throw new LogicException('Topic revision sources are immutable.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(TopicRevision::class, 'topic_revision_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
