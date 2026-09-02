<?php

namespace Tests\Unit;

use App\Console\Commands\EvaluateArticleAiQualityCommand;
use App\Http\Controllers\Admin\ArticleEditorAssistantController;
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
        $lock = strpos($source, 'acquireForInvocation(');
        $guard = strpos($source, 'assertModelCurrent(', $lock === false ? 0 : $lock);
        $stream = strpos($source, 'generationService->stream(', $guard === false ? 0 : $guard);
        $iteration = strpos($source, 'foreach ($stream as $event)', $stream === false ? 0 : $stream);
        $replacement = strpos($source, "'article_content_replacement'", $iteration === false ? 0 : $iteration);
        $done = strpos($source, 'data: [DONE]', $replacement === false ? 0 : $replacement);
        $release = strpos($source, 'invocationLocks->release(', $done === false ? 0 : $done);

        foreach ([$lock, $guard, $stream, $iteration, $replacement, $done, $release] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($guard, $lock);
        $this->assertLessThan($stream, $guard);
        $this->assertLessThan($iteration, $stream);
        $this->assertLessThan($replacement, $iteration);
        $this->assertLessThan($done, $replacement);
        $this->assertLessThan($release, $done);
        $this->assertStringContainsString('finally', $source);
        $this->assertGreaterThanOrEqual(5, substr_count($source, 'assertModelCurrent('));
    }

    #[Test]
    public function live_quality_reviews_are_guarded_and_locked_before_report_persistence(): void
    {
        $review = $this->methodSource(EvaluateArticleAiQualityCommand::class, 'reviewLive');
        $lock = strpos($review, 'acquireForInvocation(');
        $firstGuard = strpos($review, 'assertModelCurrent(', $lock === false ? 0 : $lock);
        $provider = strpos($review, 'reviewer->', $firstGuard === false ? 0 : $firstGuard);
        $secondGuard = strpos($review, 'assertModelCurrent(', $provider === false ? 0 : $provider);
        $release = strpos($review, 'invocationLocks->release(', $secondGuard === false ? 0 : $secondGuard);

        foreach ([$lock, $firstGuard, $provider, $secondGuard, $release] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($firstGuard, $lock);
        $this->assertLessThan($provider, $firstGuard);
        $this->assertLessThan($secondGuard, $provider);
        $this->assertLessThan($release, $secondGuard);
        $this->assertStringContainsString('finally', $review);

        foreach (['handle', 'handleArticleComparison'] as $method) {
            $entry = $this->methodSource(EvaluateArticleAiQualityCommand::class, $method);
            $finalGuard = strrpos($entry, 'assertModelCurrent(');
            $firstReportWrite = strrpos($entry, 'File::put(');
            $this->assertIsInt($finalGuard, $method);
            $this->assertIsInt($firstReportWrite, $method);
            $this->assertLessThan($firstReportWrite, $finalGuard, $method);
        }
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
