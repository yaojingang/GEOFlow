<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VectorStoreArchitectureTest extends TestCase
{
    #[Test]
    public function vector_consumers_use_the_injected_adapter_and_keep_sql_dialect_out_of_services(): void
    {
        $root = dirname(__DIR__, 2);
        $sync = (string) file_get_contents($root.'/app/Services/GeoFlow/KnowledgeChunkSyncService.php');
        $retrieval = (string) file_get_contents($root.'/app/Services/GeoFlow/KnowledgeRetrievalService.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/Admin/AiModelController.php');
        $provider = (string) file_get_contents($root.'/app/Providers/AppServiceProvider.php');

        self::assertStringContainsString('VectorStoreAdapter', $sync);
        self::assertStringContainsString('writeValue(', $sync);
        self::assertStringContainsString('VectorStoreAdapter', $retrieval);
        self::assertStringContainsString('similarityExpression(', $retrieval);
        self::assertStringNotContainsString('LIMIT ?', $retrieval);
        self::assertStringContainsString('VectorStoreAdapter', $controller);
        self::assertStringContainsString('singleton(VectorStoreAdapter::class', $provider);
    }
}
