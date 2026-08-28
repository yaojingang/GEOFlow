<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workspace_trace_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('step_id')->nullable();
            $table->unsignedBigInteger('sequence');
            $table->string('kind', 40);
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->string('status', 30)->default('running');
            $table->json('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ai_workspace_runs')->cascadeOnDelete();
            $table->foreign('step_id')->references('id')->on('ai_workspace_steps')->nullOnDelete();
            $table->unique(['run_id', 'sequence'], 'ai_workspace_trace_run_sequence_unique');
            $table->index(['run_id', 'status'], 'ai_workspace_trace_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workspace_trace_events');
    }
};
