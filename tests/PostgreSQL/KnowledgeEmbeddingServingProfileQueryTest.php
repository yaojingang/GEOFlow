<?php

namespace Tests\PostgreSQL;

final class KnowledgeEmbeddingServingProfileQueryTest extends PostgreSqlTestCase
{
    public function test_pgvector_query_requires_the_complete_serving_profile(): void
    {
        $source = (string) file_get_contents(app_path('Services/GeoFlow/KnowledgeRetrievalService.php'));

        foreach ([
            'AND embedding_model_id = ?',
            'AND embedding_dimensions = ?',
            'AND embedding_provider = ?',
            'AND embedding_fingerprint = ?',
            'AND embedding_profile_version = ?',
            'AND embedding_profile_digest = ?',
        ] as $requiredPredicate) {
            $this->assertStringContainsString($requiredPredicate, $source);
        }
    }

    public function test_provider_columns_follow_the_ai_model_url_width_contract(): void
    {
        $migration = (string) file_get_contents(database_path(
            'migrations/2026_09_02_155000_expand_knowledge_embedding_provider_columns.php',
        ));

        $this->assertStringContainsString("string('chunk_embedding_provider', 500)->nullable()->change()", $migration);
        $this->assertSame(2, substr_count($migration, "string('embedding_provider', 500)->default('')->change()"));
        $this->assertStringContainsString("string('chunk_embedding_provider', 255)->nullable()->change()", $migration);
        $this->assertSame(2, substr_count($migration, "string('embedding_provider', 255)->default('')->change()"));
    }
}
