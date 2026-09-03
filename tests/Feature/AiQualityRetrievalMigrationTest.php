<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiQualityRetrievalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieval_schema_migrations_support_down_and_up_again(): void
    {
        $paths = [
            database_path('migrations/2026_08_31_120322_add_ai_quality_retrieval_configuration_to_tasks_table.php'),
            database_path('migrations/2026_08_31_120323_add_ai_quality_retrieval_configuration_to_articles_table.php'),
            database_path('migrations/2026_08_31_120324_create_article_ai_quality_knowledge_bases_table.php'),
            database_path('migrations/2026_08_31_120325_add_retrieval_fields_to_article_ai_quality_checks_table.php'),
            database_path('migrations/2026_08_31_120326_create_article_ai_quality_check_sources_table.php'),
            database_path('migrations/2026_08_31_120327_add_ai_quality_readiness_projection.php'),
            database_path('migrations/2026_08_31_120328_add_serving_generation_to_knowledge_chunks.php'),
            database_path('migrations/2026_08_31_120329_add_epoch_to_article_ai_quality_rollouts_table.php'),
            database_path('migrations/2026_08_31_120330_create_ai_quality_audit_events_table.php'),
            database_path('migrations/2026_09_02_154000_harden_knowledge_embedding_profiles.php'),
            database_path('migrations/2026_09_02_155000_expand_knowledge_embedding_provider_columns.php'),
        ];
        $migrations = array_map(static fn (string $path): object => require $path, $paths);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertFalse(Schema::hasColumn('tasks', 'ai_quality_retrieval_mode'));
        $this->assertFalse(Schema::hasColumn('articles', 'ai_quality_retrieval_mode_override'));
        $this->assertFalse(Schema::hasTable('article_ai_quality_knowledge_bases'));
        $this->assertFalse(Schema::hasColumn('article_ai_quality_checks', 'retrieval_basis_hash'));
        $this->assertFalse(Schema::hasTable('article_ai_quality_check_sources'));
        $this->assertFalse(Schema::hasColumn('knowledge_bases', 'ai_quality_content_hash'));
        $this->assertFalse(Schema::hasColumn('knowledge_fact_libraries', 'active_fact_count'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunks', 'generation_key'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunks', 'embedding_profile_digest'));
        $this->assertFalse(Schema::hasColumn('article_ai_quality_rollouts', 'epoch'));
        $this->assertFalse(Schema::hasTable('ai_quality_audit_events'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumns('tasks', [
            'ai_quality_retrieval_mode',
            'ai_quality_policy_version',
        ]));
        $this->assertTrue(Schema::hasColumns('articles', [
            'ai_quality_retrieval_mode_override',
            'ai_quality_policy_version',
        ]));
        $this->assertTrue(Schema::hasTable('article_ai_quality_knowledge_bases'));
        $this->assertTrue(Schema::hasColumns('article_ai_quality_checks', [
            'requested_retrieval_mode',
            'effective_retrieval_mode',
            'retrieval_strategy_version',
            'retrieval_failure_code',
            'retrieval_basis_hash',
        ]));
        $this->assertTrue(Schema::hasTable('article_ai_quality_check_sources'));
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'ai_quality_content_hash',
            'ai_quality_content_length',
        ]));
        $this->assertTrue(Schema::hasColumn('knowledge_fact_libraries', 'active_fact_count'));
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'chunk_serving_generation',
            'chunk_serving_source_hash',
            'chunk_manifest_hash',
        ]));
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'generation_key'));
        $this->assertTrue(Schema::hasColumns('knowledge_chunks', [
            'embedding_profile_version',
            'embedding_profile_digest',
            'embedding_config_revision',
        ]));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_rollouts', 'epoch'));
        $this->assertTrue(Schema::hasTable('ai_quality_audit_events'));
    }
}
