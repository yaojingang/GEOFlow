<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL/MariaDB fresh-install schema for GEOFlow's legacy business tables.
 *
 * The PostgreSQL legacy migration remains immutable. This migration is ordered
 * immediately after it and before the vector-column migration so later
 * Laravel migrations can safely alter these tables on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->mysqlSchema();
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($this->dropOrder() as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * down() 删表顺序：先删有外键依赖的子表，与 up() 相反。
     *
     * @return list<string>
     */
    private function dropOrder(): array
    {
        return [
            'api_idempotency_keys',
            'worker_heartbeats',
            'task_runs',
            'url_import_job_logs',
            'url_import_jobs',
            'article_reviews',
            'article_images',
            'articles',
            'sensitive_words',
            'task_schedules',
            'tasks',
            'admin_activity_logs',
            'system_logs',
            'categories',
            'titles',
            'title_libraries',
            'keywords',
            'keyword_libraries',
            'images',
            'image_libraries',
            'knowledge_chunks',
            'knowledge_bases',
            'authors',
            'prompts',
            'ai_models',
        ];
    }

    /**
     * MySQL fresh install 的基础表结构。
     *
     * 这里不把 PostgreSQL 原生 SQL 做字符串替换：MySQL 的自增主键、无符号外键、
     * TEXT 默认值以及外键创建顺序都不同。可选文本字段使用 nullable，避免 MySQL
     * 8.0 在 TEXT/BLOB 默认值上的版本差异；应用层已有默认值的字段语义不变。
     */
    private function mysqlSchema(): void
    {
        $create = function (string $name, Closure $definition): void {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        };

        $create('ai_models', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('version', 50)->default('');
            $table->string('api_key', 500);
            $table->string('model_id', 100);
            $table->string('model_type', 20)->default('chat');
            $table->string('api_url', 500)->default('https://api.deepseek.com');
            $table->integer('failover_priority')->default(100);
            $table->integer('daily_limit')->default(0);
            $table->integer('used_today')->default(0);
            $table->integer('total_used')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        $create('prompts', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 50);
            $table->text('content');
            $table->text('variables')->nullable();
            $table->timestamps();
        });

        $create('keyword_libraries', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('keyword_count')->default(0);
            $table->timestamps();
        });

        $create('keywords', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('keyword_libraries')->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->integer('used_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['library_id', 'keyword']);
        });

        $create('title_libraries', static function (Blueprint $table): void {
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

        $create('titles', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained('title_libraries')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('keyword', 200)->default('');
            $table->boolean('is_ai_generated')->default(false);
            $table->integer('used_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        $create('image_libraries', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('image_count')->default(0);
            $table->integer('used_task_count')->default(0);
            $table->timestamps();
        });

        $create('images', static function (Blueprint $table): void {
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

        $create('knowledge_bases', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('content');
            $table->integer('character_count')->default(0);
            $table->integer('used_task_count')->default(0);
            $table->string('file_type', 20)->default('markdown');
            $table->string('file_path', 500)->default('');
            $table->integer('word_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        $create('knowledge_chunks', static function (Blueprint $table): void {
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
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'chunk_index']);
        });

        $create('authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('bio')->nullable();
            $table->string('email', 100)->default('');
            $table->string('avatar', 200)->default('');
            $table->string('website', 200)->default('');
            $table->text('social_links')->nullable();
            $table->timestamps();
        });

        $create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('title_library_id')->constrained('title_libraries');
            $table->foreignId('image_library_id')->nullable()->constrained('image_libraries');
            $table->integer('image_count')->default(1);
            $table->foreignId('prompt_id')->constrained('prompts');
            $table->foreignId('ai_model_id')->constrained('ai_models');
            $table->foreignId('author_id')->nullable()->constrained('authors');
            $table->integer('need_review')->default(1);
            $table->integer('publish_interval')->default(3600);
            $table->string('author_type', 20)->default('random');
            $table->foreignId('custom_author_id')->nullable()->constrained('authors');
            $table->integer('auto_keywords')->default(1);
            $table->integer('auto_description')->default(1);
            $table->integer('draft_limit')->default(10);
            $table->integer('article_limit')->default(10);
            $table->integer('is_loop')->default(0);
            $table->string('model_selection_mode', 20)->default('fixed');
            $table->string('status', 20)->default('active');
            $table->integer('created_count')->default(0);
            $table->integer('published_count')->default(0);
            $table->integer('loop_count')->default(0);
            $table->unsignedBigInteger('knowledge_base_id')->nullable();
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
            $table->timestamps();
        });

        $create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        $create('articles', static function (Blueprint $table): void {
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

        $create('article_images', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('image_id')->constrained('images');
            $table->integer('position')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        $create('sensitive_words', static function (Blueprint $table): void {
            $table->id();
            $table->string('word', 100)->unique();
            $table->timestamp('created_at')->nullable();
        });

        $create('task_schedules', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamp('next_run_time');
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        $create('system_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50);
            $table->text('message');
            $table->text('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $create('admin_activity_logs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_username', 50);
            $table->string('admin_role', 20)->default('admin');
            $table->string('action', 120);
            $table->string('request_method', 10)->default('POST');
            $table->string('page', 255)->default('');
            $table->string('target_type', 50)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['admin_id', 'created_at'], 'idx_admin_activity_logs_admin');
            $table->index('created_at', 'idx_admin_activity_logs_created');
        });

        $create('article_reviews', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins');
            $table->string('review_status', 20);
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $create('url_import_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->text('url');
            $table->text('normalized_url');
            $table->string('source_domain', 255)->default('');
            $table->string('page_title', 255)->default('');
            $table->string('status', 20)->default('queued');
            $table->string('current_step', 50)->default('queued');
            $table->integer('progress_percent')->default(0);
            $table->text('options_json')->nullable();
            $table->text('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->string('created_by', 100)->default('');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $create('url_import_job_logs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('url_import_jobs')->cascadeOnDelete();
            $table->string('step', 50)->default('queued');
            $table->string('level', 20)->default('info');
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });

        $create('task_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $create('worker_heartbeats', static function (Blueprint $table): void {
            $table->string('worker_id', 100)->primary();
            $table->string('status', 20)->default('idle');
            $table->timestamp('last_seen_at')->useCurrent();
            $table->text('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('last_seen_at', 'idx_worker_heartbeats_last_seen');
        });

        $create('api_idempotency_keys', static function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 120);
            $table->string('route_key', 120);
            $table->string('request_hash', 64);
            $table->text('response_body');
            $table->integer('response_status');
            $table->timestamps();
            $table->unique(['idempotency_key', 'route_key']);
            $table->index('created_at', 'idx_api_idempotency_created_at');
        });

        $this->addMySqlLegacyIndexes();
    }

    private function addMySqlLegacyIndexes(): void
    {
        if (Schema::hasTable('task_runs')) {
            Schema::table('task_runs', function (Blueprint $table): void {
                if (! Schema::hasIndex('task_runs', 'idx_task_runs_task')) {
                    $table->index('task_id', 'idx_task_runs_task');
                }
                if (! Schema::hasIndex('task_runs', 'idx_task_runs_status')) {
                    $table->index('status', 'idx_task_runs_status');
                }
            });
        }
    }
};
