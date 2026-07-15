<?php

namespace Tests\Feature;

use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleRiskScan;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\ArticleRiskScanner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleRiskGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_clean_article_passes_with_a_recorded_scan(): void
    {
        SensitiveWord::query()->create(['word' => 'danger']);

        $scan = $this->gate()->check($this->createArticle(), 'publish');

        $this->assertSame('clean', $scan->status);
        $this->assertFalse($scan->is_overridden);
        $this->assertNull($scan->override_reason);
        $this->assertNull($scan->overridden_by_admin_id);
        $this->assertNull($scan->overridden_at);
        $this->assertModelExists($scan);
    }

    public function test_warning_without_a_reason_throws_and_exposes_the_scan(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);

        try {
            $this->gate()->check($article, 'publish');
            $this->fail('Expected the warning scan to stop the risk gate.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertSame('warning', $exception->riskStatus);
            $this->assertSame('warning', $exception->scan->status);
            $this->assertTrue($exception->scan->article->is($article));
            $this->assertStringContainsString('warning', $exception->getMessage());
        }
    }

    public function test_invisible_unicode_does_not_count_as_an_override_reason(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();

        try {
            $this->gate()->check($article, 'publish', $admin->id, "\u{200B}\u{E0061}");
            $this->fail('Expected an invisible override reason to be rejected.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertFalse($exception->scan->is_overridden);
            $this->assertNull($exception->scan->override_reason);
        }
    }

    public function test_warning_with_admin_and_reason_persists_confirmation(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();

        $scan = $this->gate()->check($article, 'publish', $admin->id, '  Reviewed with legal.  ');

        $scan->refresh();
        $this->assertTrue($scan->is_overridden);
        $this->assertSame('Reviewed with legal.', $scan->override_reason);
        $this->assertSame($admin->id, $scan->overridden_by_admin_id);
        $this->assertSame($admin->username, $scan->overridden_by_username);
        $this->assertNotNull($scan->overridden_at);
        $this->assertTrue($scan->overriddenBy->is($admin));
        $this->assertFalse($scan->isFillable('is_overridden'));
        $this->assertFalse($scan->isFillable('override_reason'));
        $this->assertFalse($scan->isFillable('overridden_by_admin_id'));
        $this->assertFalse($scan->isFillable('overridden_by_username'));
        $this->assertFalse($scan->isFillable('overridden_at'));
    }

    public function test_blocked_scan_cannot_be_overridden(): void
    {
        SensitiveWord::query()->create([
            'word' => 'prohibited',
            'severity' => 'blocked',
        ]);
        $article = $this->createArticle(['content' => 'This is prohibited.']);
        $admin = $this->createAdmin();

        try {
            $this->gate()->check($article, 'publish', $admin->id, 'Accept the risk.');
            $this->fail('Expected the blocked scan to stop the risk gate.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertSame('blocked', $exception->riskStatus);
            $this->assertFalse($exception->scan->is_overridden);
            $this->assertNull($exception->scan->override_reason);
        }
    }

    public function test_fresh_overridden_warning_can_pass_without_repeating_the_reason(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $gate = $this->gate();
        $confirmedScan = $gate->check($article, 'publish', $admin->id, 'Reviewed.');

        $reusedScan = $gate->check($article, 'distribute');

        $this->assertTrue($reusedScan->is($confirmedScan));
        $this->assertSame(1, $article->riskScans()->count());
    }

    public function test_fresh_overridden_warning_is_rejected_when_existing_overrides_are_disabled(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $gate = $this->gate();
        $confirmedScan = $gate->check($article, 'publish', $admin->id, 'Reviewed.');

        try {
            $gate->check($article, 'auto_approve', null, null, false);
            $this->fail('Expected auto approval to reject an existing warning override.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertTrue($exception->scan->is($confirmedScan));
            $this->assertSame('warning', $exception->riskStatus);
        }

        $this->assertSame(1, $article->riskScans()->count());
    }

    public function test_disabled_existing_override_policy_does_not_create_an_override_from_reason(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();

        try {
            $this->gate()->check($article, 'auto_approve', $admin->id, 'Must be ignored.', false);
            $this->fail('Expected auto approval to reject the warning.');
        } catch (ArticleRiskGateException $exception) {
            $scan = $exception->scan->fresh();
            $this->assertFalse($scan->is_overridden);
            $this->assertNull($scan->override_reason);
            $this->assertNull($scan->overridden_by_admin_id);
        }
    }

    public function test_gate_queries_the_latest_scan_instead_of_trusting_a_preloaded_relation(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $scanner = app(ArticleRiskScanner::class);
        $scan = $scanner->record($article, 'publish');
        $article->load('latestRiskScan');

        $confirmedScan = $this->gate()->check(
            Article::query()->findOrFail($article->id),
            'publish',
            $admin->id,
            'Reviewed elsewhere.',
        );
        $reusedScan = $this->gate()->check($article, 'distribute');

        $this->assertTrue($reusedScan->is($scan));
        $this->assertTrue($reusedScan->is($confirmedScan));
        $this->assertTrue($reusedScan->is_overridden);
    }

    public function test_first_confirmation_wins_when_the_selected_scan_becomes_stale(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $firstAdmin = $this->createAdmin();
        $secondAdmin = $this->createAdmin();
        $scan = app(ArticleRiskScanner::class)->record($article, 'publish');
        $firstOverriddenAt = now()->subMinute()->startOfSecond();
        $firstConfirmationInjected = false;

        DB::listen(function (QueryExecuted $query) use (
            &$firstConfirmationInjected,
            $scan,
            $firstAdmin,
            $firstOverriddenAt,
        ): void {
            if (
                $firstConfirmationInjected
                || ! str_starts_with(ltrim(strtolower($query->sql)), 'select')
                || ! str_contains($query->sql, 'article_risk_scans')
            ) {
                return;
            }

            $firstConfirmationInjected = true;
            ArticleRiskScan::query()->whereKey($scan->id)->update([
                'is_overridden' => true,
                'override_reason' => 'First review.',
                'overridden_by_admin_id' => $firstAdmin->id,
                'overridden_by_username' => $firstAdmin->username,
                'overridden_at' => $firstOverriddenAt,
            ]);
        });

        $result = $this->gate()->check(
            Article::query()->findOrFail($article->id),
            'publish',
            $secondAdmin->id,
            'Second review.',
        );

        $this->assertTrue($firstConfirmationInjected);
        $this->assertSame('First review.', $result->override_reason);
        $this->assertSame($firstAdmin->id, $result->overridden_by_admin_id);
        $this->assertSame($firstAdmin->username, $result->overridden_by_username);
        $this->assertTrue($result->overridden_at->equalTo($firstOverriddenAt));
    }

    public function test_newer_scan_inserted_after_override_update_rolls_back_the_confirmation(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $scan = app(ArticleRiskScanner::class)->record($article, 'publish');
        $newerScanInserted = false;

        DB::listen(function (QueryExecuted $query) use (&$newerScanInserted, $scan): void {
            if (
                $newerScanInserted
                || ! str_starts_with(ltrim(strtolower($query->sql)), 'update')
                || ! str_contains($query->sql, 'article_risk_scans')
                || ! str_contains($query->sql, 'is_overridden')
            ) {
                return;
            }

            $newerScanInserted = true;
            $newerScan = $scan->replicate();
            $newerScan->trigger = 'concurrent_edit';
            $newerScan->scanned_at = now()->addMinute();
            $newerScan->save();
        });

        try {
            $this->gate()->check($article, 'publish', $admin->id, 'Reviewed.');
            $this->fail('Expected a newer scan to invalidate the confirmation.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertTrue($exception->scan->is($scan));
        }

        $this->assertTrue($newerScanInserted);
        $this->assertFalse($scan->fresh()->is_overridden);
        $this->assertSame(1, $article->riskScans()->count());
    }

    public function test_admin_deletion_nulls_the_override_foreign_key_and_keeps_the_username_snapshot(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $username = $admin->username;
        $scan = $this->gate()->check($article, 'publish', $admin->id, 'Reviewed.');

        $admin->delete();
        $scan->refresh();

        $this->assertNull($scan->overridden_by_admin_id);
        $this->assertNull($scan->overriddenBy);
        $this->assertSame($username, $scan->overridden_by_username);
    }

    public function test_edited_article_requires_a_new_confirmation(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $gate = $this->gate();
        $confirmedScan = $gate->check($article, 'publish', $admin->id, 'Reviewed.');
        $article->update(['content' => 'Edited, and still asks you to review me.']);

        try {
            $gate->check($article, 'publish');
            $this->fail('Expected edited warning content to require confirmation.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertFalse($exception->scan->is($confirmedScan));
            $this->assertFalse($exception->scan->is_overridden);
            $this->assertSame(2, $article->riskScans()->count());
        }
    }

    public function test_dictionary_change_and_cache_clear_require_a_new_confirmation(): void
    {
        $rule = SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = $this->createAdmin();
        $scanner = app(ArticleRiskScanner::class);
        $gate = new ArticleRiskGate($scanner);
        $confirmedScan = $gate->check($article, 'publish', $admin->id, 'Reviewed.');
        $rule->update(['suggestion' => 'Replace this phrase.']);
        $scanner->clearRuleCache();

        try {
            $gate->check($article, 'publish');
            $this->fail('Expected a changed dictionary to require confirmation.');
        } catch (ArticleRiskGateException $exception) {
            $this->assertFalse($exception->scan->is($confirmedScan));
            $this->assertFalse($exception->scan->is_overridden);
            $this->assertSame(2, $article->riskScans()->count());
        }
    }

    private function gate(): ArticleRiskGate
    {
        return app(ArticleRiskGate::class);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'risk-reviewer-'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid().'@example.com',
            'display_name' => 'Risk Reviewer',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createArticle(array $attributes = []): Article
    {
        $category = Category::query()->create([
            'name' => 'Risk checks',
            'slug' => 'risk-checks-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Risk Author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create(array_merge([
            'title' => 'Article title',
            'slug' => 'article-'.uniqid(),
            'excerpt' => 'Article excerpt',
            'content' => 'Original article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => 'article,risk',
            'meta_description' => 'Article meta description',
        ], $attributes));
    }
}
