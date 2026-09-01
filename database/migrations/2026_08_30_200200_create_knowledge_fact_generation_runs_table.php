<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_fact_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('knowledge_fact_libraries')->cascadeOnDelete();
            $table->string('mode', 24);
            $table->unsignedSmallInteger('target_count');
            $table->char('source_hash', 64);
            $table->unsignedInteger('base_working_version');
            $table->string('status', 16)->default('queued');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('active_key')->nullable()->unique();
            $table->uuid('job_batch_id')->nullable()->index();
            $table->string('prompt_version', 32)->default('knowledge-facts-1.0.0');
            $table->longText('result_json')->nullable();
            $table->longText('batch_meta_json')->nullable();
            $table->longText('coverage_json')->nullable();
            $table->longText('usage_json')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('diagnostic_payload_pruned_at')->nullable();
            $table->timestamps();
            $table->index(['library_id', 'status']);
        });

        Schema::table('knowledge_facts', function (Blueprint $table): void {
            $table->foreignId('origin_generation_run_id')->nullable()->constrained('knowledge_fact_generation_runs')->nullOnDelete();
        });
        Schema::table('knowledge_fact_values', function (Blueprint $table): void {
            $table->foreignId('origin_generation_run_id')->nullable()->constrained('knowledge_fact_generation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_fact_values', fn (Blueprint $table) => $table->dropConstrainedForeignId('origin_generation_run_id'));
        Schema::table('knowledge_facts', fn (Blueprint $table) => $table->dropConstrainedForeignId('origin_generation_run_id'));
        Schema::dropIfExists('knowledge_fact_generation_runs');
    }
};
