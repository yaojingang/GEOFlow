<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAiWorkerAccessArchitectureTest extends TestCase
{
    #[Test]
    public function content_worker_resolves_models_exclusively_through_the_persisted_admin_guard(): void
    {
        $source = file_get_contents(app_path('Services/GeoFlow/WorkerExecutionService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('AiModel::query(', $source);
        $this->assertStringNotContainsString('resolveModelCandidatesForShadow(', $source);
        $this->assertStringNotContainsString('assertModelCurrentForShadow(', $source);
        $this->assertStringContainsString(
            "resolveModelCandidates(\$executionContext, 'chat')",
            $source,
        );
    }
}
