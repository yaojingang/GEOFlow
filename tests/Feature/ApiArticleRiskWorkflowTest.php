<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame('pending', $article->review_status);
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

    public function test_warning_auto_approved_create_returns_409_as_an_unoverridden_draft(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->postArticle($this->articlePayload([
            'content' => 'Please review me before publishing.',
            'review_status' => 'auto_approved',
            'risk_override_reason' => 'Automatic approval must ignore this.',
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked')
            ->assertJsonPath('error.details.risk_status', 'warning');

        $article = Article::query()->where('title', 'API risk article')->firstOrFail();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_patch_risk_content_on_a_published_article_unpublishes_and_scans_it(): void
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

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.published_at', null);

        $article->refresh();
        $scan = $article->latestRiskScan()->firstOrFail();
        $this->assertSame('warning', $scan->status);
        $this->assertSame('api_save', $scan->trigger);
        $this->assertSame($this->admin->id, $scan->admin_id);
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

    public function test_warning_approved_with_explicit_risk_override_reason_then_publish_succeeds(): void
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
        $this->assertTrue($article->latestRiskScan->is_overridden);
        $this->assertSame('A human editor confirmed this warning.', $article->latestRiskScan->override_reason);
        $this->assertDatabaseHas('article_reviews', [
            'article_id' => $articleId,
            'admin_id' => $this->admin->id,
            'review_status' => 'approved',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$articleId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'approved');
    }

    public function test_review_note_alone_does_not_override_a_warning(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'Ordinary editorial note.',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked');
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

    public function test_auto_approved_warning_publish_rejects_even_a_prior_manual_override(): void
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
            ->assertJsonPath('error.code', 'article_risk_blocked')
            ->assertJsonPath('error.details.article_id', $article->id)
            ->assertJsonPath('error.details.risk_status', 'warning');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertTrue($article->latestRiskScan->is($confirmedScan));
        $this->assertTrue($article->latestRiskScan->is_overridden);
    }

    public function test_risky_publish_replays_the_cached_409_after_the_fallback_changes_workflow(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
            'review_status' => 'auto_approved',
        ]);
        app(ArticleRiskGate::class)->check(
            $article,
            'manual_review',
            $this->admin->id,
            'Previously confirmed by a human.',
        );
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

    public function test_publish_records_a_fresh_scan_for_the_audit_admin(): void
    {
        $article = $this->createArticle(['review_status' => 'approved']);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $scan = $article->refresh()->latestRiskScan()->firstOrFail();
        $this->assertSame('api_publish', $scan->trigger);
        $this->assertSame($this->admin->id, $scan->admin_id);
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
        $transitionService = \Mockery::mock(ArticleWorkflowTransitionService::class);
        $transitionService->shouldReceive('transition')
            ->once()
            ->andReturnUsing(function (
                Article $transitionArticle,
                array $workflowState,
                string $trigger,
                ?int $adminId,
                ?string $overrideReason,
                bool $allowExistingOverride,
                ?array $rejectedWorkflowState,
                callable $lockedGuard,
            ): Article {
                $transitionArticle->update(['review_status' => 'pending']);
                $lockedGuard($transitionArticle->fresh());

                return $transitionArticle;
            });
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
        $this->assertSame('pending', $article->review_status);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_auto_approved_review_rejects_a_prior_override_without_recording_a_review(): void
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

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'article_risk_blocked')
            ->assertJsonPath('error.details.risk_status', 'warning');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertDatabaseMissing('article_reviews', ['article_id' => $article->id]);
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
}
