<?php

/**
 * 仅在 PHPUnit（APP_ENV=testing）且 SQLite 内存库下创建 GEOFlow 最小表结构，
 * 供 API 契约测试使用。生产/开发 PostgreSQL 仍以 120000 全量 SQL 为准，勿依赖本迁移。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            return;
        }

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120);
            $table->string('route_key', 120);
            $table->string('request_hash', 64);
            $table->text('response_body');
            $table->integer('response_status');
            $table->timestamps();
            $table->unique(['idempotency_key', 'route_key']);
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('version', 50)->default('');
            $table->string('api_key', 500)->default('');
            $table->string('model_id', 100);
            $table->string('model_type', 20)->nullable();
            $table->string('api_url', 500)->default('');
            $table->integer('failover_priority')->default(100);
            $table->integer('daily_limit')->default(0);
            $table->integer('used_today')->default(0);
            $table->integer('total_used')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 50);
            $table->text('content');
            $table->text('variables')->nullable();
            $table->timestamps();
        });

        Schema::create('sensitive_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 100)->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('keyword_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('keyword_count')->default(0);
            $table->timestamps();
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('keyword_libraries')->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->integer('used_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['library_id', 'keyword']);
        });

        Schema::create('title_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('title_count')->default(0);
            $table->string('generation_type', 20)->default('manual');
            $table->foreignId('keyword_library_id')->nullable()->constrained('keyword_libraries');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models');
            $table->foreignId('prompt_id')->nullable()->constrained('prompts');
            $table->integer('generation_rounds')->default(1);
            $table->integer('is_ai_generated')->default(0);
            $table->timestamps();
        });

        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('title_libraries')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('keyword', 200)->default('');
            $table->boolean('is_ai_generated')->default(false);
            $table->integer('used_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('content')->default('');
            $table->integer('character_count')->default(0);
            $table->integer('used_task_count')->default(0);
            $table->string('file_type', 20)->default('markdown');
            $table->string('file_path', 500)->default('');
            $table->integer('word_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->text('content');
            $table->string('content_hash', 64)->default('');
            $table->integer('token_count')->default(0);
            $table->text('embedding_json')->nullable();
            $table->integer('embedding_model_id')->nullable();
            $table->integer('embedding_dimensions')->default(0);
            $table->string('embedding_provider', 255)->default('');
            $table->text('embedding_vector')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'chunk_index']);
        });

        Schema::create('image_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('image_count')->default(0);
            $table->integer('used_task_count')->default(0);
            $table->timestamps();
        });

        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('image_libraries')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('file_name', 255)->default('');
            $table->string('file_path', 500);
            $table->integer('file_size')->default(0);
            $table->string('mime_type', 100)->default('');
            $table->integer('width')->default(0);
            $table->integer('height')->default(0);
            $table->text('tags')->nullable();
            $table->integer('used_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('title_library_id')->nullable()->constrained('title_libraries');
            $table->foreignId('image_library_id')->nullable()->constrained('image_libraries');
            $table->foreignId('knowledge_base_id')->nullable()->constrained('knowledge_bases');
            $table->foreignId('prompt_id')->nullable()->constrained('prompts');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models');
            $table->integer('image_count')->default(0);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->integer('need_review')->default(1);
            $table->integer('publish_interval')->default(3600);
            $table->integer('auto_keywords')->default(1);
            $table->integer('auto_description')->default(1);
            $table->integer('draft_limit')->default(10);
            $table->integer('article_limit')->default(10);
            $table->integer('is_loop')->default(0);
            $table->string('model_selection_mode', 20)->default('fixed');
            $table->integer('created_count')->default(0);
            $table->integer('published_count')->default(0);
            $table->integer('loop_count')->default(0);
            $table->string('category_mode', 20)->default('smart');
            $table->unsignedBigInteger('fixed_category_id')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('next_publish_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->integer('schedule_enabled')->default(1);
            $table->integer('max_retry_count')->default(3);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('bio')->nullable();
            $table->string('email', 100)->default('');
            $table->string('avatar', 200)->default('');
            $table->string('website', 200)->default('');
            $table->text('social_links')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500);
            $table->string('slug', 500)->unique();
            $table->text('excerpt')->nullable();
            $table->text('content');
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('author_id')->constrained('authors');
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('original_keyword', 200)->default('');
            $table->text('keywords')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('review_status', 20)->default('pending');
            $table->integer('view_count')->default(0);
            $table->integer('is_ai_generated')->default(0);
            $table->timestamps();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            return;
        }

        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('images');
        Schema::dropIfExists('image_libraries');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('titles');
        Schema::dropIfExists('title_libraries');
        Schema::dropIfExists('keywords');
        Schema::dropIfExists('keyword_libraries');
        Schema::dropIfExists('knowledge_bases');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('sensitive_words');
        Schema::dropIfExists('prompts');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('api_idempotency_keys');
    }
};
