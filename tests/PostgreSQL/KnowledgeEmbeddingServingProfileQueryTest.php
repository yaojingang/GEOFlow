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
}
