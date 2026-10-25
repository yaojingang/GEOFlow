<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVisibilityTopic extends Model
{
    protected $fillable = [
        'name',
        'description',
        'brand_aliases',
    ];

    protected function casts(): array
    {
        return [
            'brand_aliases' => 'array',
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(AiVisibilityTopicKeyword::class, 'ai_visibility_topic_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'ai_visibility_topic_id');
    }
}
