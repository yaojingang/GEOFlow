<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_ai_quality_checks')) {
            return;
        }

        Schema::create('article_ai_quality_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained('task_runs')->nullOnDelete();
            $table->foreignId('prompt_id')->nullable()->constrained('prompts')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedBigInteger('supersedes_check_id')->nullable()->index();
            $table->uuid('request_key')->unique();
            $table->char('active_dedupe_key', 64)->nullable()->unique();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('decision', 30)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('pass_score')->default(85);
            $table->unsignedTinyInteger('manual_override_min_score')->default(70);
            $table->text('summary')->nullable();
            $table->string('promotion_context', 20)->nullable();
            $table->string('knowledge_coverage', 20)->nullable();
            $table->json('dimension_scores')->nullable();
            $table->json('issues')->nullable();
            $table->json('uncertainties')->nullable();
            $table->unsignedSmallInteger('segment_count')->default(0);
            $table->unsignedSmallInteger('completed_segment_count')->default(0);
            $table->longText('article_snapshot')->nullable();
            $table->longText('fact_candidates_snapshot')->nullable();
            $table->longText('evidence_snapshot')->nullable();
            $table->longText('prompt_template_snapshot')->nullable();
            $table->longText('advertising_rules_snapshot')->nullable();
            $table->longText('model_snapshot')->nullable();
            $table->longText('raw_model_output')->nullable();
            $table->char('article_content_hash', 64)->nullable();
            $table->char('prompt_hash', 64)->nullable();
            $table->char('knowledge_hash', 64)->nullable();
            $table->char('input_fingerprint', 64)->index();
            $table->string('algorithm_version', 40);
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->json('usage_meta')->nullable();
            $table->json('execution_meta')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('overridden_by_name', 100)->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'created_at'], 'article_ai_quality_article_created_idx');
            $table->index(['status', 'decision'], 'article_ai_quality_status_decision_idx');
        });

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->foreign('supersedes_check_id')
                ->references('id')
                ->on('article_ai_quality_checks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_checks');
    }
};
