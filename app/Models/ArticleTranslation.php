<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleTranslation extends Model
{
    protected $table = 'article_translations';

    protected $fillable = [
        'article_id',
        'locale',
        'title',
        'excerpt',
        'content',
        'meta_description',
        'source_hash',
        'translation_error',
        'translated_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'translated_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * 译文是否仍对应当前原文内容。
     */
    public function isCurrentFor(string $sourceHash): bool
    {
        return $this->translation_error === null
            && hash_equals((string) $this->source_hash, $sourceHash);
    }
}
