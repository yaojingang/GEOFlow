<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        Schema::table($conversationTable, function (Blueprint $table) use ($conversationTable): void {
            if (! Schema::hasColumn($conversationTable, 'surface_sequence')) {
                $table->unsignedBigInteger('surface_sequence')->default(0);
            }
        });
    }

    public function down(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        Schema::table($conversationTable, function (Blueprint $table) use ($conversationTable): void {
            if (Schema::hasColumn($conversationTable, 'surface_sequence')) {
                $table->dropColumn('surface_sequence');
            }
        });
    }
};
