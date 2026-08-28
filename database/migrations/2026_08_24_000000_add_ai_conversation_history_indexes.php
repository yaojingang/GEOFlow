<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        $messageTable = (string) config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (Schema::hasTable($messageTable)) {
            Schema::table($messageTable, function (Blueprint $table): void {
                $table->index(
                    ['conversation_id', 'created_at', 'id'],
                    'agent_messages_conversation_created_id_idx',
                );
            });
        }

        if (Schema::hasTable($conversationTable)) {
            Schema::table($conversationTable, function (Blueprint $table): void {
                $table->index(
                    ['participant_type', 'participant_id', 'archived_at', 'updated_at', 'id'],
                    'agent_conversations_owner_history_idx',
                );
            });
        }
    }

    public function down(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        $messageTable = (string) config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (Schema::hasTable($messageTable)) {
            Schema::table($messageTable, function (Blueprint $table): void {
                $table->dropIndex('agent_messages_conversation_created_id_idx');
            });
        }

        if (Schema::hasTable($conversationTable)) {
            Schema::table($conversationTable, function (Blueprint $table): void {
                $table->dropIndex('agent_conversations_owner_history_idx');
            });
        }
    }
};
