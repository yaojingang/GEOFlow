<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\ArticleRiskScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class AdminArticleRiskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Category $category;

    private Author $author;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->admin = Admin::query()->create([
            'username' => 'article-risk-admin',
            'password' => 'secret-123',
            'email' => 'article-risk-admin@example.com',
            'display_name' => 'Article Risk Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->category = Category::query()->create([
            'name' => 'Risk workflow',
            'slug' => 'risk-workflow',
        ]);
        $this->author = Author::query()->create([
            'name' => 'Risk Author',
            'email' => 'risk-author@example.com',
        ]);
    }

    public function test_draft_create_records_a_fresh_admin_save_scan(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload())
            ->assertRedirect();

        $article = Article::query()->where('title', 'Manual article')->firstOrFail();
        $scan = $article->latestRiskScan()->firstOrFail();

        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('admin_save', $scan->trigger);
        $this->assertSame($this->admin->id, $scan->admin_id);
        $this->assertSame('clean', $scan->status);
    }

    public function test_draft_create_rolls_back_when_the_risk_scan_cannot_be_recorded(): void
    {
        $scanner = \Mockery::mock(ArticleRiskScanner::class)->makePartial();
        $this->app->instance(ArticleRiskScanner::class, $scanner);
        $scanner->shouldReceive('record')->once()->andThrow(new RuntimeException('scanner unavailable'));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload())
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('articles', ['title' => 'Manual article']);
    }

    public function test_update_rolls_back_content_when_the_risk_scan_cannot_be_recorded(): void
    {
        $article = $this->createArticle(['content' => 'Original content.']);
        $scanner = \Mockery::mock(ArticleRiskScanner::class)->makePartial();
        $this->app->instance(ArticleRiskScanner::class, $scanner);
        $scanner->shouldReceive('record')->once()->andThrow(new RuntimeException('scanner unavailable'));

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), $this->articlePayload([
                'title' => 'Updated title',
                'content' => 'Updated content.',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('Existing article', $article->title);
        $this->assertSame('Original content.', $article->content);
    }

    public function test_warning_publish_without_reason_is_saved_as_pending_draft_with_an_error(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload([
                'content' => 'Please review me before publishing.',
                'status' => 'published',
                'review_status' => 'approved',
            ]));

        $article = Article::query()->where('title', 'Manual article')->firstOrFail();
        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasErrors();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('warning', $article->latestRiskScan->status);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_warning_publish_with_approved_review_and_reason_publishes_and_persists_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload([
                'content' => 'Please review me before publishing.',
                'status' => 'published',
                'review_status' => 'approved',
                'risk_override_reason' => 'Reviewed with legal.',
            ]))
            ->assertRedirect();

        $article = Article::query()->where('title', 'Manual article')->firstOrFail();
        $scan = $article->latestRiskScan;

        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNotNull($article->published_at);
        $this->assertTrue($scan->is_overridden);
        $this->assertSame('Reviewed with legal.', $scan->override_reason);
        $this->assertSame($this->admin->id, $scan->overridden_by_admin_id);
    }

    public function test_blocked_publish_with_reason_remains_a_pending_draft(): void
    {
        SensitiveWord::query()->create([
            'word' => 'prohibited',
            'severity' => 'blocked',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload([
                'content' => 'This content is prohibited.',
                'status' => 'published',
                'review_status' => 'approved',
                'risk_override_reason' => 'Accept the risk.',
            ]));

        $article = Article::query()->where('title', 'Manual article')->firstOrFail();
        $response->assertSessionHasErrors();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('blocked', $article->latestRiskScan->status);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_auto_approved_warning_is_downgraded_without_using_an_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload([
                'content' => 'Please review me before publishing.',
                'review_status' => 'auto_approved',
                'risk_override_reason' => 'This reason must be ignored.',
            ]));

        $article = Article::query()->where('title', 'Manual article')->firstOrFail();
        $response->assertSessionHasErrors();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertFalse($article->latestRiskScan->is_overridden);
    }

    public function test_batch_auto_approved_warning_ignores_an_existing_manual_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
            'review_status' => 'pending',
        ]);
        $confirmedScan = app(ArticleRiskGate::class)->check(
            $article,
            'manual_review',
            $this->admin->id,
            'Manually reviewed.',
        );

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.batch.update-review'), [
                'article_ids' => [$article->id],
                'review_status' => 'auto_approved',
                'risk_override_reason' => 'Must be ignored.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertTrue($article->latestRiskScan->is($confirmedScan));
    }

    public function test_warning_update_never_leaves_the_previously_published_article_public(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), $this->articlePayload([
                'title' => 'Updated risky article',
                'content' => 'Please review me before publishing.',
                'status' => 'published',
                'review_status' => 'approved',
            ]));

        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasErrors();
        $article->refresh();
        $this->assertSame('Updated risky article', $article->title);
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('admin_save', $article->latestRiskScan->trigger);
        $this->assertSame('warning', $article->latestRiskScan->status);
    }

    public function test_clean_published_update_remains_published_with_a_fresh_admin_save_scan(): void
    {
        SensitiveWord::query()->create(['word' => 'prohibited']);
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $previousScan = app(ArticleRiskScanner::class)->record($article, 'previous_save', $this->admin->id);

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), $this->articlePayload([
                'title' => 'Updated clean article',
                'content' => 'Updated clean content.',
                'status' => 'published',
                'review_status' => 'approved',
            ]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionDoesntHaveErrors();

        $article->refresh();
        $latestScan = $article->latestRiskScan;

        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNotNull($article->published_at);
        $this->assertSame('clean', $latestScan->status);
        $this->assertSame('admin_save', $latestScan->trigger);
        $this->assertSame($this->admin->id, $latestScan->admin_id);
        $this->assertFalse($latestScan->is($previousScan));
        $this->assertSame(2, $article->riskScans()->count());
    }

    public function test_non_risk_admin_update_preserves_a_fresh_warning_override(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'title' => 'Manual article',
            'excerpt' => 'Manual excerpt',
            'content' => 'Please review me before publishing.',
            'keywords' => 'manual,article',
            'meta_description' => 'Manual article description.',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $confirmedScan = app(ArticleRiskGate::class)->check(
            $article,
            'manual_review',
            $this->admin->id,
            'Reviewed once for this exact content.',
        );

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), $this->articlePayload([
                'content' => 'Please review me before publishing.',
                'status' => 'published',
                'review_status' => 'approved',
                'is_featured' => '1',
            ]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionDoesntHaveErrors();

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertTrue((bool) $article->is_featured);
        $this->assertTrue($article->latestRiskScan->is($confirmedScan));
        $this->assertTrue($article->latestRiskScan->is_overridden);
    }

    public function test_manual_recheck_downgrades_a_published_article_when_a_new_blocked_rule_matches(): void
    {
        $article = $this->createArticle([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        SensitiveWord::query()->create([
            'word' => 'Existing article content',
            'severity' => 'blocked',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.risk-scan', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('blocked', $article->latestRiskScan->status);
        $this->assertSame('admin_recheck', $article->latestRiskScan->trigger);
    }

    public function test_mixed_batch_publish_allows_clean_article_and_rejects_risky_article(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $cleanArticle = $this->createArticle([
            'title' => 'Clean batch article',
            'content' => 'Safe batch content.',
            'review_status' => 'approved',
        ]);
        $riskyArticle = $this->createArticle([
            'title' => 'Risky batch article',
            'content' => 'Please review me before publishing.',
            'review_status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.batch.update-status'), [
                'article_ids' => [$cleanArticle->id, $riskyArticle->id],
                'new_status' => 'published',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('message')
            ->assertSessionHasErrors();
        $this->assertStringContainsString('1', session('errors')->first());
        $this->assertSame('published', $cleanArticle->fresh()->status);
        $this->assertSame('approved', $cleanArticle->fresh()->review_status);
        $this->assertNotNull($cleanArticle->fresh()->published_at);
        $this->assertSame('draft', $riskyArticle->fresh()->status);
        $this->assertSame('pending', $riskyArticle->fresh()->review_status);
        $this->assertNull($riskyArticle->fresh()->published_at);
        $this->assertSame('warning', $riskyArticle->fresh()->latestRiskScan->status);
        $this->assertSame('admin_batch_status', $riskyArticle->fresh()->latestRiskScan->trigger);
    }

    public function test_batch_draft_transition_does_not_call_the_risk_gate(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle([
            'content' => 'Please review me before publishing.',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.batch.update-status'), [
                'article_ids' => [$article->id],
                'new_status' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_override_reason_is_limited_to_one_thousand_characters_for_forms_and_batch_actions(): void
    {
        $tooLong = str_repeat('x', 1001);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.store'), $this->articlePayload([
                'risk_override_reason' => $tooLong,
            ]))
            ->assertSessionHasErrors('risk_override_reason');

        $article = $this->createArticle(['review_status' => 'approved']);
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.articles.batch.update-status'), [
                'article_ids' => [$article->id],
                'new_status' => 'published',
                'risk_override_reason' => $tooLong,
            ])
            ->assertSessionHasErrors('risk_override_reason');
        $this->assertSame('draft', $article->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function articlePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Manual article',
            'excerpt' => 'Manual excerpt',
            'content' => 'Manual article content.',
            'keywords' => 'manual,article',
            'meta_description' => 'Manual article description.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createArticle(array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'title' => 'Existing article',
            'slug' => 'article-'.uniqid(),
            'excerpt' => 'Existing excerpt',
            'content' => 'Existing article content.',
            'keywords' => 'existing,article',
            'meta_description' => 'Existing article description.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $overrides));
    }
}
