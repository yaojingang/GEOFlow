<?php

namespace App\Models;

use Laravel\Ai\Models\ConversationMessage;

class AiConversationMessage extends ConversationMessage
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_id',
        'participant_type',
        'participant_id',
        'agent',
        'role',
        'content',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
        'approval_state',
    ];
}
