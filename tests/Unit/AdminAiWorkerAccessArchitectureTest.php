<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerAiModelInvocationGateway;
use App\Services\GeoFlow\WorkerExecutionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
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
    public function content_worker_provider_calls_use_the_locked_invocation_gateway(): void
    {
        $workerSource = file_get_contents(app_path('Services/GeoFlow/WorkerExecutionService.php'));

        $this->assertIsString($workerSource);
        $this->assertStringNotContainsString('ArticleContentGenerationService', $workerSource);
        $this->assertStringNotContainsString('AiModelInvocationLock', $workerSource);
        $this->assertDoesNotMatchRegularExpression('/->(?:prompt|stream)\s*\(/', $workerSource);

        $workerGeneration = $this->methodSource('generateContent');
        $this->assertStringContainsString('aiModelInvocationGateway->generate(', $workerGeneration);

        $gatewaySource = file_get_contents(app_path('Services/GeoFlow/WorkerAiModelInvocationGateway.php'));
        $this->assertIsString($gatewaySource);
        $this->assertStringNotContainsString('AiModel::', $gatewaySource);
        $this->assertStringNotContainsString('DB::', $gatewaySource);

        $gatewayGeneration = $this->methodSource('generate', WorkerAiModelInvocationGateway::class);
        $lockPosition = strpos($gatewayGeneration, 'acquireForInvocation(');
        $guardPosition = strpos($gatewayGeneration, 'assertModelCurrent(');
        $providerPosition = strpos($gatewayGeneration, 'generationService->generate(');
        $receiptPosition = strpos($gatewayGeneration, 'assertReceiptCurrent(');
        $persistencePosition = strpos($gatewayGeneration, '$persistResponse([');
        $releasePosition = strpos($gatewayGeneration, 'invocationLocks->release(');

        foreach ([$lockPosition, $guardPosition, $providerPosition, $receiptPosition, $persistencePosition, $releasePosition] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($guardPosition, $lockPosition);
        $this->assertLessThan($providerPosition, $guardPosition);
        $this->assertLessThan($receiptPosition, $providerPosition);
        $this->assertLessThan($persistencePosition, $receiptPosition);
        $this->assertLessThan($releasePosition, $persistencePosition);
        $this->assertStringContainsString('finally', $gatewayGeneration);

        $workerGeneration = $this->methodSource('generateContent');
        $this->assertStringContainsString('persistGeneratedContent(', $workerGeneration);
        $workerExecution = $this->methodSource('executeTask');
        $persistenceCallback = strpos($workerExecution, 'function (array $generation)');
        $businessTransaction = strpos($workerExecution, 'return DB::transaction(', $persistenceCallback === false ? 0 : $persistenceCallback);
        $this->assertIsInt($persistenceCallback);
        $this->assertIsInt($businessTransaction);
        $this->assertLessThan($businessTransaction, $persistenceCallback);
        $taskLock = strpos($workerExecution, '->lockForUpdate()', $businessTransaction);
        $runLock = strpos($workerExecution, 'lockRunningJobForWorker(', $taskLock === false ? 0 : $taskLock);
        $articleWrite = strpos($workerExecution, '$article = Article::query()->create(', $runLock === false ? 0 : $runLock);
        $this->assertIsInt($taskLock);
        $this->assertIsInt($runLock);
        $this->assertIsInt($articleWrite);
        $this->assertLessThan($runLock, $taskLock);
        $this->assertLessThan($articleWrite, $runLock);

        $receiptGuard = $this->methodSource('assertReceiptCurrent', WorkerAiModelInvocationGateway::class);
        $this->assertStringContainsString('accessGuard->assertModelCurrent(', $receiptGuard);
        $this->assertStringContainsString('configurationDigest(', $receiptGuard);
        $this->assertStringContainsString('providerTimeoutSeconds()', $gatewayGeneration);
        $this->assertStringContainsString('PERSISTENCE_MARGIN_SECONDS', $gatewayGeneration);
    }

    #[Test]
    public function content_worker_direct_collaborators_match_the_reviewed_ai_boundary(): void
    {
        $constructor = (new ReflectionClass(WorkerExecutionService::class))->getConstructor();
        $this->assertNotNull($constructor);
        $dependencies = array_map(static function ($parameter): string {
            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType ? $type->getName() : '';
        }, $constructor->getParameters());

        $this->assertSame([
            'App\\Services\\GeoFlow\\KnowledgeRetrievalService',
            'App\\Services\\GeoFlow\\DistributionOrchestrator',
            'App\\Services\\GeoFlow\\ArticleRiskScanner',
            'App\\Services\\GeoFlow\\ArticleWorkflowTransitionService',
            'App\\Services\\GeoFlow\\ArticleContentPromptRenderer',
            WorkerAiModelInvocationGateway::class,
            'App\\Services\\GeoFlow\\ArticleCitationMarkerCleaner',
            'App\\Services\\GeoFlow\\TaskTitleReadinessService',
            'App\\Services\\GeoFlow\\ArticleAiQualityGate',
            'App\\Services\\GeoFlow\\ArticleAiQualityPolicyResolver',
            'App\\Services\\GeoFlow\\ArticleAiQualityInspectionService',
            'App\\Services\\GeoFlow\\AiExecutionAccessGuard',
            'App\\Support\\GeoFlow\\AiExecutionErrorSanitizer',
            'App\\Support\\GeoFlow\\AiModelFailoverDecider',
            'App\\Services\\GeoFlow\\JobQueueService',
        ], $dependencies);

        $modelBoundaryClasses = [
            'App\\Services\\GeoFlow\\ArticleWorkflowTransitionService',
            'App\\Services\\GeoFlow\\ArticleAiQualityGate',
            'App\\Services\\GeoFlow\\ArticleAiQualityPolicyResolver',
            'App\\Services\\GeoFlow\\ArticleAiQualityInspectionService',
            'App\\Services\\GeoFlow\\AiExecutionAccessGuard',
        ];
        foreach ($dependencies as $dependency) {
            $reflection = new ReflectionClass($dependency);
            $file = $reflection->getFileName();
            $this->assertIsString($file);
            $source = file_get_contents($file);
            $this->assertIsString($source);

            if ($dependency !== WorkerAiModelInvocationGateway::class) {
                $this->assertStringNotContainsString('ArticleContentGenerationService', $source, $dependency);
                $this->assertStringNotContainsString('MarkdownContentWriterAgent', $source, $dependency);
                $this->assertDoesNotMatchRegularExpression('/->(?:prompt|stream)\s*\(/', $source, $dependency);
            }
            if (! in_array($dependency, $modelBoundaryClasses, true)) {
                $this->assertSame([], $this->workerModelBoundaryViolations($source), $dependency);
            }
        }
    }

    #[Test]
    public function content_worker_uses_only_reviewed_methods_on_each_collaborator(): void
    {
        $workerSource = file_get_contents(app_path('Services/GeoFlow/WorkerExecutionService.php'));
        $this->assertIsString($workerSource);
        $this->assertDoesNotMatchRegularExpression(
            $this->serviceLocatorPattern(),
            $workerSource,
        );

        preg_match_all(
            '/\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $workerSource,
            $matches,
            PREG_SET_ORDER,
        );
        $actual = [];
        foreach ($matches as $match) {
            $actual[$match[1]][$match[2]] = true;
        }
        foreach ($actual as &$methods) {
            $methods = array_keys($methods);
            sort($methods);
        }
        unset($methods);
        ksort($actual);

        $expected = [
            'aiExecutionAccessGuard' => ['assertCurrent', 'assertModelCurrent', 'recordResolvedModel', 'resolveModelCandidates'],
            'aiExecutionErrorSanitizer' => ['sanitize'],
            'aiModelFailoverDecider' => ['isPermanentProviderFailure', 'shouldFailover'],
            'aiModelInvocationGateway' => ['assertReceiptCurrent', 'generate', 'maxTokens'],
            'articleAiQualityGate' => ['modelIdThatWouldBeDispatched'],
            'articleAiQualityInspectionService' => ['createOrReuse'],
            'articleAiQualityPolicyResolver' => ['fromTask', 'snapshot'],
            'articleCitationMarkerCleaner' => ['cleanContent'],
            'articleContentPromptRenderer' => ['renderForWorker'],
            'articleRiskScanner' => ['record'],
            'articleWorkflowTransitionService' => ['transition'],
            'distributionOrchestrator' => ['enqueueForArticle'],
            'jobQueueService' => ['completeJob', 'lockRunningJobForWorker'],
            'knowledgeRetrievalService' => ['retrieveContextBundleFromMany'],
            'taskTitleReadinessService' => ['inspectTask'],
        ];
        foreach ($expected as &$methods) {
            sort($methods);
        }
        unset($methods);
        ksort($expected);

        $this->assertSame($expected, $actual);
        $constructor = (new ReflectionClass(WorkerExecutionService::class))->getConstructor();
        $this->assertNotNull($constructor);
        $dependencyClasses = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType) {
                $dependencyClasses[$parameter->getName()] = $type->getName();
            }
        }
        foreach ($expected as $property => $methods) {
            $this->assertArrayHasKey($property, $dependencyClasses);
            foreach ($methods as $method) {
                $this->assertDoesNotMatchRegularExpression(
                    $this->serviceLocatorPattern(),
                    $this->methodSource($method, $dependencyClasses[$property]),
                    $property.'->'.$method,
                );
            }
        }
        $this->assertSame(
            ['modelIdThatWouldBeDispatched'],
            $actual['articleAiQualityGate'],
        );
    }

    #[Test]
    public function worker_database_writes_follow_task_then_run_then_article_lock_order(): void
    {
        $claim = $this->methodSource('claimPendingJobById', 'App\\Services\\GeoFlow\\JobQueueService');
        $taskLock = strpos($claim, 'lockTaskForClaim(');
        $runLock = strpos($claim, 'lockRunForClaim(');
        $this->assertIsInt($taskLock);
        $this->assertIsInt($runLock);
        $this->assertLessThan($runLock, $taskLock);

        $publish = $this->methodSource('publishDueDraftArticle');
        $transactionStart = strpos($publish, 'return DB::transaction(');
        $taskLock = strpos($publish, 'lockForUpdate()', $transactionStart);
        $runLock = strpos($publish, 'lockRunningJobForWorker(', $taskLock);
        $articleLock = strpos($publish, '$article = Article::query()', $runLock);
        foreach ([$transactionStart, $taskLock, $runLock, $articleLock] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($runLock, $taskLock);
        $this->assertLessThan($articleLock, $runLock);

        $completion = $this->methodSource('completeJob', 'App\\Services\\GeoFlow\\JobQueueService');
        $taskLock = strpos($completion, 'Task::query()');
        $runLock = strpos($completion, 'TaskRun::query()');
        $this->assertIsInt($taskLock);
        $this->assertIsInt($runLock);
        $this->assertLessThan($runLock, $taskLock);

        foreach (['recoverStaleJobs', 'recoverStalePendingJobs'] as $recoveryMethod) {
            $recovery = $this->methodSource($recoveryMethod, 'App\\Services\\GeoFlow\\JobQueueService');
            $taskLock = strpos($recovery, 'lockTaskForRecovery(');
            $runLock = strpos($recovery, '$run = TaskRun::query()', $taskLock === false ? 0 : $taskLock);
            $this->assertIsInt($taskLock, $recoveryMethod);
            $this->assertIsInt($runLock, $recoveryMethod);
            $this->assertLessThan($runLock, $taskLock, $recoveryMethod);
        }
    }

    #[Test]
    public function article_workflow_and_quality_gates_lock_task_before_article(): void
    {
        $workflow = $this->methodSource(
            'transition',
            'App\\Services\\GeoFlow\\ArticleWorkflowTransitionService',
        );
        $workflowTaskLock = strpos($workflow, 'lockTaskBeforeArticle(');
        $workflowArticleLock = strpos($workflow, 'lockArticleAfterTask(');
        $this->assertIsInt($workflowTaskLock);
        $this->assertIsInt($workflowArticleLock);
        $this->assertLessThan($workflowArticleLock, $workflowTaskLock);
        $this->assertLockHelperQueriesExpectedModel(
            'lockTaskBeforeArticle',
            'App\\Services\\GeoFlow\\ArticleWorkflowTransitionService',
            'Task::withTrashed()',
        );
        $this->assertLockHelperQueriesExpectedModel(
            'lockArticleAfterTask',
            'App\\Services\\GeoFlow\\ArticleWorkflowTransitionService',
            'Article::query()',
        );

        foreach (['modelIdThatWouldBeDispatched', 'checkLocked'] as $qualityMethod) {
            $quality = $this->methodSource(
                $qualityMethod,
                'App\\Services\\GeoFlow\\ArticleAiQualityGate',
            );
            $taskLock = strpos($quality, 'lockTaskBeforeArticle(');
            $articleLock = strpos($quality, 'lockArticleAfterTask(');
            $this->assertIsInt($taskLock, $qualityMethod);
            $this->assertIsInt($articleLock, $qualityMethod);
            $this->assertLessThan($articleLock, $taskLock, $qualityMethod);
        }
        $this->assertLockHelperQueriesExpectedModel(
            'lockTaskBeforeArticle',
            'App\\Services\\GeoFlow\\ArticleAiQualityGate',
            'Task::withTrashed()',
        );
        $this->assertLockHelperQueriesExpectedModel(
            'lockArticleAfterTask',
            'App\\Services\\GeoFlow\\ArticleAiQualityGate',
            'Article::query()',
        );
    }

    #[Test]
    #[DataProvider('forbiddenWorkerModelAccessSnippets')]
    public function worker_model_boundary_detector_rejects_forbidden_access_variants(string $source): void
    {
        $this->assertNotSame([], $this->workerModelBoundaryViolations($source));
    }

    #[Test]
    #[DataProvider('forbiddenWorkerServiceLocatorSnippets')]
    public function worker_service_locator_detector_rejects_forbidden_variants(string $source): void
    {
        $this->assertMatchesRegularExpression($this->serviceLocatorPattern(), $source);
    }

    /** @return array<string,array{string}> */
    public static function forbiddenWorkerServiceLocatorSnippets(): array
    {
        return [
            'app helper' => ['$service = app(Provider::class);'],
            'resolve helper' => ['$service = resolve(Provider::class);'],
            'make helper' => ['$service = make(Provider::class);'],
            'container helper' => ['$service = container(Provider::class);'],
            'container singleton' => ['$service = Container::getInstance()->make(Provider::class);'],
            'app facade make' => ['$service = App::make(Provider::class);'],
        ];
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

    /** @param class-string $class */
    private function assertLockHelperQueriesExpectedModel(string $method, string $class, string $query): void
    {
        $source = $this->methodSource($method, $class);
        $this->assertStringContainsString($query, $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
    }

    private function serviceLocatorPattern(): string
    {
        return '/(?<!->)(?<!::)(?<!function )\b(?:app|resolve|make|container)\s*\(|\b(?:App|Container)::(?:getInstance|make)\s*\(/';
    }

    /** @param class-string $class */
    private function methodSource(string $method, string $class = WorkerExecutionService::class): string
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
