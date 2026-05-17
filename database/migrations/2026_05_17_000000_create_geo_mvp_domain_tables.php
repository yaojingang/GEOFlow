<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->unsignedBigInteger('owner_admin_id')->nullable()->index();
                $table->string('plan_code', 60)->default('trial');
                $table->integer('points')->default(0);
                $table->decimal('balance', 12, 2)->default(0);
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('brand_profiles')) {
            Schema::create('brand_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('brand_name', 160);
                $table->json('aliases')->nullable();
                $table->text('products')->nullable();
                $table->text('advantages')->nullable();
                $table->text('cases')->nullable();
                $table->text('pain_points')->nullable();
                $table->string('service_area', 255)->default('');
                $table->text('extra_facts')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_keywords')) {
            Schema::create('geo_keywords', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('type', 40)->default('industry')->index();
                $table->string('keyword', 255);
                $table->string('intent', 80)->default('');
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
                $table->unique(['organization_id', 'type', 'keyword']);
            });
        }

        if (! Schema::hasTable('geo_competitors')) {
            Schema::create('geo_competitors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('name', 160);
                $table->json('aliases')->nullable();
                $table->string('website', 500)->default('');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_ai_platforms')) {
            Schema::create('geo_ai_platforms', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('code', 80)->unique();
                $table->string('api_mode', 40)->default('mock');
                $table->string('base_url', 500)->default('');
                $table->integer('cost_per_query')->default(1);
                $table->string('status', 30)->default('active')->index();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_tasks')) {
            Schema::create('geo_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('brand_profile_id')->constrained('brand_profiles')->cascadeOnDelete();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('status', 30)->default('pending')->index();
                $table->integer('total_score')->default(0);
                $table->integer('points_cost')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_task_questions')) {
            Schema::create('geo_task_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_task_id')->constrained('geo_tasks')->cascadeOnDelete();
                $table->foreignId('geo_keyword_id')->nullable()->constrained('geo_keywords')->nullOnDelete();
                $table->text('question');
                $table->json('platform_codes')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_answers')) {
            Schema::create('geo_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_task_id')->constrained('geo_tasks')->cascadeOnDelete();
                $table->foreignId('geo_task_question_id')->constrained('geo_task_questions')->cascadeOnDelete();
                $table->string('platform_code', 80)->index();
                $table->text('prompt');
                $table->longText('raw_answer')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_scores')) {
            Schema::create('geo_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_answer_id')->constrained('geo_answers')->cascadeOnDelete();
                $table->boolean('brand_mentioned')->default(false);
                $table->boolean('is_recommended')->default(false);
                $table->integer('rank_position')->nullable();
                $table->json('competitors_mentioned')->nullable();
                $table->json('citations')->nullable();
                $table->integer('score')->default(0);
                $table->json('analysis_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_reports')) {
            Schema::create('geo_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_task_id')->constrained('geo_tasks')->cascadeOnDelete();
                $table->string('title', 220);
                $table->text('summary')->nullable();
                $table->integer('total_score')->default(0);
                $table->longText('markdown_report')->nullable();
                $table->longText('html_report')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_writing_tasks')) {
            Schema::create('geo_writing_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('geo_report_id')->nullable()->constrained('geo_reports')->nullOnDelete();
                $table->foreignId('geo_keyword_id')->nullable()->constrained('geo_keywords')->nullOnDelete();
                $table->string('title', 220);
                $table->string('status', 30)->default('pending')->index();
                $table->json('brief')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_article_drafts')) {
            Schema::create('geo_article_drafts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('geo_writing_task_id')->nullable()->constrained('geo_writing_tasks')->nullOnDelete();
                $table->string('title', 220);
                $table->text('summary')->nullable();
                $table->longText('content_markdown')->nullable();
                $table->longText('content_html')->nullable();
                $table->string('seo_title', 255)->default('');
                $table->text('seo_description')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_publish_targets')) {
            Schema::create('geo_publish_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('type', 50)->default('wordpress')->index();
                $table->string('name', 120);
                $table->string('endpoint', 500)->default('');
                $table->text('encrypted_token')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('geo_publish_records')) {
            Schema::create('geo_publish_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geo_article_draft_id')->constrained('geo_article_drafts')->cascadeOnDelete();
                $table->foreignId('geo_publish_target_id')->nullable()->constrained('geo_publish_targets')->nullOnDelete();
                $table->string('status', 30)->default('pending')->index();
                $table->string('target_url', 500)->default('');
                $table->text('error_message')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('point_logs')) {
            Schema::create('point_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->string('action', 80)->index();
                $table->integer('points_delta');
                $table->string('ref_type', 80)->default('');
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('point_logs');
        Schema::dropIfExists('geo_publish_records');
        Schema::dropIfExists('geo_publish_targets');
        Schema::dropIfExists('geo_article_drafts');
        Schema::dropIfExists('geo_writing_tasks');
        Schema::dropIfExists('geo_reports');
        Schema::dropIfExists('geo_scores');
        Schema::dropIfExists('geo_answers');
        Schema::dropIfExists('geo_task_questions');
        Schema::dropIfExists('geo_tasks');
        Schema::dropIfExists('geo_ai_platforms');
        Schema::dropIfExists('geo_competitors');
        Schema::dropIfExists('geo_keywords');
        Schema::dropIfExists('brand_profiles');
        Schema::dropIfExists('organizations');
    }
};
