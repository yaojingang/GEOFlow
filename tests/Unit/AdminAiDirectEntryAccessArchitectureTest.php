<?php

namespace Tests\Unit;

use App\Console\Commands\EvaluateArticleAiQualityCommand;
use App\Http\Controllers\Admin\ArticleEditorAssistantController;
use App\Services\GeoFlow\DirectAdminAiModelInvocationGateway;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class AdminAiDirectEntryAccessArchitectureTest extends TestCase
{
    #[Test]
    public function direct_admin_ai_entries_cannot_query_the_global_model_pool(): void
    {
        foreach ([
            ArticleEditorAssistantController::class,
            EvaluateArticleAiQualityCommand::class,
            DirectAdminAiModelInvocationGateway::class,
        ] as $class) {
            $source = $this->classSource($class);

            $this->assertDoesNotMatchRegularExpression('/\bAiModel::(?:query|where|find|findOrFail)\s*\(/', $source, $class);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:\bDB::table\s*\(\s*[\'\"]ai_models|\bDB::query\s*\(\s*\)\s*->from\s*\(\s*[\'\"]ai_models|\$[A-Za-z_][A-Za-z0-9_]*\s*->from\s*\(\s*[\'\"]ai_models)/',
                $source,
                $class,
            );
            $this->assertDoesNotMatchRegularExpression('/->(?:aiModel|qualityModel)\b/', $source, $class);
            $this->assertStringContainsString('DirectAdminAiExecutionGuard', $source, $class);
        }
    }

    #[Test]
    public function article_editor_consumes_the_lazy_stream_inside_the_guarded_invocation_lock(): void
    {
        $source = $this->methodSource(ArticleEditorAssistantController::class, 'generate');
        $acquire = strpos($source, 'invocationGateway->acquire(');
        $stream = strpos($source, 'generationService->deferredStreamWithReservation(', $acquire === false ? 0 : $acquire);
        $iteration = strpos($source, 'foreach ($stream as $event)', $stream === false ? 0 : $stream);
        $replacement = strpos($source, "'article_content_replacement'", $iteration === false ? 0 : $iteration);
        $success = strpos($source, 'streamSession->complete(', $replacement === false ? 0 : $replacement);
        $done = strpos($source, 'data: [DONE]', $success === false ? 0 : $success);
        $release = strpos($source, 'invocation?->close(', $done === false ? 0 : $done);

        foreach ([$acquire, $stream, $iteration, $replacement, $success, $done, $release] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($stream, $acquire);
        $this->assertLessThan($iteration, $stream);
        $this->assertLessThan($replacement, $iteration);
        $this->assertLessThan($success, $replacement);
        $this->assertLessThan($done, $success);
        $this->assertLessThan($release, $done);
        $this->assertStringContainsString('finally', $source);
        $this->assertGreaterThanOrEqual(7, substr_count($source, 'assertModelCurrent('));
    }

    #[Test]
    public function live_quality_reviews_are_guarded_and_locked_before_report_persistence(): void
    {
        $review = $this->methodSource(EvaluateArticleAiQualityCommand::class, 'reviewLive');
        $acquire = strpos($review, 'invocationGateway->acquire(');
        $readiness = strpos($review, 'assertQualityCandidateReady(', $acquire === false ? 0 : $acquire);
        $provider = strpos($review, 'reviewer->', $readiness === false ? 0 : $readiness);
        $secondGuard = strpos($review, 'assertModelCurrent(', $provider === false ? 0 : $provider);
        $success = strpos($review, 'invocation->recordSuccess(', $secondGuard === false ? 0 : $secondGuard);
        $release = strpos($review, 'invocation->close(', $success === false ? 0 : $success);

        foreach ([$acquire, $readiness, $provider, $secondGuard, $success, $release] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($readiness, $acquire);
        $this->assertLessThan($provider, $readiness);
        $this->assertLessThan($secondGuard, $provider);
        $this->assertLessThan($success, $secondGuard);
        $this->assertLessThan($release, $success);
        $this->assertStringContainsString('finally', $review);

        foreach (['handle', 'handleArticleComparison'] as $method) {
            $entry = $this->methodSource(EvaluateArticleAiQualityCommand::class, $method);
            $this->assertStringContainsString('publishLiveReport(', $entry, $method);
        }

        $publisher = $this->methodSource(EvaluateArticleAiQualityCommand::class, 'publishLiveReport');
        $firstGuard = strpos($publisher, 'assertModelCurrent(');
        $temporaryJson = strpos($publisher, 'File::put($temporaryJson', $firstGuard === false ? 0 : $firstGuard);
        $temporaryMarkdown = strpos($publisher, 'File::put($temporaryMarkdown', $temporaryJson === false ? 0 : $temporaryJson);
        $jsonPublish = strpos($publisher, 'File::replace($finalJson', $temporaryMarkdown === false ? 0 : $temporaryMarkdown);
        $markdownPublish = strpos($publisher, 'File::replace($finalMarkdown', $jsonPublish === false ? 0 : $jsonPublish);
        $finalGuard = strpos($publisher, 'assertModelCurrent(', $markdownPublish === false ? 0 : $markdownPublish);
        $cleanup = strpos($publisher, 'File::deleteDirectory($temporaryDirectory)', $finalGuard === false ? 0 : $finalGuard);

        foreach ([$firstGuard, $temporaryJson, $temporaryMarkdown, $jsonPublish, $markdownPublish, $finalGuard, $cleanup] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($temporaryJson, $firstGuard);
        $this->assertLessThan($temporaryMarkdown, $temporaryJson);
        $this->assertLessThan($jsonPublish, $temporaryMarkdown);
        $this->assertLessThan($markdownPublish, $jsonPublish);
        $this->assertLessThan($finalGuard, $markdownPublish);
        $this->assertLessThan($cleanup, $finalGuard);
        $this->assertStringContainsString('$context->requestId', $publisher);
        $this->assertStringContainsString('finally', $publisher);

        $failure = $this->methodSource(EvaluateArticleAiQualityCommand::class, 'failLive');
        $this->assertStringNotContainsString('File::delete(', $failure);
    }

    #[Test]
    public function direct_admin_invocations_recheck_and_reserve_each_candidate_inside_the_model_lock(): void
    {
        $source = $this->methodSource(DirectAdminAiModelInvocationGateway::class, 'acquire');
        $lock = strpos($source, 'acquireForInvocation(');
        $guard = strpos($source, 'assertModelCurrent(', $lock === false ? 0 : $lock);
        $runtime = strpos($source, 'runtimeGate->assertExecutable(', $guard === false ? 0 : $guard);
        $readiness = strpos($source, 'candidateReadiness?->__invoke(', $runtime === false ? 0 : $runtime);
        $reservation = strpos($source, 'usageQuota->reserveModel(', $readiness === false ? 0 : $readiness);
        $return = strpos($source, 'new DirectAdminAiModelInvocation(', $reservation === false ? 0 : $reservation);
        $release = strpos($source, 'invocationLocks->release(', $lock === false ? 0 : $lock);

        foreach ([$lock, $guard, $runtime, $readiness, $reservation, $return, $release] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($guard, $lock);
        $this->assertLessThan($runtime, $guard);
        $this->assertLessThan($readiness, $runtime);
        $this->assertLessThan($reservation, $readiness);
        $this->assertLessThan($return, $reservation);
        $this->assertStringContainsString('$explicit', $source);
        $this->assertStringContainsString('foreach (', $source);
    }

    private function classSource(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        $this->assertIsString($file);

        return (string) file_get_contents($file);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $this->assertIsString($file);
        $lines = file($file);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
