<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\Topics\TopicMatchingRules;
use App\Services\Topics\TopicPayload;
use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TopicReviewDomainRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function sources(): array
    {
        $author = Author::query()->create(['name' => 'Review fixture']);
        $category = Category::query()->create(['name' => 'Review', 'slug' => 'review']);

        return array_map(fn ($i) => Article::query()->create(['title' => 'GEO source '.$i, 'slug' => 'review-source-'.$i, 'content' => 'Different sourced body '.$i, 'excerpt' => 'Source excerpt', 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]), [1, 2, 3]);
    }

    private function topic(array $sources): Topic
    {
        return app(TopicService::class)->create('primary', ['title' => 'Review topic', 'intro' => 'Core interpretation based on selected sources.', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
    }

    public function test_exclusion_checks_title_and_keywords_while_body_negative_examples_remain_candidates(): void
    {
        $article = new Article(['title' => 'GEO guide', 'keywords' => 'seo', 'excerpt' => 'Practical guide', 'content' => 'A guide to GEO. Avoid advert spam.']);
        $result = (new TopicMatchingRules)->match($article, ['related_terms' => [], 'required_groups' => [], 'excluded_terms' => ['advert']], ['geo']);
        $this->assertTrue($result['matched']);
        $article->keywords = 'advert';
        $excluded = (new TopicMatchingRules)->match($article, ['related_terms' => [], 'required_groups' => [], 'excluded_terms' => ['advert']], ['geo']);
        $this->assertFalse($excluded['matched']);
        $this->assertSame('excluded_term', $excluded['reason']);
    }

    public function test_layout_and_display_order_publication_keep_frozen_content_date(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $service->publish($topic, 1);
        $original = $service->publicView($topic)['modified_at'];
        $this->travel(3)->hours();
        $saved = $service->save($topic, ['template_key' => 'guide', 'display_order' => -1], 1);
        $this->assertSame($topic->draft_content_composed_at->toIso8601String(), $saved->draft_content_composed_at->toIso8601String());
        $service->publish($topic, 2);
        $modified = $service->publicView($topic)['modified_at'];
        $this->assertSame($original, $modified);
        $this->assertSame($modified, app(TopicReadModel::class)->detailSchema($service->publicView($topic), 'Review', 'http://localhost')['@graph'][0]['dateModified']);
    }

    public function test_invalidated_core_intro_pauses_detail_list_sitemap_and_article_backlinks_until_new_verified_revision(): void
    {
        $service = app(TopicService::class);
        $sources = $this->sources();
        $topic = $this->topic($sources);
        $service->publish($topic, 1);
        $sources[0]->update(['content' => 'Changed evidence no longer supporting interpretation']);
        $view = $service->publicView($topic);
        $this->assertNull($view);
        $this->assertSame('', $service->previewView($topic)['intro']);
        $this->get('/topics/'.$topic->slug)->assertNotFound();
        $this->assertTrue(app(TopicReadModel::class)->all('primary')->isEmpty());
        $this->assertTrue(app(TopicReadModel::class)->related('primary', $sources[1]->id)->isEmpty());
        $this->get('/sitemap.txt')->assertDontSee('/topics/'.$topic->slug, false);
        $service->save($topic, ['intro' => 'Rechecked core interpretation.', 'source_hashes' => collect($sources)->mapWithKeys(fn ($a) => [$a->id => TopicService::contentHash($a->fresh())])->all()], 1);
        $service->publish($topic, 2);
        $this->get('/topics/'.$topic->slug)->assertOk();
        $this->get('/sitemap.txt')->assertSee('/topics/'.$topic->slug, false);
    }

    public function test_withdraw_request_key_binds_expected_revision_and_preserves_later_publication_on_original_replay(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $first = $service->publish($topic, 1);
        $service->withdraw($topic, expectedRevisionId: $first->id, requestId: 'same-withdraw-key');
        $service->save($topic, ['intro' => 'Updated sourced interpretation.'], 1);
        $second = $service->publish($topic, 2);
        $this->assertSame($second->id, $service->withdraw($topic, expectedRevisionId: $first->id, requestId: 'same-withdraw-key')->public_revision_id);
        try {
            $service->withdraw($topic, expectedRevisionId: $second->id, requestId: 'same-withdraw-key');
            $this->fail('A withdrawal key cannot be reused for a different public revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_id', $exception->errors());
        }
        $this->assertSame($second->id, $topic->fresh()->public_revision_id);
    }

    public function test_content_date_tracks_seo_freshness_and_score_changes_while_composition_date_tracks_text(): void
    {
        $service = app(TopicService::class);
        $sources = $this->sources();
        $topic = $this->topic($sources);
        $service->publish($topic, 1);
        $compositionDate = $topic->draft_content_composed_at->toIso8601String();
        $modifiedAt = $service->publicView($topic)['modified_at'];
        foreach ([['seo' => ['description' => 'Actual source reading guide.']], ['freshness' => ['mode' => 'composed_at']], ['score' => ['enabled' => true, 'source' => 'Editorial review', 'name' => 'Topic quality', 'type' => 'editorial', 'dimensions' => [['name' => 'Evidence', 'score' => 8.5]], 'weights' => [1], 'total' => 8.5, 'evidence' => [['text' => 'Distinct sources support this guide.', 'article_ids' => [$sources[0]->id]]]]]] as $change) {
            $this->travel(1)->hour();
            $topic = $service->save($topic, $change, $topic->fresh()->draft_version);
            $service->publish($topic, $topic->draft_version);
            $view = $service->publicView($topic);
            $this->assertNotSame($modifiedAt, $view['modified_at']);
            $this->assertSame($compositionDate, $topic->draft_content_composed_at->toIso8601String());
            $modifiedAt = $view['modified_at'];
        }
        $this->assertSame(8.5, (float) $view['score']['total']);
    }

    public function test_first_publication_date_is_not_earlier_than_actual_publication_when_draft_was_written_days_before(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $this->travel(4)->days();
        $service->publish($topic, 1);
        $view = $service->publicView($topic);
        $this->assertSame($view['first_published_at'], $view['modified_at']);
    }

    public function test_expired_optional_score_hides_without_pausing_a_source_valid_topic(): void
    {
        $service = app(TopicService::class);
        $sources = $this->sources();
        $topic = $this->topic($sources);
        $topic = $service->save($topic, ['score' => ['enabled' => true, 'source' => 'Editorial review', 'name' => 'Topic quality', 'type' => 'editorial', 'dimensions' => [['name' => 'Evidence', 'score' => 8.5]], 'weights' => [1], 'total' => 8.5, 'evidence' => [['text' => 'Distinct sources support this guide.', 'article_ids' => [$sources[0]->id]]]]], 1);
        $service->publish($topic, 2);
        $this->assertNotNull($service->publicView($topic)['score']);
        $this->travel(91)->days();
        $view = $service->publicView($topic);
        $this->assertNotNull($view);
        $this->assertNull($view['score']);
        $this->assertSame('Core interpretation based on selected sources.', $view['intro']);
        $this->get('/topics/'.$topic->slug)->assertOk();
    }

    public function test_legacy_withdrawal_records_replay_original_input_and_reject_a_different_expected_revision(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $first = $service->publish($topic, 1);
        $service->withdraw($topic, expectedRevisionId: $first->id);
        $topic->fresh()->update(['withdrawal_requests' => ['legacy-request' => $first->id]]);
        $service->save($topic, ['intro' => 'Updated sourced interpretation.'], 1);
        $second = $service->publish($topic, 2);
        $this->assertSame($second->id, $service->withdraw($topic, expectedRevisionId: $first->id, requestId: 'legacy-request')->public_revision_id);
        try {
            $service->withdraw($topic, expectedRevisionId: $second->id, requestId: 'legacy-request');
            $this->fail('Legacy withdrawal receipt must reject changed expected revision.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertArrayHasKey('request_id', $exception->errors());
        }
        $this->assertSame($second->id, $topic->fresh()->public_revision_id);
    }

    public function test_same_title_different_declared_scopes_can_be_created_while_equivalent_scope_is_rejected(): void
    {
        $service = app(TopicService::class);
        $first = $service->create('primary', ['title' => 'Scoped guide', 'summary' => ['scope' => ' 初次使用 用户 ']]);
        $second = $service->create('primary', ['title' => 'Scoped guide', 'summary' => ['scope' => '管理员操作']]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->normalized_title_key, $second->normalized_title_key);
        try {
            $service->create('primary', ['title' => 'Scoped guide', 'summary' => ['scope' => '初次使用  用户']]);
            $this->fail('Equivalent declared scopes must reject duplicate topics.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
        try {
            $service->save($second, ['summary' => ['scope' => '初次使用 用户']], 1);
            $this->fail('Editing scope must retain the duplicate topic constraint.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
        $this->assertSame('管理员操作', $second->fresh()->draft_payload['summary']['scope']);
        $this->assertSame(1, $second->fresh()->draft_version);
    }

    public function test_topic_key_keeps_default_compatibility_and_ignores_composition_and_review_dates(): void
    {
        $payloads = TopicPayload::class;
        $this->assertSame($payloads::titleKey('Guide'), $payloads::topicKey('Guide'));
        $first = ['summary' => ['scope' => 'Operators'], 'freshness' => ['mode' => 'composed_at', 'composed_at' => '2026-09-01']];
        $second = ['summary' => ['scope' => 'Operators'], 'freshness' => ['mode' => 'composed_at', 'composed_at' => '2026-10-01']];
        $this->assertSame($payloads::topicKey('Guide', $first), $payloads::topicKey('Guide', $second));
        $first['freshness'] = ['mode' => 'as_of', 'coverage_note' => 'v2 product scope', 'last_verified_at' => '2026-09-01'];
        $second['freshness'] = ['mode' => 'as_of', 'coverage_note' => 'v2 product scope', 'last_verified_at' => '2026-10-01'];
        $this->assertSame($payloads::topicKey('Guide', $first), $payloads::topicKey('Guide', $second));
        $second['freshness']['coverage_note'] = 'v3 product scope';
        $this->assertNotSame($payloads::topicKey('Guide', $first), $payloads::topicKey('Guide', $second));
    }

    public function test_legacy_title_only_key_is_migrated_for_the_same_site_without_changing_content_or_public_dates(): void
    {
        $service = app(TopicService::class);
        $sources = $this->sources();
        $topic = $service->create('primary', ['title' => 'Legacy scoped guide', 'intro' => 'Scoped source interpretation.', 'summary' => ['scope' => 'Operators'], 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        $service->publish($topic, 1);
        DB::table('topics')->where('id', $topic->id)->update(['normalized_title_key' => TopicPayload::titleKey('Legacy scoped guide')]);
        $prior = $topic->fresh()->getAttributes();
        $this->travel(1)->day();
        $other = $service->create('primary', ['title' => 'Legacy scoped guide', 'summary' => ['scope' => 'Administrators']]);
        $this->assertNotSame($topic->id, $other->id);
        $after = $topic->fresh()->getAttributes();
        foreach (['updated_at', 'draft_payload', 'draft_version', 'public_revision_id', 'first_published_at', 'published_at'] as $field) {
            $this->assertSame($prior[$field], $after[$field]);
        }
        $this->assertNotSame($prior['normalized_title_key'], $after['normalized_title_key']);
    }

    public function test_manual_approval_persists_revision_review_history_and_exposes_only_public_metadata(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $revision = $service->publish($topic, 1, reviewRequired: true);
        $this->travel(1)->hour();
        $reviewedAt = now()->toIso8601String();
        $service->approve($topic, expectedRevisionId: $revision->id);
        $topic = $topic->fresh();
        $this->assertSame($reviewedAt, $topic->approved_at?->toIso8601String());
        $this->assertSame(['method' => 'editorial', 'reviewed_at' => $reviewedAt, 'actor_id' => null], $topic->review_records[$revision->id]);
        $info = $service->publicView($topic)['review_info'];
        $this->assertSame(['method' => 'editorial', 'label' => '人工审核', 'reviewed_at' => $reviewedAt], $info);
        $this->assertArrayNotHasKey('actor_id', $info);
        $this->assertArrayNotHasKey('review_records', $service->publicView($topic));
    }

    public function test_new_pending_draft_keeps_old_public_revision_review_and_unreviewed_activation_omits_it(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $old = $service->publish($topic, 1, reviewRequired: true);
        $service->approve($topic, expectedRevisionId: $old->id);
        $oldInfo = $service->publicView($topic)['review_info'];
        $this->travel(1)->hour();
        $topic = $service->save($topic, ['intro' => 'New unreviewed source interpretation.'], 1);
        $new = $service->publish($topic, 2, reviewRequired: true);
        $this->assertSame($oldInfo, $service->publicView($topic)['review_info']);
        $this->assertArrayNotHasKey($new->id, $topic->fresh()->review_records);
        $service->publish($topic, 2);
        $this->assertNull($service->publicView($topic)['review_info']);
        $this->assertNull($topic->fresh()->approved_at);
        $this->assertSame($oldInfo['reviewed_at'], $topic->fresh()->review_records[$old->id]['reviewed_at']);
    }

    public function test_automatic_publication_and_unknown_legacy_approval_do_not_invent_manual_review(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $service->publish($topic, 1, requestId: 'build:automatic-publication');
        $this->assertNull($service->publicView($topic)['review_info']);
        $this->assertEmpty($topic->fresh()->review_records);
        $topic->fresh()->update(['approved_at' => now()]);
        $this->assertNull($service->publicView($topic)['review_info']);
    }

    public function test_scheduled_publication_preserves_the_actual_approval_time_instead_of_rescheduling_review(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->sources());
        $owner = Admin::query()->create(['username' => 'review-owner', 'password' => 'not-used', 'role' => 'admin']);
        $task = Task::query()->create(['name' => 'Deferred review task', 'content_type' => 'topic', 'target_site_key' => 'primary']);
        $topic->update(['task_id' => $task->id]);
        TopicBuildRun::query()->create(['request_key' => 'domain-deferred-review', 'site_key' => 'primary', 'topic_id' => $topic->id, 'task_id' => $task->id, 'expected_version' => 1, 'owner_admin_id' => $owner->id, 'input' => ['defer_publication' => true], 'identity' => [], 'status' => 'completed', 'phase' => 'finished']);
        $revision = $service->publish($topic, 1, $owner->id, true);
        $service->approve($topic, $owner->id, $revision->id);
        $reviewedAt = $topic->fresh()->approved_at->toIso8601String();
        $this->travel(2)->hours();
        $service->publishApproved($topic, $revision->id, $owner->id);
        $this->assertSame($reviewedAt, $topic->fresh()->approved_at?->toIso8601String());
        $this->assertSame($reviewedAt, $service->publicView($topic)['review_info']['reviewed_at']);
        $this->assertSame($owner->id, $topic->fresh()->review_records[$revision->id]['actor_id']);
        $this->assertArrayNotHasKey('actor_id', $service->publicView($topic)['review_info']);
    }
}
