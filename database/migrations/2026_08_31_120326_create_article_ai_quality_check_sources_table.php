<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_ai_quality_check_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_ai_quality_check_id')->constrained('article_ai_quality_checks')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->string('knowledge_base_name_snapshot');
            $table->string('dependency_kind', 24);
            $table->char('source_hash', 64)->nullable();
            $table->string('chunk_serving_generation', 64)->nullable();
            $table->char('chunk_manifest_hash', 64)->nullable();
            $table->foreignId('fact_revision_id')->nullable()->constrained('knowledge_fact_library_revisions')->nullOnDelete();
            $table->char('fact_library_hash', 64)->nullable();
            $table->string('readiness_status', 24);
            $table->string('used_provider', 32)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['article_ai_quality_check_id', 'knowledge_base_id', 'dependency_kind'],
                'article_ai_quality_check_source_unique'
            );
            $table->index(
                ['knowledge_base_id', 'dependency_kind', 'article_ai_quality_check_id'],
                'article_ai_quality_check_source_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_check_sources');
    }
};
