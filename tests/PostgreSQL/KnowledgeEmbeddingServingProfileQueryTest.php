<?php

namespace Tests\PostgreSQL;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

final class KnowledgeEmbeddingServingProfileQueryTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

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

    public function test_postgresql_provider_columns_store_the_full_ai_model_url_width(): void
    {
        $columns = [
            ['knowledge_bases', 'chunk_embedding_provider'],
            ['knowledge_chunks', 'embedding_provider'],
            ['knowledge_chunk_sync_rows', 'embedding_provider'],
        ];
        foreach ($columns as [$table, $column]) {
            $length = DB::table('information_schema.columns')
                ->whereRaw('table_schema = current_schema()')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('character_maximum_length');
            $this->assertSame(500, (int) $length, $table.'.'.$column);
        }

        $providerUrl = 'https://system.test/'.str_repeat('A', 480);
        $knowledgeBaseId = DB::table('knowledge_bases')->insertGetId([
            'name' => 'PostgreSQL provider width',
            'content' => 'provider width',
            'chunk_embedding_provider' => $providerUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $knowledgeChunkId = DB::table('knowledge_chunks')->insertGetId([
                'knowledge_base_id' => $knowledgeBaseId,
                'chunk_index' => 0,
                'content' => 'provider width',
                'embedding_provider' => $providerUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stagingId = DB::table('knowledge_chunk_sync_rows')->insertGetId([
                'knowledge_base_id' => $knowledgeBaseId,
                'sync_token' => 'postgres-provider-width',
                'chunk_index' => 0,
                'content' => 'provider width',
                'embedding_provider' => $providerUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame($providerUrl, DB::table('knowledge_bases')->where('id', $knowledgeBaseId)->value('chunk_embedding_provider'));
            $this->assertSame($providerUrl, DB::table('knowledge_chunks')->where('id', $knowledgeChunkId)->value('embedding_provider'));
            $this->assertSame($providerUrl, DB::table('knowledge_chunk_sync_rows')->where('id', $stagingId)->value('embedding_provider'));
        } finally {
            DB::table('knowledge_chunk_sync_rows')->where('knowledge_base_id', $knowledgeBaseId)->delete();
            DB::table('knowledge_chunks')->where('knowledge_base_id', $knowledgeBaseId)->delete();
            DB::table('knowledge_bases')->where('id', $knowledgeBaseId)->delete();
        }
    }
}
