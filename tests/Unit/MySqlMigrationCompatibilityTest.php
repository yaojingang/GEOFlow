<?php

namespace Tests\Unit;

use Tests\TestCase;

class MySqlMigrationCompatibilityTest extends TestCase
{
    public function test_legacy_schema_has_a_mysql_fresh_install_path(): void
    {
        $migration = $this->migration('2026_04_18_120000_mysql_geoflow_schema.php');

        $this->assertStringContainsString("['mysql', 'mariadb']", $migration);
        $this->assertStringContainsString('$this->mysqlSchema()', $migration);
        $this->assertStringContainsString('private function mysqlSchema(): void', $migration);
        $this->assertStringContainsString('Schema::disableForeignKeyConstraints()', $migration);
        $this->assertStringContainsString('Schema::create($name, $definition)', $migration);

        foreach ([
            'ai_models',
            'prompts',
            'keyword_libraries',
            'keywords',
            'title_libraries',
            'titles',
            'image_libraries',
            'images',
            'knowledge_bases',
            'knowledge_chunks',
            'authors',
            'tasks',
            'categories',
            'articles',
            'article_images',
            'sensitive_words',
            'task_schedules',
            'system_logs',
            'admin_activity_logs',
            'article_reviews',
            'url_import_jobs',
            'url_import_job_logs',
            'task_runs',
            'worker_heartbeats',
            'api_idempotency_keys',
        ] as $table) {
            $this->assertStringContainsString("'{$table}'", $migration);
        }
    }

    public function test_mysql_vector_migration_uses_the_native_vector_type(): void
    {
        $migration = $this->migration('2026_04_18_120001_mysql_vector_column.php');

        $this->assertStringContainsString("['mysql', 'mariadb']", $migration);
        $this->assertStringContainsString('VECTOR(3072)', $migration);
        $this->assertStringContainsString('VECTOR_DIM(VEC_FROMTEXT(?))', $migration);
        $this->assertStringContainsString("Schema::hasColumn('knowledge_chunks', 'embedding_vector')", $migration);
        $this->assertStringContainsString('CREATE EXTENSION', file_get_contents(
            database_path('migrations/2026_04_18_110000_enable_pgvector_extension.php')
        ));
    }

    public function test_mysql_branches_cover_scalar_expression_and_foreign_key_migrations(): void
    {
        $taskLimit = $this->migration('2026_04_25_130000_add_task_article_limit_and_publish_clock.php');
        $compatibility = $this->migration('2026_09_21_000100_mysql_compatibility.php');

        $this->assertStringContainsString("'pgsql', 'mysql' => 'GREATEST(", $taskLimit);
        $this->assertStringContainsString('ensureArticleTaskForeignKeyUsesSetNull', $compatibility);
        $this->assertStringContainsString('widenActiveDedupeKey', $compatibility);
        $this->assertStringContainsString('active_dedupe_key', $compatibility);
    }

    public function test_mysql_skips_unverified_statement_level_revision_triggers(): void
    {
        $migration = $this->migration('2026_09_13_000300_track_url_data_revisions.php');

        $this->assertStringNotContainsString("DB::getDriverName() === 'mysql'", $migration);
        $this->assertStringContainsString('REFERENCING', $migration);
    }

    public function test_historical_postgres_migrations_remain_immutable(): void
    {
        $legacy = $this->migration('2026_04_18_120000_geoflow_legacy_schema.php');
        $vector = $this->migration('2026_04_18_120001_geoflow_knowledge_chunks_embedding_vector.php');

        $this->assertStringNotContainsString('mysqlSchema', $legacy);
        $this->assertStringNotContainsString('VECTOR(3072)', $vector);
    }

    private function migration(string $name): string
    {
        $contents = file_get_contents(database_path('migrations/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }
}
