<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_reference_content_analyses')) {
            Schema::create('geo_reference_content_analyses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('geo_citation_source_id')->constrained('geo_citation_sources')->cascadeOnDelete();
                $table->foreignId('geo_citation_page_snapshot_id')->constrained('geo_citation_page_snapshots')->cascadeOnDelete();
                $table->foreignId('geo_reference_content_score_id')->nullable()->constrained('geo_reference_content_scores')->nullOnDelete();
                $table->string('article_title', 500)->default('');
                $table->json('structure_json')->nullable();
                $table->longText('analysis_markdown')->nullable();
                $table->string('markdown_path', 1000)->default('');
                $table->string('json_path', 1000)->default('');
                $table->timestamp('analyzed_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_reference_content_analyses');
    }
};
