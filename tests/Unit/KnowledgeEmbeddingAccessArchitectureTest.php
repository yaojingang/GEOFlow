<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class KnowledgeEmbeddingAccessArchitectureTest extends TestCase
{
    #[Test]
    public function knowledge_embedding_entry_points_use_the_dedicated_access_resolvers(): void
    {
        foreach ([
            app_path('Services/GeoFlow/KnowledgeChunkSyncService.php'),
            app_path('Services/GeoFlow/KnowledgeRetrievalService.php'),
            app_path('Services/AiWorkspace/AdminHelpKnowledgeRetriever.php'),
            app_path('Jobs/PrepareKnowledgeChunkSyncJob.php'),
            app_path('Jobs/EmbedKnowledgeChunkBatchJob.php'),
            app_path('Jobs/FinalizeKnowledgeChunkSyncJob.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/\bAiModel::query\s*\(/', $source, $file);
        }

        $syncService = (string) file_get_contents(app_path('Services/GeoFlow/KnowledgeChunkSyncService.php'));
        $this->assertStringContainsString('SystemAiModelAccessResolver', $syncService);
        $this->assertStringContainsString('AdminAiModelAccessResolver', $syncService);
        $this->assertStringContainsString('AiExecutionAccessGuard', $syncService);
    }
}
