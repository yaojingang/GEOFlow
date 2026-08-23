<?php

namespace App\Models;

use Laravel\Ai\Models\ConversationMessage;

class AiConversationMessage extends ConversationMessage
{
    public $incrementing = false;

    protected $keyType = 'string';
}
