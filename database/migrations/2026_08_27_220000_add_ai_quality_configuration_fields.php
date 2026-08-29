<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompts')) {
            Schema::table('prompts', function (Blueprint $table): void {
                if (! Schema::hasColumn('prompts', 'system_key')) {
                    $table->string('system_key', 120)->nullable()->unique();
                }
                if (! Schema::hasColumn('prompts', 'system_version')) {
                    $table->string('system_version', 40)->nullable();
                }
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('tasks', 'ai_quality_enabled')) {
                    $table->boolean('ai_quality_enabled')->default(false)->index();
                }
                if (! Schema::hasColumn('tasks', 'ai_quality_prompt_id')) {
                    $table->foreignId('ai_quality_prompt_id')->nullable()->constrained('prompts')->nullOnDelete();
                }
                if (! Schema::hasColumn('tasks', 'ai_quality_model_id')) {
                    $table->foreignId('ai_quality_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
                }
                if (! Schema::hasColumn('tasks', 'ai_quality_pass_score')) {
                    $table->unsignedTinyInteger('ai_quality_pass_score')->default(85);
                }
                if (! Schema::hasColumn('tasks', 'ai_quality_manual_override_min_score')) {
                    $table->unsignedTinyInteger('ai_quality_manual_override_min_score')->default(70);
                }
            });
        }

        if (Schema::hasTable('articles')) {
            Schema::table('articles', function (Blueprint $table): void {
                if (! Schema::hasColumn('articles', 'ai_quality_required_at_creation')) {
                    $table->boolean('ai_quality_required_at_creation')->default(false)->index();
                }
                if (! Schema::hasColumn('articles', 'ai_quality_policy_snapshot')) {
                    $table->json('ai_quality_policy_snapshot')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('articles')) {
            Schema::table('articles', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'ai_quality_required_at_creation',
                    'ai_quality_policy_snapshot',
                ], static fn (string $column): bool => Schema::hasColumn('articles', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('tasks', 'ai_quality_prompt_id')) {
                    $table->dropConstrainedForeignId('ai_quality_prompt_id');
                }
                if (Schema::hasColumn('tasks', 'ai_quality_model_id')) {
                    $table->dropConstrainedForeignId('ai_quality_model_id');
                }
                $columns = array_values(array_filter([
                    'ai_quality_enabled',
                    'ai_quality_pass_score',
                    'ai_quality_manual_override_min_score',
                ], static fn (string $column): bool => Schema::hasColumn('tasks', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('prompts')) {
            Schema::table('prompts', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'system_key',
                    'system_version',
                ], static fn (string $column): bool => Schema::hasColumn('prompts', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
