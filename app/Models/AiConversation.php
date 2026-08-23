<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;

class AiConversation extends Conversation
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function workspaceRuns(): HasMany
    {
        return $this->hasMany(AiWorkspaceRun::class, 'conversation_id');
    }
}
