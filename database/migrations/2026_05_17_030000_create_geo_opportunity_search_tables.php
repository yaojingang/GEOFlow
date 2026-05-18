<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_keyword_opportunities')) {
            Schema::create('geo_keyword_opportunities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('brand_profile_id')->nullable()->constrained('brand_profiles')->nullOnDelete();
                $table->foreignId('source_geo_keyword_id')->nullable()->constrained('geo_keywords')->nullOnDelete();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->string('keyword', 255);
                $table->string('intent', 80)->default('decision')->index();
                $table->string('cluster_name', 120)->default('');
                $table->string('status', 30)->default('active')->index();
                $table->unsignedTinyInteger('business_value')->default(0);
                $table->unsignedTinyInteger('visibility_gap')->default(0);
                $table->unsignedTinyInteger('source_availability')->default(0);
                $table->unsignedTinyInteger('local_relevance')->default(0);
                $table->unsignedTinyInteger('opportunity_score')->default(0)->index();
                $table->string('generation_source', 80)->default('brand_profile');
                $table->text('rationale')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'keyword']);
            });
        }

        if (! Schema::hasTable('geo_ai_search_runs')) {
            Schema::create('geo_ai_search_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('brand_profile_id')->constrained('brand_profiles')->cascadeOnDelete();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('status', 30)->default('pending')->index();
                $table->json('platform_codes')->nullable();
                $table->integer('points_cost')->default(0);
                $table->integer('total_questions')->default(0);
                $table->integer('completed_questions')->default(0);
                $table->integer('failed_questions')->default(0);
                $table->integer('average_score')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_ai_search_questions')) {
            Schema::create('geo_ai_search_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_ai_search_run_id')->constrained('geo_ai_search_runs')->cascadeOnDelete();
                $table->foreignId('geo_keyword_opportunity_id')->constrained('geo_keyword_opportunities')->cascadeOnDelete();
                $table->text('question');
                $table->string('intent', 80)->default('');
                $table->string('status', 30)->default('pending')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_ai_search_answers')) {
            Schema::create('geo_ai_search_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_ai_search_run_id')->constrained('geo_ai_search_runs')->cascadeOnDelete();
                $table->foreignId('geo_ai_search_question_id')->constrained('geo_ai_search_questions')->cascadeOnDelete();
                $table->foreignId('geo_keyword_opportunity_id')->constrained('geo_keyword_opportunities')->cascadeOnDelete();
                $table->string('platform_code', 80)->index();
                $table->longText('prompt');
                $table->longText('raw_answer')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->boolean('brand_mentioned')->default(false);
                $table->json('competitors_mentioned')->nullable();
                $table->json('citations')->nullable();
                $table->json('source_urls')->nullable();
                $table->integer('visibility_score')->default(0);
                $table->json('analysis_json')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();
                $table->unique(['geo_ai_search_question_id', 'platform_code'], 'geo_search_answer_unique');
            });
        }

        if (! Schema::hasTable('geo_citation_sources')) {
            Schema::create('geo_citation_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('geo_ai_search_answer_id')->nullable()->constrained('geo_ai_search_answers')->nullOnDelete();
                $table->string('url', 1000);
                $table->string('domain', 255)->default('')->index();
                $table->string('title', 500)->default('');
                $table->string('platform_name', 120)->default('');
                $table->string('status', 40)->default('pending_crawl')->index();
                $table->integer('citation_count')->default(1);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'url']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_citation_sources');
        Schema::dropIfExists('geo_ai_search_answers');
        Schema::dropIfExists('geo_ai_search_questions');
        Schema::dropIfExists('geo_ai_search_runs');
        Schema::dropIfExists('geo_keyword_opportunities');
    }
};
