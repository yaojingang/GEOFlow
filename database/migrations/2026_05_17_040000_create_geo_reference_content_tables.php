<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_citation_page_snapshots')) {
            Schema::create('geo_citation_page_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_citation_source_id')->nullable()->constrained('geo_citation_sources')->nullOnDelete();
                $table->string('url', 1000);
                $table->string('domain', 255)->default('')->index();
                $table->string('title', 500)->default('');
                $table->text('description')->nullable();
                $table->text('content_summary')->nullable();
                $table->longText('content_text')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('crawl_status', 40)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->string('content_hash', 64)->default('')->index();
                $table->timestamp('crawled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_reference_content_scores')) {
            Schema::create('geo_reference_content_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_citation_page_snapshot_id')->constrained('geo_citation_page_snapshots')->cascadeOnDelete();
                $table->unsignedTinyInteger('relevance_score')->default(0);
                $table->unsignedTinyInteger('structure_score')->default(0);
                $table->unsignedTinyInteger('actionability_score')->default(0);
                $table->unsignedTinyInteger('evidence_density_score')->default(0);
                $table->unsignedTinyInteger('brand_competitor_score')->default(0);
                $table->unsignedTinyInteger('total_score')->default(0)->index();
                $table->text('score_reason')->nullable();
                $table->string('suggested_usage', 120)->default('reference');
                $table->json('signals')->nullable();
                $table->timestamp('scored_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_reference_content_scores');
        Schema::dropIfExists('geo_citation_page_snapshots');
    }
};
