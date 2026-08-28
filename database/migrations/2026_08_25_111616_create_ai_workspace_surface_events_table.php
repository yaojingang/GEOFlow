<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        Schema::create('ai_workspace_surface_events', function (Blueprint $table) use ($conversationTable): void {
            $table->uuid('id')->primary();
            $table->string('conversation_id', 36);
            $table->uuid('run_id')->nullable();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 80);
            $table->string('source_key', 190);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on($conversationTable)->cascadeOnDelete();
            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->nullOnDelete();
            $table->unique(['conversation_id', 'sequence'], 'ai_surface_conversation_sequence_unique');
            $table->unique(['conversation_id', 'source_key'], 'ai_surface_conversation_source_unique');
            $table->index(['run_id', 'sequence'], 'ai_surface_run_sequence_index');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workspace_surface_events');
    }
};
