<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SystemKnowledgeBase extends Model
{
    protected $primaryKey = 'system_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'system_key',
        'knowledge_base_id',
        'official_version',
        'official_content_hash',
        'customized_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'integer',
            'customized_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
