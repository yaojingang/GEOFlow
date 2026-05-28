<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleImage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'article_images';

    protected $fillable = [
        'article_id',
        'image_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'image_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }
}
