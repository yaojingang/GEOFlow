<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_visibility_runs')) {
            return;
        }

        Schema::create('ai_visibility_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('keyword', 255);
            $table->text('prompt')->nullable();
            $table->string('provider_type', 80);
            $table->string('provider_key', 80)->nullable();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('ai_source_provider_id')->nullable()->constrained('ai_source_providers')->nullOnDelete();
            $table->string('model_id', 160)->nullable();
            $table->string('status', 40)->default('queued');
            $table->longText('answer_text')->nullable();
            $table->string('locale', 20)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('usage_json')->nullable();
            $table->json('analysis_json')->nullable();
            $table->json('raw_request_json')->nullable();
            $table->json('raw_response_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_type', 'status', 'created_at'], 'ai_visibility_runs_provider_status_created_idx');
            $table->index(['keyword', 'created_at'], 'ai_visibility_runs_keyword_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_runs');
    }
};
