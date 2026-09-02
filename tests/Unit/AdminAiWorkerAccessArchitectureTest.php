<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame([], $this->workerModelBoundaryViolations($source));
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

    #[Test]
    #[DataProvider('forbiddenWorkerModelAccessSnippets')]
    public function worker_model_boundary_detector_rejects_forbidden_access_variants(string $source): void
    {
        $this->assertNotSame([], $this->workerModelBoundaryViolations($source));
    }

    /** @return array<string,array{string}> */
    public static function forbiddenWorkerModelAccessSnippets(): array
    {
        return [
            'direct model query' => ['AiModel::query()->whereKey(1)->first();'],
            'direct model where query' => ['AiModel::where(\'status\', \'active\')->first();'],
            'direct model find query' => ['AiModel::find(1);'],
            'direct model find-or-fail query' => ['AiModel::findOrFail(1);'],
            'task query eager-loads configured model' => ['Task::query()->with(\'aiModel\')->first();'],
            'task query eager-loads quality model from array' => ['Task::query()->with([\'knowledgeBases\', \'qualityModel\'])->first();'],
            'static eager-load array' => ['Task::with([\'aiModel\', \'qualityModel\'])->first();'],
            'constrained eager-load array' => ['Task::query()->with([\'aiModel:id,name\'])->first();'],
            'model relation property' => ['$task->aiModel;'],
            'quality relation property' => ['$freshTask->qualityModel;'],
            'load configured model relation' => ['$task->load(\'aiModel\');'],
            'load missing quality model relation from array' => ['$task->loadMissing([\'knowledgeBases\', \'qualityModel\']);'],
            'get configured model relation' => ['$task->getRelation(\'aiModel\');'],
            'check quality model relation' => ['$task->relationLoaded(\'qualityModel\');'],
            'query model table directly' => ['DB::table(\'ai_models\')->first();'],
            'query builder model table directly' => ['DB::query()->from(\'ai_models\')->first();'],
            'existing query builder model table directly' => ['$query->from(\'ai_models\')->first();'],
            'connection query model table directly' => ['DB::connection()->table(\'ai_models\')->first();'],
            'aliased model table query' => ['$query->from(\'ai_models as models\')->first();'],
        ];
    }

    /** @return list<string> */
    private function workerModelBoundaryViolations(string $source): array
    {
        $violations = [];

        if (str_contains($source, 'AiModel::')) {
            $violations[] = 'static_ai_model_access';
        }
        if (preg_match('/(?:\bDB::|->)(?:table|from)\s*\(\s*[\'\"]ai_models(?:\s+(?:as\s+)?[a-z_][a-z0-9_]*)?[\'\"]\s*\)/i', $source) === 1) {
            $violations[] = 'direct_ai_models_table_access';
        }
        if (preg_match('/\??->(?:aiModel|qualityModel)\b/', $source) === 1) {
            $violations[] = 'direct_model_relation_access';
        }
        if (preg_match('/(?:->|::)(?:with|load|loadMissing|getRelation|relationLoaded)\s*\([^;]*[\'\"](?:aiModel|qualityModel)(?:(?::|\.)[^\'\"]*)?[\'\"]/s', $source) === 1) {
            $violations[] = 'model_relation_resolution';
        }

        return $violations;
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
