<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARTICLE_FOREIGN_KEY = 'view_logs_article_id_foreign';

    private const TEMPORARY_ARTICLE_FOREIGN_KEY = 'view_logs_article_id_set_null_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('view_logs') || ! Schema::hasColumn('view_logs', 'article_id')) {
            return;
        }

        $foreignKey = $this->articleForeignKey();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE "view_logs" ADD CONSTRAINT "%s" FOREIGN KEY ("article_id") REFERENCES "articles" ("id") ON DELETE SET NULL NOT VALID',
                self::TEMPORARY_ARTICLE_FOREIGN_KEY,
            ));
            $this->dropForeignKey($foreignKey);
            DB::statement(sprintf(
                'ALTER TABLE "view_logs" RENAME CONSTRAINT "%s" TO "%s"',
                self::TEMPORARY_ARTICLE_FOREIGN_KEY,
                self::ARTICLE_FOREIGN_KEY,
            ));

            return;
        }

        $this->dropForeignKey($foreignKey);

        Schema::table('view_logs', function (Blueprint $table): void {
            $table->foreign('article_id', self::ARTICLE_FOREIGN_KEY)
                ->references('id')
                ->on('articles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('view_logs') || ! Schema::hasColumn('view_logs', 'article_id')) {
            return;
        }

        $this->dropForeignKey($this->articleForeignKey());

        Schema::table('view_logs', function (Blueprint $table): void {
            $table->foreign('article_id', self::ARTICLE_FOREIGN_KEY)
                ->references('id')
                ->on('articles')
                ->noActionOnDelete();
        });
    }

    /** @return array{name:string,columns:list<string>,foreign_table:string}|null */
    private function articleForeignKey(): ?array
    {
        return collect(Schema::getForeignKeys('view_logs'))
            ->first(static fn (array $key): bool => $key['columns'] === ['article_id']
                && $key['foreign_table'] === 'articles');
    }

    /** @param array{name:string,columns:list<string>,foreign_table:string}|null $foreignKey */
    private function dropForeignKey(?array $foreignKey): void
    {
        if ($foreignKey === null) {
            return;
        }

        Schema::table('view_logs', function (Blueprint $table) use ($foreignKey): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['article_id']);

                return;
            }

            $table->dropForeign((string) $foreignKey['name']);
        });
    }
};
