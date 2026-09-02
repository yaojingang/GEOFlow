<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\TaskController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class ApiTaskModelProjectionArchitectureTest extends TestCase
{
    #[DataProvider('taskApiActions')]
    #[Test]
    public function task_api_actions_resolve_the_authenticated_execution_admin(string $method): void
    {
        $source = $this->methodSource(TaskController::class, $method);

        $this->assertStringContainsString('executionAdmin($request)', $source, $method);
    }

    #[DataProvider('taskDetailMutationActions')]
    #[Test]
    public function cached_task_detail_mutations_reproject_model_references(string $method): void
    {
        $source = $this->methodSource(TaskController::class, $method);

        $this->assertStringContainsString('refreshTaskModelProjection(', $source, $method);
    }

    #[DataProvider('viewerBoundTaskServiceCalls')]
    #[Test]
    public function task_api_service_calls_carry_the_non_null_viewer(string $method, string $serviceMethod): void
    {
        $source = $this->methodSource(TaskController::class, $method);

        $this->assertStringContainsString('$viewer = $this->executionAdmin($request);', $source, $method);
        $this->assertMatchesRegularExpression(
            '/\$tasks->'.preg_quote($serviceMethod, '/').'\s*\([\s\S]*\$viewer\s*,?\s*\)/',
            $source,
            $method,
        );
    }

    #[Test]
    public function job_detail_service_call_carries_the_non_null_viewer(): void
    {
        $source = $this->methodSource(JobController::class, 'show');

        $this->assertStringContainsString('$viewer = $this->executionAdmin($request);', $source);
        $this->assertStringContainsString('$tasks->getJob($job, $viewer)', $source);
        $this->assertStringNotContainsString('$tasks->getJob($job)', $source);
    }

    #[Test]
    public function api_task_reads_pass_a_viewer_and_monitoring_uses_the_access_resolver(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/TaskController.php'));
        $monitoring = file_get_contents(app_path('Services/GeoFlow/TaskMonitoringQueryService.php'));

        $this->assertIsString($controller);
        $this->assertIsString($monitoring);
        $this->assertDoesNotMatchRegularExpression(
            '/->getTask\s*\(\s*\$task\s*\)/',
            $controller,
            'API task detail reads must never select the anonymous governance projection.',
        );
        $this->assertStringContainsString('AdminAiModelAccessResolver $adminAiModelAccessResolver', $monitoring);
        $this->assertStringContainsString('->usableQuery($modelViewer)', $monitoring);
        $this->assertStringContainsString('?Admin $modelViewer = null', $monitoring);
    }

    /** @return array<string, array{string}> */
    public static function taskApiActions(): array
    {
        return collect([
            'index',
            'store',
            'show',
            'update',
            'destroy',
            'start',
            'stop',
            'enqueue',
            'jobs',
        ])->mapWithKeys(static fn (string $method): array => [$method => [$method]])->all();
    }

    /** @return array<string, array{string}> */
    public static function taskDetailMutationActions(): array
    {
        return collect(['store', 'update', 'start', 'stop'])
            ->mapWithKeys(static fn (string $method): array => [$method => [$method]])
            ->all();
    }

    /** @return array<string, array{string,string}> */
    public static function viewerBoundTaskServiceCalls(): array
    {
        return [
            'listTasks' => ['index', 'listTasks'],
            'getTask' => ['show', 'getTask'],
            'createTask' => ['store', 'createTask'],
            'updateTask' => ['update', 'updateTask'],
            'startTask' => ['start', 'startTask'],
            'stopTask' => ['stop', 'stopTask'],
            'enqueueTask' => ['enqueue', 'enqueueTask'],
            'listTaskJobs' => ['jobs', 'listTaskJobs'],
        ];
    }

    /** @param class-string $class */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
