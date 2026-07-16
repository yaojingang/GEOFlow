<?php

namespace Tests\Feature;

use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
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

    public function test_rejected_gate_commits_the_fallback_workflow_state_with_the_scan(): void
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
            $this->assertSame('draft', $article->status);
            $this->assertSame('pending', $article->review_status);
            $this->assertNull($article->published_at);
            $this->assertSame(1, $article->riskScans()->count());
            $this->assertSame('warning', $article->latestRiskScan->status);
            $this->assertSame('service_publish', $article->latestRiskScan->trigger);
        }
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
