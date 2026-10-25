<?php

namespace Tests\Feature;

use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArticleWorkflowTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_clean_gate_and_workflow_state_update_complete_together(): void
    {
        SensitiveWord::query()->create(['word' => 'prohibited']);
        $article = $this->createArticle(['review_status' => 'approved']);
        $workflowState = ArticleWorkflow::normalizeState('published', 'approved');

        $transitioned = app(ArticleWorkflowTransitionService::class)->transition(
            $article,
            $workflowState,
            'service_publish',
        );

        $this->assertSame('published', $transitioned->status);
        $this->assertSame('approved', $transitioned->review_status);
        $this->assertNotNull($transitioned->published_at);
        $this->assertSame('clean', $transitioned->latestRiskScan->status);
        $this->assertSame('service_publish', $transitioned->latestRiskScan->trigger);
    }

    public function test_rejected_gate_records_scan_without_unpublishing_or_revoking_human_approval(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me.',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $workflowState = ArticleWorkflow::normalizeState('published', 'approved');
        $fallbackWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');

        try {
            app(ArticleWorkflowTransitionService::class)->transition(
                $article,
                $workflowState,
                'service_publish',
                null,
                null,
                true,
                $fallbackWorkflowState,
            );
            $this->fail('Expected the warning gate to reject the transition.');
        } catch (ArticleRiskGateException) {
            $article->refresh();
            $this->assertSame('published', $article->status);
            $this->assertSame('approved', $article->review_status);
            $this->assertNotNull($article->published_at);
            $this->assertSame(1, $article->riskScans()->count());
            $this->assertSame('warning', $article->latestRiskScan->status);
            $this->assertSame('service_publish', $article->latestRiskScan->trigger);
        }
    }

    public function test_task_backed_article_transition_preserves_the_locked_task_relation(): void
    {
        $task = Task::query()->create([
            'name' => 'Task backed workflow transition',
            'status' => 'active',
            'schedule_enabled' => 1,
            'ai_quality_enabled' => false,
        ]);
        $article = $this->createArticle([
            'task_id' => $task->id,
            'review_status' => 'approved',
        ]);
        $guardObservedTask = false;

        $transitioned = app(ArticleWorkflowTransitionService::class)->transition(
            $article,
            ArticleWorkflow::normalizeState('published', 'approved'),
            'service_task_publish',
            lockedGuard: function (Article $lockedArticle) use ($task, &$guardObservedTask): void {
                $guardObservedTask = $lockedArticle->relationLoaded('task')
                    && (int) $lockedArticle->task?->id === (int) $task->id;
            },
        );

        $this->assertTrue($guardObservedTask);
        $this->assertSame('published', $transitioned->status);
        $this->assertSame((int) $task->id, (int) $transitioned->task_id);
    }

    public function test_distribution_only_task_forces_a_publish_transition_to_private(): void
    {
        $task = Task::query()->create([
            'name' => 'Distribution only workflow transition',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'ai_quality_enabled' => false,
        ]);
        $article = $this->createArticle([
            'task_id' => $task->id,
            'review_status' => 'approved',
        ]);
        $this->assertSame('distribution_only', $task->fresh()->publish_scope);

        $transitioned = app(ArticleWorkflowTransitionService::class)->transition(
            $article,
            ArticleWorkflow::normalizeState('published', 'approved'),
            'service_task_publish',
        );

        $this->assertSame('private', $transitioned->status);
        $this->assertSame('approved', $transitioned->review_status);
        $this->assertNull($transitioned->published_at);
    }

    public function test_ai_quality_rejection_does_not_preserve_invalid_distribution_only_publication(): void
    {
        $task = Task::query()->create([
            'name' => 'Distribution only quality rejection',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'ai_quality_enabled' => false,
        ]);
        $article = $this->createArticle([
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $qualityGate = \Mockery::mock(ArticlePublicationQualityGate::class);
        $qualityGate->shouldReceive('check')
            ->once()
            ->andThrow(new ArticleAiQualityGateException('article_ai_quality_pending', 'Quality pending.'));
        $service = app()->makeWith(ArticleWorkflowTransitionService::class, ['publicationQualityGate' => $qualityGate]);

        try {
            $service->transition(
                $article,
                ArticleWorkflow::normalizeState('published', 'approved'),
                'service_task_publish',
            );
            $this->fail('Expected the AI quality gate to reject the transition.');
        } catch (ArticleAiQualityGateException) {
            $article->refresh();
            $this->assertSame('private', $article->status);
            $this->assertSame('approved', $article->review_status);
            $this->assertNull($article->published_at);
        }
    }

    public function test_repeated_manual_publish_preserves_the_published_state_and_only_retries_distribution_enqueue(): void
    {
        $article = $this->createArticle([
            'status' => 'published', 'review_status' => 'approved', 'publication_intent' => 'none',
            'published_at' => now()->subDay(), 'workflow_version' => 7,
        ]);
        $before = $article->getAttributes();
        $gate = \Mockery::mock(ArticlePublicationQualityGate::class);
        $gate->shouldNotReceive('check');
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->twice()
            ->with(\Mockery::on(fn (int $candidate): bool => $candidate === $article->id), 'publish', [], true,
                \Mockery::on(fn (array $fence): bool => $fence['workflow_version'] === 7 && $fence['origin'] === 'manual'))
            ->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        $service = app()->makeWith(ArticleWorkflowTransitionService::class, ['publicationQualityGate' => $gate]);

        $service->humanAction($article, 'publish', expectedVersion: 7);
        $service->humanAction($article->fresh(), 'publish', expectedVersion: 7);

        $article->refresh();
        foreach (['status', 'review_status', 'published_at', 'publication_intent', 'workflow_version'] as $field) {
            $this->assertSame($before[$field], $article->getRawOriginal($field), $field);
        }
        $this->assertSame(0, $article->reviews()->count());
    }

    /** @param array<string, mixed> $attributes */
    private function createArticle(array $attributes = []): Article
    {
        $category = Category::query()->create([
            'name' => 'Workflow transition',
            'slug' => 'workflow-transition-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Workflow Author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create(array_merge([
            'title' => 'Workflow article',
            'slug' => 'workflow-article-'.uniqid(),
            'excerpt' => 'Workflow excerpt',
            'content' => 'Workflow article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $attributes));
    }
}
