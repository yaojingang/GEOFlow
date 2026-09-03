<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Models\Admin;
use App\Services\GeoFlow\TaskLifecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;
use TypeError;

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
    public function task_api_service_calls_use_the_dedicated_non_nullable_projection_boundary(string $method, string $serviceMethod): void
    {
        $source = $this->methodSource(TaskController::class, $method);

        $this->assertStringContainsString('$viewer = $this->executionAdmin($request);', $source, $method);
        $this->assertStringContainsString('$tasks->'.$serviceMethod.'(', $source, $method);
        $this->assertStringContainsString('viewer: $viewer', $source, $method);
        $this->assertSame([], $this->unsafeProjectionCalls($source), $method);
    }

    #[Test]
    public function job_detail_service_call_carries_the_non_null_viewer(): void
    {
        $source = $this->methodSource(JobController::class, 'show');

        $this->assertStringContainsString('$viewer = $this->executionAdmin($request);', $source);
        $this->assertStringContainsString('$tasks->getJobForApi(', $source);
        $this->assertStringContainsString('viewer: $viewer', $source);
        $this->assertSame([], $this->unsafeProjectionCalls($source));
    }

    #[Test]
    public function api_task_reads_pass_a_viewer_and_monitoring_uses_the_access_resolver(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/TaskController.php'));
        $monitoring = file_get_contents(app_path('Services/GeoFlow/TaskMonitoringQueryService.php'));

        $this->assertIsString($controller);
        $this->assertIsString($monitoring);
        $this->assertDoesNotMatchRegularExpression(
            '/->getTask\s*\(/',
            $controller,
            'API task detail reads must never select the anonymous governance projection.',
        );
        $this->assertStringContainsString('AdminAiModelAccessResolver $adminAiModelAccessResolver', $monitoring);
        $this->assertStringContainsString('->usableQuery($modelViewer)', $monitoring);
        $this->assertStringContainsString('?Admin $modelViewer = null', $monitoring);
    }

    #[Test]
    public function api_projection_boundary_rejects_a_cosmetic_viewer_and_nullable_governance_call(): void
    {
        $cosmeticViewer = <<<'PHP'
        $viewer = $this->executionAdmin($request);
        $viewerWasResolved = $viewer->id > 0;
        return $tasks->getTask($task, null);
        PHP;
        $nullableDedicatedCall = <<<'PHP'
        $viewer = $this->executionAdmin($request);
        return $tasks->getTaskForApi(taskId: $task, viewer: null);
        PHP;

        $this->assertSame(['getTask'], $this->unsafeProjectionCalls($cosmeticViewer));
        $this->assertSame(['nullable_api_viewer'], $this->unsafeProjectionCalls($nullableDedicatedCall));
    }

    #[Test]
    public function api_controller_directory_never_calls_nullable_task_projection_methods(): void
    {
        $paths = glob(app_path('Http/Controllers/Api/**/*.php')) ?: [];
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source, $path);
            $this->assertSame([], $this->unsafeProjectionCalls($source), $path);
        }
    }

    #[Test]
    public function dedicated_api_projection_method_rejects_a_null_viewer_at_the_type_boundary(): void
    {
        $this->expectException(TypeError::class);

        app(TaskLifecycleService::class)->getTaskForApi(1, null);
    }

    #[DataProvider('apiProjectionMethods')]
    #[Test]
    public function dedicated_api_projection_methods_require_a_non_nullable_admin(string $method): void
    {
        $reflection = new ReflectionMethod(TaskLifecycleService::class, $method);
        $viewer = collect($reflection->getParameters())->firstWhere('name', 'viewer');

        $this->assertNotNull($viewer, $method);
        $this->assertFalse($viewer->allowsNull(), $method);
        $type = $viewer->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type, $method);
        $this->assertSame(Admin::class, $type->getName(), $method);
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
            'listTasks' => ['index', 'listTasksForApi'],
            'getTask' => ['show', 'getTaskForApi'],
            'createTask' => ['store', 'createTaskForApi'],
            'updateTask' => ['update', 'updateTaskForApi'],
            'startTask' => ['start', 'startTaskForApi'],
            'stopTask' => ['stop', 'stopTaskForApi'],
            'enqueueTask' => ['enqueue', 'enqueueTaskForApi'],
            'listTaskJobs' => ['jobs', 'listTaskJobsForApi'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function apiProjectionMethods(): array
    {
        return collect([
            'listTasksForApi',
            'getTaskForApi',
            'createTaskForApi',
            'updateTaskForApi',
            'startTaskForApi',
            'stopTaskForApi',
            'enqueueTaskForApi',
            'listTaskJobsForApi',
            'getJobForApi',
        ])->mapWithKeys(static fn (string $method): array => [$method => [$method]])->all();
    }

    /** @return list<string> */
    private function unsafeProjectionCalls(string $source): array
    {
        $violations = [];
        foreach (['listTasks', 'getTask', 'createTask', 'updateTask', 'startTask', 'stopTask', 'enqueueTask', 'listTaskJobs', 'getJob'] as $method) {
            if (preg_match('/->'.preg_quote($method, '/').'\s*\(/', $source) === 1) {
                $violations[] = $method;
            }
        }
        if (preg_match('/->\w+ForApi\s*\([^;]*\bviewer\s*:\s*null\b/s', $source) === 1) {
            $violations[] = 'nullable_api_viewer';
        }

        return $violations;
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
