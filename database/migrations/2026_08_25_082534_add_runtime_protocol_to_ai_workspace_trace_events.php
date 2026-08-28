<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workspace_trace_events', function (Blueprint $table): void {
            $table->string('event_type', 100)->nullable();
            $table->unsignedSmallInteger('event_version')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->string('actor_type', 40)->nullable();
            $table->string('actor_id', 120)->nullable();
            $table->json('payload')->nullable();
            $table->string('visibility', 24)->nullable();
            $table->timestamp('occurred_at', 6)->nullable();

            $table->index(['run_id', 'event_type', 'sequence'], 'aiw_trace_run_type_sequence_idx');
            $table->index(['run_id', 'occurred_at'], 'aiw_trace_run_occurred_idx');
            $table->index('correlation_id', 'aiw_trace_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_trace_events', function (Blueprint $table): void {
            $table->dropIndex('aiw_trace_run_type_sequence_idx');
            $table->dropIndex('aiw_trace_run_occurred_idx');
            $table->dropIndex('aiw_trace_correlation_idx');
            $table->dropColumn([
                'event_type',
                'event_version',
                'correlation_id',
                'causation_id',
                'actor_type',
                'actor_id',
                'payload',
                'visibility',
                'occurred_at',
            ]);
        });
    }
};
