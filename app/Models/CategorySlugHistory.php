<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategorySlugHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'slug',
        'category_id',
        'original_category_id',
        'admin_id',
        'created_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'original_category_id' => 'integer',
            'admin_id' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
