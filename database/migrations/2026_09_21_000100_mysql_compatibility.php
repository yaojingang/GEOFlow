<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apply MySQL-only fixes after the full historical migration stream has run.
 *
 * This keeps already deployed migrations immutable while repairing constraints
 * and column widths for fresh and existing MySQL GEOFlow databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->ensureArticleTaskForeignKeyUsesSetNull();
        $this->widenActiveDedupeKey();
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (Schema::hasTable('article_ai_quality_checks')
            && Schema::hasColumn('article_ai_quality_checks', 'active_dedupe_key')) {
            DB::table('article_ai_quality_checks')
                ->whereNotNull('active_dedupe_key')
                ->whereRaw('CHAR_LENGTH(active_dedupe_key) > 40')
                ->update(['active_dedupe_key' => null]);

            Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
                $table->string('active_dedupe_key', 40)->nullable()->change();
            });
        }
    }

    private function ensureArticleTaskForeignKeyUsesSetNull(): void
    {
        if (! Schema::hasTable('articles')
            || ! Schema::hasTable('tasks')
            || ! Schema::hasColumn('articles', 'task_id')) {
            return;
        }

        $foreignKey = collect(Schema::getForeignKeys('articles'))
            ->first(static fn (array $key): bool => $key['columns'] === ['task_id']
                && $key['foreign_table'] === 'tasks');

        if ($foreignKey === null
            || strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'set null') {
            return;
        }

        Schema::table('articles', function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign((string) $foreignKey['name']);
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreign('task_id', 'articles_task_id_foreign')
                ->references('id')
                ->on('tasks')
                ->nullOnDelete();
        });
    }

    private function widenActiveDedupeKey(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || ! Schema::hasColumn('article_ai_quality_checks', 'active_dedupe_key')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->char('active_dedupe_key', 64)->nullable()->change();
        });
    }
};
