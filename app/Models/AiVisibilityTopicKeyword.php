<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVisibilityTopicKeyword extends Model
{
    protected $fillable = [
        'ai_visibility_topic_id',
        'keyword',
        'keyword_hash',
    ];

    protected function casts(): array
    {
        return [
            'ai_visibility_topic_id' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityTopic::class, 'ai_visibility_topic_id');
    }
}
