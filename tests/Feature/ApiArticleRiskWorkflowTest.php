<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ApiArticleRiskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Author $author;

    private Category $category;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake([ReconcileArticleAiQualityJob::class]);
        if (! Schema::hasTable('article_reviews')) {
            Schema::create('article_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('admin_id')->constrained('admins');
                $table->string('review_status', 20);
                $table->text('review_note')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
        $this->admin = Admin::query()->create([
            'username' => 'api-article-risk-admin',
            'password' => 'secret-123',
            'email' => 'api-article-risk@example.com',
            'display_name' => 'API Article Risk Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->author = Author::query()->create([
            'name' => 'API Risk Author',
            'email' => 'api-risk-author@example.com',
        ]);
        $this->category = Category::query()->create([
            'name' => 'API Risk Category',
            'slug' => 'api-risk-category',
        ]);
        $this->token = $this->admin
            ->createToken('api-article-risk', ['articles:read', 'articles:write', 'articles:publish'])
            ->plainTextToken;
    }

    public function test_draft_create_records_an_api_save_scan_for_the_audit_admin(): void
    {
        $response = $this->postArticle($this->articlePayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'pending');

        $article = Article::query()->findOrFail((int) $response->json('data.id'));
        $scan = $article->latestRiskScan()->firstOrFail();

        $this->assertSame('api_save', $scan->trigger);
        $this->assertSame($this->admin->id, $scan->admin_id);
        $this->assertSame('clean', $scan->status);
    }

    #[TestWith([null, false])]
    #[TestWith(['pending', true])]
    #[TestWith(['rejected', false])]
    public function test_requested_publish_without_human_approval_returns_409_and_rolls_back_creation(?string $reviewStatus, bool $idempotent): void
    {
        app()->setLocale('zh_CN');
        $payload = $this->articlePayload(['status' => 'published']);
        if ($reviewStatus === null) {
            unset($payload['review_status']);
        } else {
            $payload['review_status'] = $reviewStatus;
        }
        if ($idempotent) {
            $this->withHeader('X-Idempotency-Key', 'unreviewed-create-publish');
        }

        $this->postArticle($payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'article_not_publishable')
            ->assertJsonPath('error.message', '当前审核结果不允许发布，请先完成审核。');

        $this->assertDatabaseEmpty('articles');
        $this->assertDatabaseEmpty('article_reviews');
        Queue::assertNothingPushed();
    }

    public function test_write_only_token_cannot_publish_or_override_during_article_creation(): void
    {
        $writeOnlyToken = $this->admin
            ->createToken('api-article-write-only', ['articles:write'])
            ->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$writeOnlyToken)
            ->postJson('/api/v1/articles', $this->articlePayload([
                'status' => 'published',
                'review_status' => 'approved',
                'risk_override_reason' => 'Attempted override.',
            ]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'articles:publish');

        $this->assertDatabaseMissing('articles', ['title' => 'API risk article']);
    }

    public function test_article_create_rejects_content_above_the_scan_limit(): void
    {
        $this->postArticle($this->articlePayload([
            'content' => str_repeat('x', ArticleRiskScanner::MAX_CONTENT_CHARACTERS + 1),
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.content', '文章内容超过扫描长度上限');

        $this->assertDatabaseMissing('articles', ['title' => 'API risk article']);
    }

    public function test_create_rolls_back_the_article_when_the_api_save_scan_fails(): void
    {
        $scanner = \Mockery::mock(ArticleRiskScanner::class);
        $scanner->shouldReceive('record')->once()->andThrow(new \RuntimeException('scan insert failed'));
        $this->app->instance(ArticleRiskScanner::class, $scanner);

        $this->postArticle($this->articlePayload())
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error');

        $this->assertSame(0, Article::query()->count());
    }

    public function test_risky_requested_publish_without_reason_returns_stable_409_and_saves_a_draft(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
            'status' => 'published',
            'review_status' => 'approved',
        ]));

        $article = Article::query()->where('title', 'API risk article')->firstOrFail();

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'article_risk_blocked')
            ->assertJsonPath('error.details.article_id', $article->id)
            ->assertJsonPath('error.details.risk_status', 'warning')
            ->assertJsonPath('error.details.match_count', 1)
            ->assertJsonCount(1, 'error.details.matches');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('api_save', $article->latestRiskScan->trigger);
    }

    public function test_risky_create_replays_the_cached_409_without_creating_another_article(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $payload = $this->articlePayload([
            'content' => 'Please review me before publishing.',
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        $first = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'X-Idempotency-Key' => 'risky-create-retry',
        ])->postJson('/api/v1/articles', $payload);
        $second = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'X-Idempotency-Key' => 'risky-create-retry',
        ])->postJson('/api/v1/articles', $payload);

        $first->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked');
        $second->assertStatus(409)
            ->assertExactJson($first->json());
        $this->assertSame(1, Article::query()->count());
        $this->assertSame(
            $first->json('error.details.article_id'),
            $second->json('error.details.article_id')
        );
    }

    public function test_warning_approved_create_with_reason_publishes_and_records_the_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
            'status' => 'published',
            'review_status' => 'approved',
            'risk_override_reason' => 'Reviewed by the API editor.',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'approved');

        $article = Article::query()->findOrFail((int) $response->json('data.id'));
        $scan = $article->latestRiskScan()->firstOrFail();

        $this->assertNotNull($article->published_at);
        $this->assertTrue($scan->is_overridden);
        $this->assertSame('Reviewed by the API editor.', $scan->override_reason);
        $this->assertSame($this->admin->id, $scan->overridden_by_admin_id);
    }

    public function test_distribution_only_create_stays_private_and_enters_distribution(): void
    {
        $task = $this->createDistributionOnlyTask();
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')
            ->once()
            ->with(\Mockery::on(fn (mixed $candidate): bool => is_int($candidate)
                && (int) Article::query()->find($candidate)?->task_id === (int) $task->id), 'publish', [], true, \Mockery::on(fn (array $fence): bool => (int) $fence['task_id'] === (int) $task->id && $fence['origin'] === 'manual' && $fence['workflow_version'] > 0))
            ->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $response = $this->postArticle($this->articlePayload([
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'private')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_distribution_only_explicit_private_create_does_not_enter_distribution(): void
    {
        $task = $this->createDistributionOnlyTask();
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldNotReceive('enqueueForArticle');
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->postArticle($this->articlePayload([
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'private')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_legacy_auto_approved_create_records_human_approval_without_overriding_risk(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
            'review_status' => 'auto_approved',
            'risk_override_reason' => 'Automatic approval must ignore this.',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.compatibility_notice', 'auto_approved 已作为人工审核通过处理；发布安排保持不变。');

        $article = Article::query()->where('title', 'API risk article')->firstOrFail();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    #[TestWith(['content'])]
    #[TestWith(['title'])]
    #[TestWith(['excerpt'])]
    #[TestWith(['keywords'])]
    #[TestWith(['meta_description'])]
    #[TestWith(['author_id'])]
    #[TestWith(['category_id'])]
    public function test_write_only_token_cannot_change_published_article_fields(string $field): void
    {
        $task = Task::query()->create(['name' => 'Published scope task', 'need_review' => false, 'ai_quality_enabled' => false]);
        $article = $this->createArticle([
            'task_id' => $task->id, 'status' => 'published', 'review_status' => 'auto_approved', 'published_at' => now(),
        ]);
        $before = $article->fresh()->getAttributes();
        $value = match ($field) {
            'author_id' => Author::query()->create(['name' => 'Replacement author'])->id,
            'category_id' => Category::query()->create(['name' => 'Replacement category', 'slug' => 'replacement-category'])->id,
            default => 'Changed public information.',
        };
        $token = $this->admin->createToken('write-only-published-edit', ['articles:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", [$field => $value])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'articles:publish');

        $this->assertSame($before, $article->fresh()->getAttributes());
        $this->assertSame(0, $article->riskScans()->count());
        $this->assertSame(0, $article->reviews()->count());
    }

    public function test_write_only_token_can_send_unchanged_published_content(): void
    {
        $article = $this->createArticle(['status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        $before = $article->fresh()->getAttributes();
        $token = $this->admin->createToken('write-only-published-noop', ['articles:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => $article->content])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame($before, $article->fresh()->getAttributes());
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_write_only_token_can_edit_a_draft(): void
    {
        $article = $this->createArticle();
        $token = $this->admin->createToken('write-only-draft-edit', ['articles:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Updated draft content.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.content', 'Updated draft content.');

        $this->assertSame('Updated draft content.', $article->fresh()->content);
    }

    public function test_publish_scope_can_update_published_content_after_current_gates_pass(): void
    {
        $task = Task::query()->create(['name' => 'Allowed published edit', 'need_review' => false, 'ai_quality_enabled' => false]);
        $article = $this->createArticle([
            'task_id' => $task->id, 'status' => 'published', 'review_status' => 'auto_approved', 'published_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Updated verified content.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.content', 'Updated verified content.');

        $this->assertSame('Updated verified content.', $article->fresh()->content);
        $this->assertSame('clean', $article->fresh()->latestRiskScan->status);
    }

    public function test_publish_scope_does_not_bypass_manual_review_for_changed_published_content(): void
    {
        $task = Task::query()->create(['name' => 'Reviewed published edit', 'need_review' => true, 'ai_quality_enabled' => false]);
        $article = $this->createArticle([
            'task_id' => $task->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);
        $before = $article->fresh()->getAttributes();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Updated content still needing human review.'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'article_review_required');

        $this->assertSame($before, $article->fresh()->getAttributes());
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_patch_rejects_risky_new_content_and_preserves_the_existing_published_article(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'content' => 'Updated content that says review me.',
            ]);

        $response->assertConflict()->assertJsonPath('error.code', 'article_risk_blocked');

        $article->refresh();
        $this->assertSame('Existing safe content.', $article->content);
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNotNull($article->published_at);
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_update_rolls_back_content_and_workflow_when_the_api_save_scan_fails(): void
    {
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $scanner = \Mockery::mock(ArticleRiskScanner::class);
        $scanner->shouldReceive('record')->once()->andThrow(new \RuntimeException('scan insert failed'));
        $this->app->instance(ArticleRiskScanner::class, $scanner);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'content' => 'Changed content that must roll back.',
            ])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error');

        $article->refresh();
        $this->assertSame('Existing safe content.', $article->content);
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNotNull($article->published_at);
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_patch_with_the_same_risk_field_values_preserves_workflow_without_rescanning(): void
    {
        $publishedAt = now()->startOfSecond();
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => $publishedAt,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'approved');

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertTrue($publishedAt->equalTo($article->published_at));
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_approval_records_review_and_publish_explicitly_applies_the_risk_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $create = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
        ]))->assertCreated();
        $articleId = (int) $create->json('data.id');

        $review = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$articleId}/review", [
                'review_status' => 'approved',
                'review_note' => 'Editorial review completed.',
                'risk_override_reason' => 'A human editor confirmed this warning.',
            ]);

        $review->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'approved');

        $article = Article::query()->findOrFail($articleId);
        $this->assertFalse($article->latestRiskScan->is_overridden);
        $this->assertNull($article->latestRiskScan->override_reason);
        $this->assertDatabaseHas('article_reviews', [
            'article_id' => $articleId,
            'admin_id' => $this->admin->id,
            'review_status' => 'approved',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$articleId}/publish", [
                'risk_override_reason' => 'A human editor confirmed this warning.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'approved');
        $this->assertTrue($article->fresh()->latestRiskScan->is_overridden);
        $this->assertSame('A human editor confirmed this warning.', $article->fresh()->latestRiskScan->override_reason);
    }

    public function test_review_note_alone_does_not_override_a_warning(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
        ]);

        $scan = app(ArticleRiskScanner::class)->record($article, 'fixture');
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'Ordinary editorial note.',
            ]);

        $response->assertOk()->assertJsonPath('data.review_status', 'approved')->assertJsonPath('data.status', 'draft');
        $this->assertTrue($article->fresh()->latestRiskScan->is($scan));
        $this->assertFalse($article->refresh()->latestRiskScan->is_overridden);
    }

    public function test_approved_review_rolls_back_transition_and_override_when_audit_insert_fails(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $create = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
        ]))->assertCreated();
        $article = Article::query()->findOrFail((int) $create->json('data.id'));
        $scan = $article->latestRiskScan()->firstOrFail();
        Schema::drop('article_reviews');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'This override must roll back.',
                'risk_override_reason' => 'Explicitly accept this warning.',
            ])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error');

        $article->refresh();
        $scan->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertFalse($scan->is_overridden);
        $this->assertNull($scan->override_reason);
    }

    public function test_manual_review_requirement_cannot_be_satisfied_by_auto_approval_or_risk_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
            'review_status' => 'auto_approved',
        ]);
        $confirmedScan = app(ArticleRiskGate::class)->check(
            $article,
            'manual_review',
            $this->admin->id,
            'Previously confirmed by a human.',
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'article_not_publishable');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('auto_approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertTrue($article->latestRiskScan->is($confirmedScan));
        $this->assertTrue($article->latestRiskScan->is_overridden);
    }

    public function test_risky_publish_replays_cached_409_without_revoking_human_approval(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
            'review_status' => 'approved',
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'X-Idempotency-Key' => 'risky-publish-retry',
        ];

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/publish");
        $second = $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/publish");

        $first->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked');
        $second->assertStatus(409)
            ->assertExactJson($first->json());
    }

    public function test_idempotent_quality_pending_response_commits_the_check_and_replays_it(): void
    {
        Queue::fake();
        $model = AiModel::query()->create([
            'name' => 'API quality idempotency model',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'api-quality-idempotency-model',
            'api_url' => 'https://example.test',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'API quality idempotency knowledge',
            'content' => 'Existing safe content.',
        ]);
        $task = Task::query()->create([
            'name' => 'API quality idempotency task',
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
            'need_review' => false,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $article = $this->createArticle([
            'task_id' => $task->id,
            'review_status' => 'approved',
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'X-Idempotency-Key' => 'quality-pending-publish-retry',
        ];

        $first = $this->withHeaders($headers)->postJson("/api/v1/articles/{$article->id}/publish");
        $second = $this->withHeaders($headers)->postJson("/api/v1/articles/{$article->id}/publish");

        $first->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.publication_intent', 'immediate');
        $second->assertOk()->assertExactJson($first->json());
        $this->assertNotNull(data_get(ArticleAiQualityCheck::query()->where('article_id', $article->id)->firstOrFail()->execution_meta, 'workflow_fence'));
        $this->assertSame(1, ArticleAiQualityCheck::query()->where('article_id', $article->id)->count());
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_publish_records_a_fresh_scan_for_the_audit_admin(): void
    {
        $article = $this->createArticle(['review_status' => 'approved']);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $scan = $article->refresh()->latestRiskScan()->firstOrFail();
        $this->assertSame('manual_publish', $scan->trigger);
        $this->assertSame($this->admin->id, $scan->admin_id);
    }

    public function test_distribution_only_publish_stays_private_and_enters_distribution(): void
    {
        $task = $this->createDistributionOnlyTask();
        $article = $this->createArticle([
            'task_id' => $task->id,
            'review_status' => 'approved',
        ]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')
            ->once()
            ->with(\Mockery::on(fn (mixed $candidate): bool => is_int($candidate)
                && (int) Article::query()->find($candidate)?->task_id === (int) $task->id), 'publish', [], true, \Mockery::on(fn (array $fence): bool => (int) $fence['task_id'] === (int) $task->id && $fence['origin'] === 'manual' && $fence['workflow_version'] > 0))
            ->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'private')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_distribution_only_review_records_approval_without_publishing(): void
    {
        $task = $this->createDistributionOnlyTask(['need_review' => 0]);
        $article = $this->createArticle(['task_id' => $task->id]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldNotReceive('enqueueForArticle');
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'Ready for channel publication.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_distribution_only_approved_review_keeps_explicit_private_article_out_of_distribution(): void
    {
        $task = $this->createDistributionOnlyTask(['need_review' => 1]);
        $article = $this->createArticle([
            'task_id' => $task->id,
            'status' => 'private',
        ]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldNotReceive('enqueueForArticle');
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'Approved for storage only.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'private')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_pending_article_cannot_be_published(): void
    {
        $article = $this->createArticle(['review_status' => 'pending']);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_not_publishable');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_publish_rechecks_approval_after_the_article_is_locked(): void
    {
        $article = $this->createArticle(['review_status' => 'approved']);
        $realService = app(ArticleWorkflowTransitionService::class);
        $transitionService = \Mockery::mock(ArticleWorkflowTransitionService::class);
        $transitionService->shouldReceive('humanAction')->once()->andReturnUsing(
            function (Article $candidate, string $action, ?int $adminId, ?string $note, ?int $expectedVersion) use ($realService): Article {
                Article::query()->whereKey($candidate->id)->update(['review_status' => 'pending']);

                return $realService->humanAction($candidate, $action, $adminId, $note, $expectedVersion);
            },
        );
        $this->app->instance(ArticleWorkflowTransitionService::class, $transitionService);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_not_publishable');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
    }

    public function test_blocked_content_cannot_be_overridden(): void
    {
        SensitiveWord::query()->create([
            'word' => 'prohibited',
            'severity' => 'blocked',
        ]);

        $response = $this->postArticle($this->articlePayload([
            'content' => 'This content is prohibited.',
            'status' => 'published',
            'review_status' => 'approved',
            'risk_override_reason' => 'Accept this risk.',
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked')
            ->assertJsonPath('error.details.risk_status', 'blocked');

        $article = Article::query()->where('title', 'API risk article')->firstOrFail();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_legacy_auto_approved_review_records_human_approval_and_preserves_prior_risk_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
        ]);
        app(ArticleRiskGate::class)->check(
            $article,
            'manual_review',
            $this->admin->id,
            'Previously confirmed by a human.',
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'auto_approved',
                'review_note' => 'Automatic approval cannot use this.',
            ]);

        $response->assertOk()->assertJsonPath('data.review_status', 'approved')->assertJsonPath('data.status', 'draft');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertDatabaseHas('article_reviews', ['article_id' => $article->id, 'review_status' => 'approved', 'admin_id' => $this->admin->id]);
        $this->assertTrue($article->latestRiskScan->is_overridden);
    }

    public function test_pending_review_remains_draft_and_records_the_audit_row(): void
    {
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'pending',
                'review_note' => 'Needs another editorial pass.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('article_reviews', [
            'article_id' => $article->id,
            'admin_id' => $this->admin->id,
            'review_status' => 'pending',
            'review_note' => 'Needs another editorial pass.',
        ]);
    }

    public function test_rejected_review_remains_draft_and_records_the_audit_row(): void
    {
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'rejected',
                'review_note' => 'Claims require supporting sources.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('article_reviews', [
            'article_id' => $article->id,
            'admin_id' => $this->admin->id,
            'review_status' => 'rejected',
            'review_note' => 'Claims require supporting sources.',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function articlePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'API risk article',
            'content' => 'Safe API article content.',
            'excerpt' => 'Safe excerpt.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function postArticle(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/articles', $payload);
    }

    /** @param array<string, mixed> $overrides */
    private function createArticle(array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'title' => 'Existing API risk article',
            'slug' => 'existing-api-risk-article-'.uniqid(),
            'content' => 'Existing safe content.',
            'excerpt' => 'Existing safe excerpt.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createDistributionOnlyTask(array $overrides = []): Task
    {
        return Task::query()->create(array_merge([
            'name' => 'API distribution only task '.uniqid(),
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'ai_quality_enabled' => false,
        ], $overrides));
    }
}
