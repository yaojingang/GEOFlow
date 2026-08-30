<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARTICLE_FOREIGN_KEY = 'view_logs_article_id_foreign';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('view_logs')
            || ! Schema::hasColumn('view_logs', 'article_id')) {
            return;
        }

        $foreignKey = collect(Schema::getForeignKeys('view_logs'))
            ->first(static fn (array $key): bool => $key['name'] === self::ARTICLE_FOREIGN_KEY
                && $key['columns'] === ['article_id']
                && $key['foreign_table'] === 'articles'
                && strtolower((string) $key['on_delete']) === 'set null');

        if ($foreignKey === null) {
            throw new RuntimeException('The view_logs article foreign key is missing or has an unexpected delete rule.');
        }

        DB::statement(sprintf(
            'ALTER TABLE "view_logs" VALIDATE CONSTRAINT "%s"',
            self::ARTICLE_FOREIGN_KEY,
        ));
    }

    public function down(): void {}
};
