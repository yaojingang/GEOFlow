<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\ArticleRiskScan;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TopicSourceInvalidation;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicViewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TopicServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimal_draft_can_be_saved_and_has_no_public_view(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', ['title' => 'AI Guide']);
        $saved = $service->save($topic, ['title' => 'Better Guide'], 1);

        $this->assertSame(2, $saved->draft_version);
        $this->assertSame('', $saved->draft_payload['intro']);
        $this->assertNull($service->publicView($topic));
        $this->assertTrue($service->previewView($topic)['noindex']);
        $this->assertSame(0, $saved->revisions()->count());
    }

    public function test_public_revision_is_independent_of_later_draft_edits_and_article_order_is_preserved(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$second, $first]));
        $revision = $service->publish($topic, 1);
        $service->save($topic, ['title' => 'Unpublished title', 'intro' => 'Private changes'], 1);
        $view = $service->publicView($topic);

        $this->assertSame('AI Guide', $view['base_title']);
        $this->assertSame('A sourced introduction.', $view['intro']);
        $this->assertSame([$second->id, $first->id], array_column($view['articles'], 'id'));
        $this->assertSame('Unpublished title', $service->previewView($topic)['title']);
        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
        $this->assertSame(1, $topic->revisions()->count());
        foreach (['owner_admin_id', 'task_id', 'publication_requests', 'draft_payload'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $view);
        }
    }

    public function test_browser_newline_conversion_preserves_valid_fact_evidence_without_accepting_text_changes(): void
    {
        $first = $this->article();
        $first->update(['content' => "GEO source fact.\nSecond supporting line."]);
        $payload = $this->payload([$first, $this->article()]);
        $payload['summary']['facts'][0]['evidence'][0]['text'] = "GEO source fact.\r\nSecond supporting line.";
        $service = app(TopicService::class);
        $topic = $service->create('primary', $payload);

        $revision = $service->publish($topic, 1);

        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
        $this->assertCount(1, $service->publicView($topic)['summary']['facts']);
        $payload['summary']['facts'][0]['evidence'][0]['text'] = "Altered source fact.\r\nSecond supporting line.";
        $service->save($topic, $payload, 1);
        $this->assertValidation('summary.facts.0.evidence', fn () => $service->publish($topic, 2));
        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
    }

    public function test_pending_review_is_a_fixed_revision_and_keeps_the_previous_public_revision(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $old = $service->publish($topic, 1);
        $service->save($topic, ['title' => 'Reviewed title'], 1);
        $pending = $service->publish($topic, 2, reviewRequired: true);
        $service->save($topic, ['title' => 'Newer draft'], 2);

        $this->assertSame($old->id, $topic->fresh()->public_revision_id);
        $this->assertSame('AI Guide', $service->publicView($topic)['title']);
        $approved = $service->approve($topic, expectedRevisionId: $pending->id);

        $this->assertSame($pending->id, $approved->id);
        $this->assertSame('Reviewed title', $service->publicView($topic)['title']);
        $this->assertSame('Newer draft', $service->previewView($topic)['title']);
        $this->assertSame(3, $topic->fresh()->draft_version);
        $this->assertNull($topic->fresh()->pending_revision_id);
    }

    public function test_pending_first_publication_has_no_public_dates_until_approval(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $service->publish($topic, 1, reviewRequired: true);

        $this->assertNull($service->publicView($topic));
        $this->assertNull($topic->fresh()->published_at);
        $this->assertNull($topic->fresh()->first_published_at);
        $service->approve($topic);
        $this->assertNotNull($topic->fresh()->published_at);
    }

    public function test_article_status_review_and_delete_changes_are_applied_to_every_public_read(): void
    {
        $first = $this->article();
        $second = $this->article();
        $third = $this->article();
        $service = app(TopicService::class);
        $payload = $this->payload([$first, $second, $third]);
        $payload['summary']['facts'] = [
            ['text' => 'First fact', 'article_ids' => [$first->id], 'evidence' => [$this->sourceEvidence($first)]],
            ['text' => 'Second fact', 'article_ids' => [$second->id], 'evidence' => [$this->sourceEvidence($second)]],
        ];
        $topic = $service->create('primary', $payload);
        $service->publish($topic, 1);
        $first->update(['status' => 'private']);
        $this->assertNull($service->publicView($topic));
        $view = $service->previewView($topic);

        $this->assertSame(2, $view['article_count']);
        $this->assertSame(['Second fact'], array_column($view['summary']['facts'], 'text'));
        $this->assertSame('', $view['intro']);
        $second->update(['review_status' => 'pending']);
        $this->assertNull($service->publicView($topic));
        $first->update(['status' => 'published']);
        $third->delete();
        $this->assertNull($service->publicView($topic));
    }

    public function test_source_content_change_hides_dependent_claims_score_and_reasons_without_changing_revision(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $payload = $this->payload([$first, $second]);
        $payload['score'] = $this->score($first);
        $payload['faq'] = [['question' => 'Why?', 'answer' => 'Because.', 'article_ids' => [$first->id]]];
        $topic = $service->create('primary', $payload);
        $revision = $service->publish($topic, 1);
        $first->update(['content' => 'Changed source body']);
        $this->assertNull($service->publicView($topic));
        $view = $service->previewView($topic);

        $this->assertSame(2, $view['article_count']);
        $this->assertSame('', $view['intro']);
        $this->assertSame('', $view['summary']['one_sentence']);
        $this->assertSame([], $view['summary']['facts']);
        $this->assertSame([], $view['faq']);
        $this->assertNull($view['score']);
        $this->assertSame('', $view['articles'][0]['reason']);
        $this->assertSame($payload['intro'], $revision->fresh()->payload['intro']);
    }

    public function test_publication_requires_two_distinct_currently_eligible_sources_and_an_intro(): void
    {
        $service = app(TopicService::class);
        $article = $this->article();
        $topic = $service->create('primary', $this->payload([$article]));
        $this->assertValidation('articles', fn () => $service->publish($topic, 1));
        $this->assertValidation('articles.1.article_id', fn () => $service->save($topic, $this->payload([$article, $article]), 1));
        $topic = $service->save($topic, $this->payload([$article, $this->article()], ['intro' => '']), 1);
        $this->assertValidation('intro', fn () => $service->publish($topic, 2));
        $this->assertSame(0, $topic->revisions()->count());
    }

    public function test_primary_and_hosted_source_queries_are_isolated_and_assignment_withdrawal_hides_topic(): void
    {
        $primary = $this->article();
        $alpha = $this->hostedProfile('alpha');
        $beta = $this->hostedProfile('beta');
        $alphaFirst = $this->hostedArticle($alpha);
        $alphaSecond = $this->hostedArticle($alpha);
        $betaArticle = $this->hostedArticle($beta);
        $service = app(TopicService::class);
        $topic = $service->create('hosted:'.$alpha->id, $this->payload([$alphaFirst, $alphaSecond]));
        $service->publish($topic, 1);

        $this->assertSame([$primary->id], app(SiteScopedArticleQuery::class)->queryForSiteKey('primary')->pluck('id')->all());
        $this->assertSame(2, $service->publicView($topic)['article_count']);
        $this->assertStringStartsWith('https://alpha.sites.test/', $service->publicView($topic)['articles'][0]['url']);
        $wrong = $service->create('hosted:'.$beta->id, $this->payload([$alphaFirst, $betaArticle], ['title' => 'Wrong sources']));
        $this->assertValidation('articles', fn () => $service->publish($wrong, 1));
        $alphaFirst->hostedSiteAssignment->update(['status' => HostedSiteArticleAssignment::STATUS_WITHDRAWN]);
        $this->assertNull($service->publicView($topic));
    }

    public function test_approval_revalidates_current_assignment_and_review_status(): void
    {
        $profile = $this->hostedProfile('review');
        $first = $this->hostedArticle($profile);
        $second = $this->hostedArticle($profile);
        $service = app(TopicService::class);
        $topic = $service->create('hosted:'.$profile->id, $this->payload([$first, $second]));
        $service->publish($topic, 1, reviewRequired: true);
        $first->update(['review_status' => 'rejected']);

        $this->assertValidation('articles', fn () => $service->approve($topic));
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertNotNull($topic->fresh()->pending_revision_id);
    }

    public function test_site_scoped_normalized_title_and_slug_uniqueness_includes_trashed_topics(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', ['title' => 'AI   Guide', 'slug' => 'ai-guide']);
        $topic->delete();

        $this->assertValidation('title', fn () => $service->create('primary', ['title' => 'ai guide']));
        $this->assertValidation('slug', fn () => $service->create('primary', ['title' => 'Different', 'slug' => 'ai-guide']));
        $hosted = $service->create('hosted:'.$this->hostedProfile('unique')->id, ['title' => 'ai guide', 'slug' => 'ai-guide']);
        $this->assertModelExists($hosted);
        $this->assertTrue($topic->fresh()->trashed());
    }

    public function test_optimistic_conflict_leaves_draft_and_public_revision_unchanged(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $saved = $service->save($topic, ['intro' => 'Latest draft'], 1);

        $this->assertValidation('draft_version', fn () => $service->save($topic, ['title' => 'Stale'], 1));
        $this->assertValidation('draft_version', fn () => $service->publish($topic, 1));
        $this->assertSame('Latest draft', $topic->fresh()->draft_payload['intro']);
        $this->assertSame($saved->draft_version, $topic->fresh()->draft_version);
        $this->assertSame(0, $topic->revisions()->count());
    }

    public function test_duplicate_publication_requests_do_not_create_revisions_or_repeat_publication_after_withdrawal(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $revision = $service->publish($topic, 1, requestId: 'first');
        $publishedAt = $topic->fresh()->published_at->toIso8601String();
        $this->travel(1)->hour();
        $retry = $service->publish($topic, 1, requestId: 'another-request');
        $this->assertSame($revision->id, $retry->id);
        $this->assertSame($publishedAt, $topic->fresh()->published_at->toIso8601String());
        $service->withdraw($topic);
        $withdrawnAt = $topic->fresh()->withdrawn_at->toIso8601String();
        $this->travel(1)->hour();
        $service->withdraw($topic);
        $service->save($topic, ['title' => 'New draft'], 1);
        $this->assertSame($revision->id, $service->publish($topic, 1, requestId: 'another-request')->id);
        $this->assertSame(1, $topic->revisions()->count());
        $this->assertSame($withdrawnAt, $topic->fresh()->withdrawn_at->toIso8601String());
        $this->assertNull($service->publicView($topic));
        $this->assertValidation('request_id', fn () => $service->publish($topic, 2, requestId: 'first'));
    }

    public function test_restore_rechecks_sources_and_only_changes_draft(): void
    {
        $first = $this->article();
        $second = $this->article();
        $third = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second, $third]));
        $revision = $service->publish($topic, 1);
        $topic = $service->save($topic, ['title' => 'New title'], 1);
        $first->update(['status' => 'draft']);
        $restored = $service->restoreRevision($topic, $revision->id, 2);

        $this->assertSame(3, $restored->draft_version);
        $this->assertSame([$second->id, $third->id], array_column($restored->draft_payload['articles'], 'article_id'));
        $this->assertSame('', $restored->draft_payload['intro']);
        $this->assertSame($revision->id, $restored->public_revision_id);
        $other = $service->create('primary', ['title' => 'Other topic']);
        $this->assertValidation('revision_id', fn () => $service->restoreRevision($other, $revision->id, 1));
        $this->assertValidation('draft_version', fn () => $service->restoreRevision($topic, $revision->id, 2));
    }

    public function test_immutable_revision_cannot_be_changed_and_soft_delete_removes_public_view(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $revision = $service->publish($topic, 1);
        try {
            $revision->update(['payload' => ['title' => 'Changed']]);
            $this->fail('Expected immutable revision rejection.');
        } catch (LogicException) {
            $this->assertSame('AI Guide', $revision->fresh()->payload['title']);
        }
        $topic->delete();
        $this->assertNull($service->publicView($topic));
    }

    public function test_supported_freshness_has_a_real_window_and_expires_back_to_plain_title(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()], [
            'freshness' => ['mode' => 'monthly', 'year' => 2026, 'month' => 10, 'coverage_note' => 'Covers October source articles.', 'valid_until' => '2026-10-31'],
        ]));
        $service->publish($topic, 1);

        $this->assertSame('2026年10月 AI Guide', $service->publicView($topic)['title']);
        $this->travelTo(now()->setDate(2026, 11, 1));
        $view = $service->publicView($topic);
        $this->assertSame('AI Guide', $view['title']);
        $this->assertNotEmpty($view['warnings']);
    }

    public function test_missing_freshness_basis_uses_plain_title_and_invalid_window_blocks_publication(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()], ['freshness' => ['mode' => 'annual', 'year' => 2026]]));
        $this->assertValidation('freshness.coverage_note', fn () => $service->publish($topic, 1));
        $this->assertSame('AI Guide', $service->previewView($topic)['title']);
        $this->assertSame('unknown', $service->previewView($topic)['freshness_status']);
        $topic = $service->save($topic, ['freshness' => ['mode' => 'monthly', 'year' => 2026, 'month' => 10, 'coverage_note' => 'October', 'valid_until' => '2026-11-30']], 1);
        $this->assertValidation('freshness.valid_until', fn () => $service->publish($topic, 2));
        $this->assertValidation('freshness.month', fn () => $service->save($topic, ['freshness' => ['mode' => 'monthly', 'month' => 13]], 2));
    }

    public function test_optional_score_is_evidence_based_normalized_on_ten_and_excluded_when_expired(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => $this->score($first)]));
        $service->publish($topic, 1);
        $view = $service->publicView($topic);

        $this->assertSame(8, $view['score']['total']);
        $this->assertSame(10, $view['score']['max']);
        $this->assertSame(4.0, $view['score']['stars']);
        $this->assertSame([0.5, 0.5], $view['score']['normalized_weights']);
        $this->travel(2)->days();
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_incomplete_disabled_score_is_optional_and_invalid_enabled_calculation_is_omitted(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => ['enabled' => false]]));
        $service->publish($topic, 1);
        $this->assertNull($service->publicView($topic)['score']);
        $score = $this->score($first);
        $score['total'] = 10;
        $topic = $service->save($topic, ['score' => $score], 1);
        $service->publish($topic, 2);
        $this->assertNull($service->publicView($topic)['score']);
        $score['total'] = 8;
        $score['weights'] = [0, 0];
        $topic = $service->save($topic, ['score' => $score], 2);
        $service->publish($topic, 3);
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_invalid_site_and_unsafe_or_changed_slug_are_rejected(): void
    {
        $service = app(TopicService::class);
        $this->assertValidation('site_key', fn () => $service->create('hosted:999999', ['title' => 'Invalid']));
        $this->assertValidation('slug', fn () => $service->create('primary', ['title' => 'Invalid', 'slug' => '../path']));
        $topic = $service->create('primary', ['title' => 'Stable', 'slug' => 'stable']);
        $this->assertValidation('slug', fn () => $service->save($topic, ['slug' => 'changed'], 1));
        $this->assertSame('stable', $topic->fresh()->slug);
    }

    public function test_unicode_whitespace_title_duplicates_are_reserved(): void
    {
        $service = app(TopicService::class);
        $service->create('primary', ['title' => "\u{3000}AI\u{00A0}Guide\u{3000}"]);

        $this->assertValidation('title', fn () => $service->create('primary', ['title' => 'ai guide']));
    }

    public function test_recent_freshness_rejects_a_window_longer_than_ninety_days(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()], [
            'freshness' => ['mode' => 'recent', 'coverage_note' => 'Recent articles.', 'valid_until' => now()->addDays(120)->toDateString()],
        ]));

        $this->assertValidation('freshness.valid_until', fn () => $service->publish($topic, 1));
    }

    public function test_non_finite_score_weights_are_omitted_without_blocking_publication(): void
    {
        $article = $this->article();
        $score = $this->score($article);
        $score['weights'] = ['1e308', '1e308'];
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$article, $this->article()], ['score' => $score]));

        $service->publish($topic, 1);
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_duplicate_withdrawal_request_cannot_withdraw_a_later_publication(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $first = $service->publish($topic, 1);
        $service->withdraw($topic, expectedRevisionId: $first->id, requestId: 'withdraw-first');
        $service->save($topic, ['title' => 'Republished'], 1);
        $second = $service->publish($topic, 2);
        $service->withdraw($topic, expectedRevisionId: $first->id, requestId: 'withdraw-first');

        $this->assertSame($second->id, $topic->fresh()->public_revision_id);
        $this->assertValidation('public_revision_id', fn () => $service->withdraw($topic, expectedRevisionId: $first->id));
    }

    public function test_stale_manual_approval_and_blocked_risk_sources_fail_the_shared_eligibility_check(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second, $this->article()]));
        $service->publish($topic, 1);
        $admin = Admin::query()->create(['username' => 'reviewer', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        ArticleReview::query()->create(['article_id' => $first->id, 'admin_id' => $admin->id, 'review_status' => 'approved', 'content_hash' => $first->reviewContentHash()]);
        $first->update(['content' => 'A body that has not been reviewed.']);
        ArticleRiskScan::query()->create(['article_id' => $second->id, 'status' => 'blocked', 'matches' => [], 'content_hash' => TopicService::contentHash($second),
            'dictionary_hash' => hash('sha256', 'dictionary'), 'trigger' => 'topic-test', 'scanned_at' => now()]);

        $this->assertFalse($service->isEligible($first->fresh(), 'primary'));
        $this->assertFalse($service->isEligible($second->fresh(), 'primary'));
        $this->assertNull($service->publicView($topic));
    }

    public function test_source_changes_block_draft_publication_until_an_explicit_recheck_without_losing_user_edits(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second]));
        $oldHash = $topic->draft_source_hashes[$first->id];
        $first->update(['content' => 'Revised source.']);
        $topic = $service->save($topic, ['title' => 'User title', 'intro' => 'User introduction'], 1);

        $this->assertValidation('articles', fn () => $service->publish($topic, 2));
        $this->assertSame('User introduction', $topic->fresh()->draft_payload['intro']);
        $this->assertSame($oldHash, $topic->fresh()->draft_source_hashes[$first->id]);
        $topic = $service->save($topic, ['summary' => ['one_sentence' => 'Checked current source.', 'facts' => []],
            'source_hashes' => [$first->id => TopicService::contentHash($first), $second->id => TopicService::contentHash($second)]], 2);
        $service->publish($topic, 3);
        $this->assertSame('User introduction', $service->publicView($topic)['intro']);
    }

    public function test_pending_source_change_and_recovery_cannot_approve_the_old_fixed_revision(): void
    {
        $first = $this->article();
        $second = $this->article();
        $originalBody = $first->content;
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second]));
        $pending = $service->publish($topic, 1, reviewRequired: true);
        $first->update(['content' => 'A changed pending source.']);

        $this->assertValidation('articles', fn () => $service->approve($topic, expectedRevisionId: $pending->id));
        $first->update(['content' => $originalBody]);
        $this->assertValidation('articles', fn () => $service->approve($topic, expectedRevisionId: $pending->id));
        $this->assertNull($topic->fresh()->public_revision_id);
        $topic = $service->save($topic, ['source_hashes' => [$first->id => TopicService::contentHash($first), $second->id => TopicService::contentHash($second)]], 1);
        $next = $service->publish($topic, 2, reviewRequired: true);
        $service->approve($topic, expectedRevisionId: $next->id);
        $this->assertSame($next->id, $topic->fresh()->public_revision_id);
    }

    public function test_invalid_expired_and_missing_optional_score_never_block_normal_publication(): void
    {
        $article = $this->article();
        $service = app(TopicService::class);
        $score = $this->score($article);
        $score['valid_until'] = now()->subDay()->toDateString();
        $topic = $service->create('primary', $this->payload([$article, $this->article()], ['score' => $score]));
        $service->publish($topic, 1);

        $this->assertNotNull($service->publicView($topic));
        $this->assertNull($service->publicView($topic)['score']);
        $topic = $service->save($topic, ['score' => ['enabled' => true, 'total' => 42]], 1);
        $service->publish($topic, 2);
        $this->assertNull($service->publicView($topic)['score']);
        $topic = $service->save($topic, ['score' => ['enabled' => true]], 2);
        $service->publish($topic, 3);
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_score_rating_date_default_expiry_and_content_baseline_do_not_renew_on_unrelated_saves(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $score = $this->score($first);
        unset($score['valid_until']);
        $ratedAt = now()->subDays(7)->startOfSecond();
        $score['rated_at'] = $ratedAt->toIso8601String();
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => $score]));
        $service->publish($topic, 1);
        $view = $service->publicView($topic);

        $this->assertSame($ratedAt->toIso8601String(), $view['score']['rated_at']);
        $this->assertSame($ratedAt->copy()->addDays(90)->toDateString(), $view['score']['valid_until']);
        $this->travel(1)->day();
        $topic = $service->save($topic, ['template_key' => 'guide'], 1);
        $service->publish($topic, 2);
        $this->assertSame($ratedAt->toIso8601String(), $service->publicView($topic)['score']['rated_at']);
        $topic = $service->save($topic, ['intro' => 'Substantially changed introduction.'], 2);
        $service->publish($topic, 3);
        $this->assertNull($service->publicView($topic)['score']);
        $this->assertSame($ratedAt->toIso8601String(), $topic->fresh()->draft_score_binding['rated_at']);
        $score['rated_at'] = now()->toIso8601String();
        $topic = $service->save($topic, ['score' => $score], 3);
        $service->publish($topic, 4);
        $this->assertSame(now()->startOfSecond()->toIso8601String(), $service->publicView($topic)['score']['rated_at']);
    }

    public function test_withdrawn_then_restored_source_keeps_old_derived_content_suppressed_without_an_intermediate_read(): void
    {
        $first = $this->article();
        $second = $this->article();
        $third = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second, $third], ['score' => $this->score($first)]));
        $old = $service->publish($topic, 1);
        $first->update(['status' => 'private']);
        $first->update(['status' => 'published']);
        $this->assertNull($service->publicView($topic));
        $view = $service->previewView($topic);

        $this->assertSame(3, $view['article_count']);
        $this->assertSame('', $view['intro']);
        $this->assertSame([], $view['summary']['facts']);
        $this->assertSame('', $view['articles'][0]['reason']);
        $this->assertNull($view['score']);
        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $old->id, 'article_id' => $first->id]);
        $topic = $service->save($topic, ['source_hashes' => [$first->id => TopicService::contentHash($first), $second->id => TopicService::contentHash($second), $third->id => TopicService::contentHash($third)]], 1);
        $service->publish($topic, 2);
        $this->assertSame('A sourced introduction.', $service->publicView($topic)['intro']);
        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $old->id, 'article_id' => $first->id]);
    }

    public function test_public_read_persists_minimal_invalidation_when_source_events_were_not_dispatched(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $this->article(), $this->article()]));
        $revision = $service->publish($topic, 1);
        Article::query()->whereKey($first->id)->update(['status' => 'private']);
        $this->assertSame(0, TopicSourceInvalidation::query()->where('topic_revision_id', $revision->id)->count());
        $service->publicView($topic);
        Article::query()->whereKey($first->id)->update(['status' => 'published']);

        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $revision->id, 'article_id' => $first->id]);
        $this->assertNull($service->publicView($topic));
        $historicalPreview = app(TopicViewBuilder::class)->build($topic->fresh(), $revision->payload, $revision, true);
        $this->assertSame('', $historicalPreview['intro']);
        $this->assertSame([], $historicalPreview['summary']['facts']);
    }

    public function test_draft_source_withdrawal_cycle_remains_invalid_until_an_explicit_recheck(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second]));
        $first->update(['status' => 'private']);
        $first->update(['status' => 'published']);
        $topic = $service->save($topic, ['title' => 'Later draft'], 1);

        $this->assertValidation('articles', fn () => $service->publish($topic, 2));
        $topic = $service->save($topic, ['source_hashes' => [$first->id => TopicService::contentHash($first), $second->id => TopicService::contentHash($second)]], 2);
        $service->publish($topic, 3);
        $this->assertNotNull($service->publicView($topic));
    }

    public function test_two_titles_with_identical_bodies_count_as_one_source_and_near_versions_remain_distinct(): void
    {
        $first = $this->article(['title' => 'First title', 'content' => 'Identical source body.']);
        $second = $this->article(['title' => 'Another title', 'content' => 'Identical source body.']);
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second]));

        $this->assertValidation('articles', fn () => $service->publish($topic, 1));
        $third = $this->article(['content' => 'Identical source body with a meaningful revision.']);
        $topic = $service->save($topic, ['articles' => [['article_id' => $first->id], ['article_id' => $third->id]]], 1);
        $service->publish($topic, 2);
        $this->assertSame(2, $service->publicView($topic)['article_count']);
        $third->update(['content' => $first->content]);
        $this->assertNull($service->publicView($topic));
    }

    public function test_manual_edits_and_stop_actions_preserve_the_maintenance_fence(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $revision = $service->publish($topic, 1);
        $topic = $service->save($topic, ['title' => 'Manual edit'], 1);
        $this->assertSame(1, $topic->manual_edit_version);
        $topic = $service->save($topic, ['title' => 'Automatic edit', 'automatic_write' => true], 2);
        $this->assertSame(1, $topic->manual_edit_version);
        $topic = $service->withdraw($topic);
        $pausedAt = $topic->maintenance_paused_at->toIso8601String();
        $restored = $service->restoreRevision($topic, $revision->id, 3);

        $this->assertSame($pausedAt, $restored->maintenance_paused_at->toIso8601String());
        $restored->delete();
        $restored->restore();
        $this->assertSame($pausedAt, $restored->fresh()->maintenance_paused_at->toIso8601String());
    }

    public function test_current_site_review_policy_cannot_be_bypassed_by_an_older_publish_choice(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['require_review' => true])]);
        $pending = $service->publish($topic, 1, reviewRequired: false);

        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertSame($pending->id, $topic->fresh()->pending_revision_id);
        $service->approve($topic);
        $this->assertSame($pending->id, $topic->fresh()->public_revision_id);
    }

    public function test_topic_migration_refuses_to_destroy_nonempty_domain_data(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Protected history']);
        $migration = require database_path('migrations/2026_10_01_064730_create_topics_tables.php');
        try {
            $migration->down();
            $this->fail('Expected nonempty rollback rejection.');
        } catch (RuntimeException) {
            $this->assertModelExists($topic);
        }
    }

    public function test_first_enabling_a_complete_saved_score_creates_a_real_rating_binding(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $score = $this->score($first);
        $score['enabled'] = false;
        unset($score['valid_until']);
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => $score]));
        $this->assertNull($topic->draft_score_binding);
        $this->travel(2)->days();
        $enabledAt = now()->startOfSecond()->toIso8601String();
        $score['enabled'] = true;
        $topic = $service->save($topic, ['score' => $score], 1);
        $service->publish($topic, 2);
        $view = $service->publicView($topic);

        $this->assertSame($enabledAt, $view['score']['rated_at']);
        $this->assertSame(now()->addDays(90)->toDateString(), $view['score']['valid_until']);
        $this->assertSame(8, $view['score']['total']);
    }

    public function test_score_expiry_cannot_outlive_the_true_ninety_day_rating_boundary(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $score = $this->score($first);
        $score['rated_at'] = now()->subDays(120)->toIso8601String();
        $score['valid_until'] = '2099-12-31';
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => $score]));
        $service->publish($topic, 1);

        $this->assertNotNull($service->publicView($topic));
        $this->assertNull($service->publicView($topic)['score']);
        $score['rated_at'] = now()->toIso8601String();
        $topic = $service->save($topic, ['score' => $score], 1);
        $service->publish($topic, 2);
        $this->assertSame(now()->addDays(90)->toDateString(), $service->publicView($topic)['score']['valid_until']);
        $this->travel(90)->days();
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_score_expiry_uses_the_earliest_evidence_and_freshness_boundary(): void
    {
        $first = $this->article();
        $service = app(TopicService::class);
        $score = $this->score($first);
        $score['valid_until'] = now()->addDays(60)->toDateString();
        $score['evidence'][0]['valid_until'] = now()->addDays(20)->toDateString();
        $topic = $service->create('primary', $this->payload([$first, $this->article()], ['score' => $score,
            'freshness' => ['mode' => 'event', 'coverage_note' => 'Verified current coverage.', 'valid_until' => now()->addDays(50)->toDateString()],
        ]));
        $service->publish($topic, 1);

        $this->assertNotNull($service->publicView($topic)['score']);
        $this->assertSame(now()->addDays(20)->toDateString(), $service->publicView($topic)['score']['valid_until']);
        $freshnessBound = $service->create('primary', $this->payload([$first, $this->article()], [
            'title' => 'Short coverage window', 'score' => $score,
            'freshness' => ['mode' => 'event', 'coverage_note' => 'Verified short coverage.', 'valid_until' => now()->addDays(10)->toDateString()],
        ]));
        $service->publish($freshnessBound, 1);
        $this->assertSame(now()->addDays(10)->toDateString(), $service->publicView($freshnessBound)['score']['valid_until']);
        $this->travel(11)->days();
        $this->assertNull($service->publicView($freshnessBound)['score']);
        $this->assertNotNull($service->publicView($topic)['score']);
        $this->travel(10)->days();
        $this->assertNull($service->publicView($topic)['score']);
    }

    public function test_recycling_a_topic_only_restores_draft_and_keeps_source_cycle_invalidations(): void
    {
        $first = $this->article();
        $second = $this->article();
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$first, $second]));
        $public = $service->publish($topic, 1);
        $topic = $service->save($topic, ['title' => 'Pending title'], 1);
        $pending = $service->publish($topic, 2, reviewRequired: true);
        $topic->delete();
        $first->update(['status' => 'private']);
        $first->update(['status' => 'published']);

        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $public->id, 'article_id' => $first->id]);
        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $pending->id, 'article_id' => $first->id]);
        $this->assertTrue($topic->restore());
        $topic = $topic->fresh();
        $this->assertSame(3, $topic->draft_version);
        $this->assertNull($topic->public_revision_id);
        $this->assertNull($topic->pending_revision_id);
        $this->assertNull($topic->submitted_at);
        $this->assertNotNull($topic->maintenance_paused_at);
        $this->assertNull($service->publicView($topic));
        $this->assertValidation('articles', fn () => $service->publish($topic, 3));
        $topic = $service->save($topic, ['source_hashes' => [$first->id => TopicService::contentHash($first), $second->id => TopicService::contentHash($second)]], 3);
        $restored = $service->publish($topic, 4);
        $this->assertNotSame($public->id, $restored->id);
        $this->assertNotSame($pending->id, $restored->id);
        $this->assertSame('A sourced introduction.', $service->publicView($topic)['intro']);
        $this->assertDatabaseHas('topic_source_invalidations', ['topic_revision_id' => $public->id, 'article_id' => $first->id]);
    }

    public function test_recycling_unchanged_sources_still_requires_an_explicit_new_source_check(): void
    {
        $service = app(TopicService::class);
        $topic = $service->create('primary', $this->payload([$this->article(), $this->article()]));
        $service->publish($topic, 1);
        $topic->delete();
        $topic->restore();
        $topic = $topic->fresh();

        $this->assertNull($service->publicView($topic));
        $this->assertFalse($topic->restore());
        $this->assertSame(2, $topic->fresh()->draft_version);
        $this->assertValidation('draft_version', fn () => $service->publish($topic, 1));
        $this->assertValidation('articles', fn () => $service->publish($topic, 2));
    }

    /** @param list<Article> $articles @param array<string,mixed> $overrides @return array<string,mixed> */
    private function payload(array $articles, array $overrides = []): array
    {
        return array_replace([
            'title' => 'AI Guide', 'intro' => 'A sourced introduction.',
            'summary' => ['one_sentence' => 'A sourced summary.', 'facts' => $articles === [] ? [] : [['text' => 'Source fact.', 'article_ids' => [$articles[0]->id], 'evidence' => [$this->sourceEvidence($articles[0])]]]],
            'articles' => array_map(fn (Article $article): array => ['article_id' => $article->id, 'group' => 'Reading', 'reason' => 'Relevant source.'], $articles),
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function score(Article $article): array
    {
        return ['enabled' => true, 'type' => 'editorial', 'source' => 'Editorial assessment', 'name' => 'Source quality',
            'dimensions' => [['name' => 'completeness', 'score' => 9], ['name' => 'usability', 'score' => 7]],
            'weights' => [2, 2], 'total' => 8, 'evidence' => [['text' => 'Assessment evidence.', 'article_ids' => [$article->id]]],
            'valid_until' => now()->addDay()->toDateString()];
    }

    private function article(array $overrides = []): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'topics'], ['name' => 'Topics']);
        $author = Author::query()->firstOrCreate(['email' => 'topics@example.test'], ['name' => 'Topic Author']);

        return Article::query()->create(array_replace([
            'title' => 'Source '.uniqid(), 'slug' => 'source-'.uniqid(), 'content' => 'Original source body '.uniqid(), 'excerpt' => 'Original excerpt.',
            'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ], $overrides));
    }

    private function hostedProfile(string $name): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create([
            'name' => $name, 'domain' => $name.'.sites.test', 'endpoint_url' => 'https://'.$name.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        return HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => $name.'.sites.test', 'root_domain' => 'sites.test']);
    }

    private function hostedArticle(HostedSiteProfile $profile): Article
    {
        $task = Task::query()->create(['name' => 'Hosted topic source', 'publish_scope' => 'distribution_only']);
        $article = $this->article(['status' => 'private', 'task_id' => $task->id]);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id, 'hosted_site_profile_id' => $profile->id, 'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', (string) $article->id), 'capacity_date' => now()->toDateString(), 'assigned_at' => now(), 'published_at' => now(),
        ]);

        return $article;
    }

    private function assertValidation(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function sourceEvidence(Article $article): array
    {
        $text = mb_substr((string) $article->content, 0, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
    }
}
