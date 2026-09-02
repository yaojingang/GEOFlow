<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class AdminAiWorkerAccessArchitectureTest extends TestCase
{
    #[Test]
    public function content_worker_resolves_models_exclusively_through_the_persisted_admin_guard(): void
    {
        $source = file_get_contents(app_path('Services/GeoFlow/WorkerExecutionService.php'));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/\bAiModel::\s*(?:query|where|find|findOrFail)\s*\(/',
            $source,
        );
        $this->assertStringNotContainsString('AiModel::', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/\bDB::table\s*\(\s*[\'\"]ai_models[\'\"]\s*\)/',
            $source,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$(?:task|freshTask)->(?:aiModel|qualityModel)\b/',
            $source,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:->(?:load|loadMissing|getRelation|relationLoaded)|::with)\s*\([^;]*(?:aiModel|qualityModel)/s',
            $source,
        );
        $this->assertStringNotContainsString('resolveModelCandidatesForShadow(', $source);
        $this->assertStringNotContainsString('assertModelCurrentForShadow(', $source);

        $configuredSelection = $this->methodSource('resolveConfiguredAiModel');
        $this->assertStringContainsString(
            'assertModelCurrent($executionContext, $aiModelId)',
            $configuredSelection,
        );
        $this->assertDoesNotMatchRegularExpression('/\b(?:AiModel|DB)::/', $configuredSelection);

        $candidateSelection = $this->methodSource('resolveAiModelCandidates');
        $this->assertStringContainsString(
            'resolveConfiguredAiModel($task, $executionContext)',
            $candidateSelection,
        );
        $this->assertStringContainsString(
            "resolveModelCandidates(\$executionContext, 'chat')",
            $candidateSelection,
        );
        $this->assertSame(1, substr_count($candidateSelection, 'resolveModelCandidates('));
        $this->assertDoesNotMatchRegularExpression('/\b(?:AiModel|DB)::/', $candidateSelection);
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(WorkerExecutionService::class, $method);
        $lines = file($reflection->getFileName());

        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
