<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'ai_quality_timeout_sampling_enabled')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->boolean('ai_quality_timeout_sampling_enabled')
                    ->default(false)
                    ->after('ai_quality_enabled');
            });
        }

        if (! Schema::hasTable('article_ai_quality_checks')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            if (! Schema::hasColumn('article_ai_quality_checks', 'primary_deadline_at')) {
                $table->timestamp('primary_deadline_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('article_ai_quality_checks', 'sampled_deadline_at')) {
                $table->timestamp('sampled_deadline_at')->nullable()->after('primary_deadline_at');
            }
            if (! Schema::hasColumn('article_ai_quality_checks', 'inspection_scope')) {
                $table->string('inspection_scope', 30)->default('full')->after('evaluation_mode');
            }
            if (! Schema::hasColumn('article_ai_quality_checks', 'fallback_trigger_code')) {
                $table->string('fallback_trigger_code', 80)->nullable()->after('inspection_scope');
            }
            if (! Schema::hasColumn('article_ai_quality_checks', 'coverage_meta')) {
                $table->json('coverage_meta')->nullable()->after('execution_meta');
            }
        });

        if (! Schema::hasIndex('article_ai_quality_checks', 'article_ai_quality_primary_deadline_idx')) {
            Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
                $table->index(['status', 'primary_deadline_at'], 'article_ai_quality_primary_deadline_idx');
            });
        }
        if (! Schema::hasIndex('article_ai_quality_checks', 'article_ai_quality_scope_created_idx')) {
            Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
                $table->index(['inspection_scope', 'created_at'], 'article_ai_quality_scope_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('article_ai_quality_checks')) {
            if (Schema::hasIndex('article_ai_quality_checks', 'article_ai_quality_primary_deadline_idx')) {
                Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
                    $table->dropIndex('article_ai_quality_primary_deadline_idx');
                });
            }
            if (Schema::hasIndex('article_ai_quality_checks', 'article_ai_quality_scope_created_idx')) {
                Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
                    $table->dropIndex('article_ai_quality_scope_created_idx');
                });
            }
            foreach (['primary_deadline_at', 'sampled_deadline_at', 'inspection_scope', 'fallback_trigger_code', 'coverage_meta'] as $column) {
                if (Schema::hasColumn('article_ai_quality_checks', $column)) {
                    Schema::table('article_ai_quality_checks', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'ai_quality_timeout_sampling_enabled')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('ai_quality_timeout_sampling_enabled');
            });
        }
    }
};
