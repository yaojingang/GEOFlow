<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleReview extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'article_reviews';

    protected $fillable = [
        'article_id',
        'admin_id',
        'review_status',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'admin_id' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
