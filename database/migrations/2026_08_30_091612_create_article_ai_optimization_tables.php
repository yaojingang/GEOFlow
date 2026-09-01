<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('article_ai_optimization_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_check_id')->nullable()->constrained('article_ai_quality_checks')->nullOnDelete();
            $table->foreignId('best_check_id')->nullable()->constrained('article_ai_quality_checks')->nullOnDelete();
            $table->foreignId('final_check_id')->nullable()->constrained('article_ai_quality_checks')->nullOnDelete();
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('trigger', 30);
            $table->string('strategy', 20)->default('excellent_80');
            $table->unsignedTinyInteger('target_score')->default(85);
            $table->unsignedTinyInteger('max_rounds')->default(2);
            $table->unsignedTinyInteger('completed_rounds')->default(0);
            $table->string('status', 30)->default('queued');
            $table->string('stop_reason', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->char('base_article_hash', 64);
            $table->char('candidate_hash', 64)->nullable();
            $table->char('applied_article_hash', 64)->nullable();
            $table->char('policy_hash', 64);
            $table->string('active_dedupe_key', 64)->nullable()->unique();
            $table->string('lease_owner', 120)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('usage_meta')->nullable();
            $table->json('execution_meta')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status', 'id'], 'article_ai_opt_article_status_idx');
            $table->index(['task_id', 'status'], 'article_ai_opt_task_status_idx');
            $table->index(['status', 'lease_expires_at'], 'article_ai_opt_lease_idx');
        });

        Schema::create('article_ai_optimization_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('article_ai_optimization_runs')->cascadeOnDelete();
            $table->unsignedTinyInteger('round_index');
            $table->foreignId('input_check_id')->nullable()->constrained('article_ai_quality_checks')->nullOnDelete();
            $table->foreignId('output_check_id')->nullable()->constrained('article_ai_quality_checks')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('status', 30)->default('planning');
            $table->string('rejection_code', 80)->nullable();
            $table->string('rejection_message', 500)->nullable();
            $table->json('selected_causes')->nullable();
            $table->json('patch_plan')->nullable();
            $table->json('applied_patch')->nullable();
            $table->json('validation')->nullable();
            $table->char('before_hash', 64);
            $table->char('after_hash', 64)->nullable();
            $table->unsignedTinyInteger('before_score')->nullable();
            $table->unsignedTinyInteger('after_score')->nullable();
            $table->string('before_decision', 30)->nullable();
            $table->string('after_decision', 30)->nullable();
            $table->json('usage_meta')->nullable();
            $table->json('execution_meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'round_index'], 'article_ai_opt_step_round_unique');
            $table->index(['run_id', 'status'], 'article_ai_opt_step_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_ai_optimization_steps');
        Schema::dropIfExists('article_ai_optimization_runs');
    }
};
