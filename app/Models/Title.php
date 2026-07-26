<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'titles';

    protected $fillable = [
        'library_id',
        'title',
        'keyword',
        'is_ai_generated',
        'used_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'is_ai_generated' => 'boolean',
            'used_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(TitleLibrary::class, 'library_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'source_title_id');
    }
}
