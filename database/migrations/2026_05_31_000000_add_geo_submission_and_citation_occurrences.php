<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geo_publish_records') && ! Schema::hasColumn('geo_publish_records', 'submitted_at')) {
            Schema::table('geo_publish_records', function (Blueprint $table): void {
                $table->timestamp('submitted_at')->nullable()->after('error_message');
            });
        }

        if (! Schema::hasTable('geo_citation_occurrences')) {
            Schema::create('geo_citation_occurrences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('geo_citation_source_id')->constrained('geo_citation_sources')->cascadeOnDelete();
                $table->foreignId('geo_ai_search_run_id')->constrained('geo_ai_search_runs')->cascadeOnDelete();
                $table->foreignId('geo_ai_search_question_id')->constrained('geo_ai_search_questions')->cascadeOnDelete();
                $table->foreignId('geo_ai_search_answer_id')->constrained('geo_ai_search_answers')->cascadeOnDelete();
                $table->foreignId('geo_keyword_opportunity_id')->nullable()->constrained('geo_keyword_opportunities')->nullOnDelete();
                $table->string('platform_code', 80)->default('')->index();
                $table->string('url', 1000);
                $table->string('domain', 255)->default('')->index();
                $table->timestamp('cited_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['geo_ai_search_answer_id', 'geo_citation_source_id'], 'geo_citation_occ_answer_source_unique');
                $table->index(['organization_id', 'geo_ai_search_run_id'], 'geo_citation_occ_org_run_idx');
                $table->index('geo_citation_source_id', 'geo_citation_occ_source_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_citation_occurrences');

        if (Schema::hasTable('geo_publish_records') && Schema::hasColumn('geo_publish_records', 'submitted_at')) {
            Schema::table('geo_publish_records', function (Blueprint $table): void {
                $table->dropColumn('submitted_at');
            });
        }
    }
};
