<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->json('model_snapshot')->nullable();
            $table->json('usage')->nullable();
            $table->string('context_snapshot_digest', 64)->nullable();
            $table->timestamp('last_event_at', 6)->nullable();
            $table->index('last_event_at', 'aiw_runs_last_event_idx');
        });

        Schema::table('ai_workspace_steps', function (Blueprint $table): void {
            $table->timestamp('queued_at', 6)->nullable();
            $table->timestamp('first_output_at', 6)->nullable();
            $table->unsignedSmallInteger('result_schema_version')->nullable();
            $table->index(['run_id', 'queued_at'], 'aiw_steps_run_queued_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_steps', function (Blueprint $table): void {
            $table->dropIndex('aiw_steps_run_queued_idx');
            $table->dropColumn(['queued_at', 'first_output_at', 'result_schema_version']);
        });

        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->dropIndex('aiw_runs_last_event_idx');
            $table->dropColumn(['model_snapshot', 'usage', 'context_snapshot_digest', 'last_event_at']);
        });
    }
};
