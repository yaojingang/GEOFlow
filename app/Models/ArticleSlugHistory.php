<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleSlugHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['article_id', 'slug', 'created_at', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'created_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
