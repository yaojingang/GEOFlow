<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\Topics\TopicFreshnessService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TopicFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T04:00:00Z'));
    }

    public function test_composition_title_uses_actual_content_time_and_is_frozen_independently_of_calendar_and_working_draft(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T04:00:00Z'));
        $topic = $this->topic(['mode' => 'composed_at']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T04:00:00Z'));
        $service = app(TopicService::class);
        $topic = $service->save($topic, ['freshness' => ['mode' => 'composed_at', 'year' => 2027]], 1);
        $revision = $service->publish($topic, 2);
        $this->assertSame('2026年9月整理：Product guide', $service->publicView($topic)['title']);
        $this->assertSame('2026-09-30T04:00:00+00:00', $revision->freshness_snapshot_json['composed_at']);
        $this->assertSame($revision->freshness_snapshot_json, $revision->payload['freshness_snapshot_json']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T04:00:00Z'));
        $topic = $service->save($topic, ['intro' => 'A real content revision.'], 2);
        $this->assertSame('2026年10月整理：Product guide', $service->previewView($topic)['title']);
        $this->assertSame('2026年9月整理：Product guide', $service->publicView($topic)['title']);
        $this->assertSame('evergreen', $service->publicView($topic)['freshness_status']);
        $service->publish($topic, 3);
        $this->assertSame('2026年10月整理：Product guide', $service->publicView($topic)['title']);
        $this->assertSame($topic->slug, $topic->fresh()->slug);
    }

    public function test_verifiable_content_description_can_publish_without_claiming_completed_verification(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'evergreen'], ['seo' => ['description' => '梳理可核验的 GEO 内容建设路径，提供可复核的引用。']]);

        $revision = $service->publish($topic, 1);

        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
        $service->save($topic, ['seo' => ['description' => '已核验的 GEO 内容建设路径。']], 1);
        $this->assertFieldError('seo.description', fn () => $service->publish($topic, 2));
    }

    public function test_a_user_supplied_composition_date_is_rejected(): void
    {
        $this->assertFieldError('freshness', fn () => app(TopicService::class)->create('primary', ['title' => 'Guide', 'freshness' => ['mode' => 'composed_at', 'composed_at' => '2027-01-01']]));
    }

    public function test_annual_title_requires_year_coverage_and_does_not_roll_forward_on_new_year(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'annual', 'year' => 2026, 'coverage_note' => 'Two sources cover the 2026 edition.', 'effective_to' => '2026-12-31']);
        $revision = $service->publish($topic, 1);
        $this->assertSame('2026年 Product guide', $service->publicView($topic)['title']);
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:00:00Z'));
        $view = $service->publicView($topic);
        $this->assertSame('expired', $view['freshness_status']);
        $this->assertSame('Product guide', $view['title']);
        $this->assertSame(2026, $revision->fresh()->freshness_snapshot_json['year']);
        $this->assertSame('2026-12-31T16:00:00+00:00', $view['modified_at']);
    }

    public function test_an_annual_cover_note_without_matching_source_year_cannot_publish(): void
    {
        $topic = $this->topic(['mode' => 'annual', 'year' => 2025, 'coverage_note' => 'Claimed 2025 edition.', 'effective_to' => '2026-12-31']);
        $this->assertSame('unknown', app(TopicService::class)->previewView($topic)['freshness_status']);
        $this->assertFieldError('freshness.effective_to', fn () => app(TopicService::class)->publish($topic, 1));
        $topic = app(TopicService::class)->save($topic, ['freshness' => ['mode' => 'annual', 'year' => 2027, 'coverage_note' => 'Claimed future edition.', 'effective_to' => '2027-12-31']], 1);
        $this->assertFieldError('freshness.coverage_note', fn () => app(TopicService::class)->publish($topic, 2));
    }

    public function test_as_of_and_version_titles_keep_literal_verified_values_in_the_public_snapshot(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'as_of', 'coverage_note' => 'Verified product scope.', 'last_verified_at' => '2026-09-30', 'effective_to' => '2026-12-31']);
        $service->publish($topic, 1);
        $this->assertSame('截至2026年9月的Product guide', $service->publicView($topic)['title']);
        $topic = $service->save($topic, ['freshness' => ['mode' => 'version', 'version' => 'v2.5', 'coverage_note' => 'Sources cover v2.5.', 'effective_to' => '2026-12-31']], 1);
        $this->assertSame('截至2026年9月的Product guide', $service->publicView($topic)['title']);
        $service->publish($topic, 2);
        $this->assertSame('Product guide（v2.5版）', $service->publicView($topic)['title']);
    }

    public function test_future_verification_and_missing_version_are_field_errors(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'as_of', 'last_verified_at' => '2026-10-02', 'coverage_note' => 'Product scope.', 'effective_to' => '2026-12-31']);
        $this->assertFieldError('freshness.last_verified_at', fn () => $service->publish($topic, 1));
        $topic = $service->save($topic, ['freshness' => ['mode' => 'version', 'coverage_note' => 'Product scope.', 'effective_to' => '2026-12-31']], 1);
        $this->assertFieldError('freshness.version', fn () => $service->publish($topic, 2));
    }

    public function test_event_guide_can_be_public_before_the_event_and_uses_exact_half_open_utc_boundaries(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->event());
        $revision = $service->publish($topic, 1);
        $this->assertSame('2026-10-04T16:00:00+00:00', $revision->freshness_snapshot_json['effective_from']);
        $this->assertSame('2026-10-06T16:00:00+00:00', $revision->freshness_snapshot_json['effective_to']);
        $this->assertSame('scheduled', $service->publicView($topic)['freshness_status']);
        $this->assertSame('开始时间未到', $service->publicView($topic)['event_hint']);
        $this->travelTo(CarbonImmutable::parse('2026-10-04T16:00:00Z'));
        $this->assertSame('active', $service->publicView($topic)['freshness_status']);
        $this->assertSame('活动时段内', $service->publicView($topic)['event_hint']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06T15:59:59Z'));
        $this->assertSame('active', $service->publicView($topic)['freshness_status']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06T16:00:00Z'));
        $this->assertSame('expired', $service->publicView($topic)['freshness_status']);
        $this->assertSame('结束时间已过', $service->publicView($topic)['event_hint']);
    }

    public function test_expiration_suppresses_current_core_claims_and_keeps_clearly_historical_source_links_with_queue_stopped(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->event());
        $topic = $service->save($topic, ['score' => ['enabled' => true, 'type' => 'editorial', 'source' => 'Editorial assessment', 'name' => 'Verified quality', 'dimensions' => [['name' => 'completeness', 'score' => 8]], 'weights' => [1], 'total' => 8, 'evidence' => [['text' => 'Verified source evidence.', 'article_ids' => [$topic->draft_payload['articles'][0]['article_id']]]], 'rated_at' => now()->toIso8601String(), 'valid_until' => '2026-12-31']], 1);
        $revision = $service->publish($topic, 2);
        $this->assertNotNull($service->publicView($topic)['score']);
        $this->assertSame('2026-10-06T16:00:00+00:00', $service->publicView($topic)['score']['expires_at']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06T16:00:00Z'));
        $view = $service->publicView($topic);
        $this->assertSame('', $view['intro']);
        $this->assertSame([], $view['summary']['facts']);
        $this->assertSame([], $view['faq']);
        $this->assertSame([], $view['basic_info']);
        $this->assertNull($view['score']);
        $this->assertCount(2, $view['articles']);
        $this->assertNotEmpty($view['articles'][0]['url']);
        $this->assertSame('', $view['articles'][0]['reason']);
        $this->assertSame('expired', $view['freshness_status']);
        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('历史资料')->assertSee('结束时间已过')->assertDontSee('Current verified claim.');
    }

    public function test_missing_or_invalid_published_policy_uses_unknown_base_title_and_risk_suppression(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'evergreen']);
        $revision = $service->publish($topic, 1);
        DB::table('topic_revisions')->where('id', $revision->id)->update(['freshness_snapshot_json' => json_encode(['mode' => 'as_of', 'supported' => false, 'last_verified_at' => 'bad date'])]);
        $view = $service->publicView($topic);
        $this->assertSame('unknown', $view['freshness_status']);
        $this->assertSame('Product guide', $view['title']);
        $this->assertSame('', $view['intro']);
        $this->assertCount(2, $view['articles']);
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_timezone_date_order_and_daylight_saving_inputs_return_field_errors(array $policy, string $field): void
    {
        $this->assertFieldError($field, fn () => app(TopicFreshnessService::class)->normalize($policy));
    }

    public static function invalidDates(): array
    {
        return [
            'unknown timezone' => [['mode' => 'event', 'timezone' => 'Mars/Base'], 'freshness.timezone'],
            'bad day' => [['mode' => 'event', 'effective_from' => '2026-02-30'], 'freshness.effective_from'],
            'end before start' => [['mode' => 'event', 'effective_from' => '2026-10-05T10:00', 'effective_to' => '2026-10-05T09:00'], 'freshness.effective_to'],
            'equal exact boundaries' => [['mode' => 'event', 'effective_from' => '2026-10-05T10:00', 'effective_to' => '2026-10-05T10:00'], 'freshness.effective_to'],
            'missing daylight saving hour' => [['mode' => 'event', 'timezone' => 'America/New_York', 'effective_from' => '2026-03-08T02:30'], 'freshness.effective_from'],
            'ambiguous daylight saving hour' => [['mode' => 'event', 'timezone' => 'America/New_York', 'effective_from' => '2026-11-01T01:30'], 'freshness.effective_from'],
            'unknown mode' => [['mode' => 'invented'], 'freshness.mode'],
            'forged literal year template' => [['mode' => 'annual', 'title_template' => '2027年 {title}'], 'freshness.title_template'],
            'unsupported comparison' => [['mode' => 'evergreen', 'title_template' => '最新{title}'], 'freshness.title_template'],
        ];
    }

    public function test_explicit_offset_resolves_daylight_saving_ambiguity_without_rewriting_input(): void
    {
        $service = app(TopicFreshnessService::class);
        $policy = $service->normalize(['mode' => 'event', 'timezone' => 'America/New_York', 'effective_from' => '2026-11-01T01:30:00-04:00', 'effective_to' => '2026-11-01', 'coverage_note' => 'Verified event calendar.']);
        $snapshot = $service->snapshot($policy, new Collection([$this->article(), $this->article()]));
        $this->assertSame('2026-11-01T01:30:00-04:00', $policy['effective_from']);
        $this->assertSame('2026-11-01T05:30:00+00:00', $snapshot['effective_from']);
        $this->assertSame('2026-11-02T05:00:00+00:00', $snapshot['effective_to']);
    }

    public function test_rolling_requires_real_review_public_update_record_and_next_review_and_expires_at_the_earliest_review_boundary(): void
    {
        $service = app(TopicService::class);
        $policy = ['mode' => 'rolling', 'coverage_note' => 'Reviewed product sources.', 'last_verified_at' => '2026-07-04T04:00:00Z', 'next_review_at' => '2026-10-20', 'public_updates' => '2026-09-30: revised product scope.'];
        $topic = $this->topic($policy);
        $revision = $service->publish($topic, 1);
        $this->assertSame('Product guide：持续更新', $service->publicView($topic)['title']);
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('公开更新记录')->assertSee('revised product scope');
        $this->travelTo(CarbonImmutable::parse('2026-10-02T04:00:00Z'));
        $this->assertSame('expired', $service->publicView($topic)['freshness_status']);
        $this->assertSame('Product guide', $service->publicView($topic)['title']);
        $this->assertSame('2026-10-02T04:00:00+00:00', $service->publicView($topic)['modified_at']);
        $topic = $service->save($topic, ['freshness' => array_replace($policy, ['last_verified_at' => now()->toIso8601String(), 'public_updates' => ''])], 1);
        $this->assertFieldError('freshness.public_updates', fn () => $service->publish($topic, 2));
    }

    public function test_review_deadline_expiration_has_priority_over_a_future_effective_start(): void
    {
        $freshness = app(TopicFreshnessService::class);
        $state = $freshness->evaluate(['mode' => 'rolling', 'supported' => true, 'coverage_note' => 'Reviewed sources.', 'public_updates' => '2026-09-01: reviewed.', 'last_verified_at' => '2026-09-01T00:00:00Z', 'next_review_at' => '2026-09-30T00:00:00Z', 'effective_from' => '2026-11-01T00:00:00Z']);
        $this->assertSame('expired', $state['freshness_status']);
        $state = $freshness->evaluate(['mode' => 'event', 'supported' => false, 'effective_to' => '2026-09-30T00:00:00Z']);
        $this->assertSame('unknown', $state['freshness_status']);
    }

    public function test_public_from_blocks_manual_publication_but_keeps_the_working_draft(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(array_replace($this->event(), ['public_from' => '2026-10-02T04:00:00Z']));
        $this->assertFieldError('freshness.public_from', fn () => $service->publish($topic, 1));
        $this->assertFieldError('freshness.public_from', fn () => $service->publish($topic, 1, reviewRequired: true));
        $this->assertNull($topic->fresh()->public_revision_id);
        $this->assertSame('Current verified claim.', $topic->draft_payload['intro']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T04:00:00Z'));
        $service->publish($topic, 1);
        $this->assertSame('scheduled', $service->publicView($topic)['freshness_status']);
    }

    public function test_auto_task_publication_waits_for_frozen_public_from_and_rejects_changed_content_version(): void
    {
        $service = app(TopicService::class);
        $task = Task::query()->create(['name' => 'Timed topic', 'content_type' => 'topic', 'target_site_key' => 'primary', 'topic_config_version' => 1, 'status' => 'active', 'schedule_enabled' => 1, 'topic_settings' => ['after' => 'auto_publish'], 'topic_limit' => 10, 'publish_interval' => 3600]);
        $topic = $this->topic(array_replace($this->event(), ['public_from' => '2026-10-02T04:00:00Z']), ['task_id' => $task->id]);
        $run = TopicBuildRun::query()->create(['owner_admin_id' => $this->actor()->id, 'identity' => [], 'request_key' => 'time-bound-auto', 'site_key' => 'primary', 'task_id' => $task->id, 'topic_id' => $topic->id, 'config_version' => 1, 'expected_version' => 1, 'expected_control_version' => 0, 'status' => 'completed', 'phase' => 'finished', 'input' => ['after' => 'auto_publish', 'defer_publication' => true]]);
        $this->assertNull(app(TopicTaskService::class)->dueRun($task));
        $this->travelTo(CarbonImmutable::parse('2026-10-02T04:00:00Z'));
        $this->assertSame($run->id, app(TopicTaskService::class)->dueRun($task)->id);
        $service->save($topic, ['intro' => 'Human changed the planned revision.'], 1);
        $this->assertNull(app(TopicTaskService::class)->dueRun($task));
    }

    public function test_approved_task_version_uses_its_frozen_publication_time(): void
    {
        $service = app(TopicService::class);
        $actor = Admin::query()->firstOrCreate(['username' => 'freshness_editor'], ['password' => 'password', 'email' => 'freshness@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $task = Task::query()->create(['name' => 'Reviewed timed topic', 'content_type' => 'topic', 'target_site_key' => 'primary', 'topic_config_version' => 1, 'status' => 'active', 'schedule_enabled' => 1, 'topic_settings' => ['after' => 'review_then_publish'], 'topic_limit' => 10, 'publish_interval' => 3600]);
        $topic = $this->topic(array_replace($this->event(), ['public_from' => '2026-10-02T04:00:00Z']), ['task_id' => $task->id]);
        $run = TopicBuildRun::query()->create(['owner_admin_id' => $actor->id, 'identity' => [], 'request_key' => 'time-bound-reviewed', 'site_key' => 'primary', 'task_id' => $task->id, 'topic_id' => $topic->id, 'config_version' => 1, 'expected_version' => 1, 'expected_control_version' => 0, 'status' => 'completed', 'phase' => 'finished', 'input' => ['after' => 'review_then_publish', 'defer_publication' => true]]);
        $revision = $service->publish($topic, 1, $actor->id, true, 'build:'.$run->id);
        $service->approve($topic, $actor->id, $revision->id);
        $this->assertNull(app(TopicTaskService::class)->dueRun($task));
        $this->assertFieldError('freshness.public_from', fn () => $service->publishApproved($topic, $revision->id, $actor->id));
        $this->travelTo(CarbonImmutable::parse('2026-10-02T04:00:00Z'));
        $this->assertSame($run->id, app(TopicTaskService::class)->dueRun($task)->id);
        $service->publishApproved($topic, $revision->id, $actor->id);
        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
    }

    public function test_five_minute_checker_records_real_transitions_and_expiration_reminders_without_changing_public_pointer_or_modified_time(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic($this->event());
        $draft = $this->topic(array_replace($this->event(), ['public_from' => '2026-10-02']), ['title' => 'Manual future draft']);
        $revision = $service->publish($topic, 1);
        $modified = $topic->fresh()->updated_at;
        $this->travelTo(CarbonImmutable::parse('2026-10-04T16:00:00Z'));
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame('active', $topic->fresh()->freshness_status);
        $this->travelTo(CarbonImmutable::parse('2026-10-06T16:00:00Z'));
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame('expired', $topic->fresh()->freshness_status);
        $this->assertDatabaseHas('topic_freshness_changes', ['topic_revision_id' => $revision->id, 'previous_status' => 'active', 'status' => 'expired', 'event_hint' => '结束时间已过']);
        $this->assertNotNull(DB::table('topic_freshness_changes')->where('status', 'expired')->value('reminder_at'));
        $this->assertSame(3, DB::table('topic_freshness_changes')->where('topic_id', $topic->id)->count());
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame(3, DB::table('topic_freshness_changes')->where('topic_id', $topic->id)->count());
        $this->assertTrue($modified->equalTo($topic->fresh()->updated_at));
        $this->assertSame($revision->id, $topic->fresh()->public_revision_id);
        $this->assertNull($draft->fresh()->public_revision_id);
    }

    public function test_disabled_channel_blocks_manual_publication_and_task_work_then_can_resume(): void
    {
        $service = app(TopicService::class);
        $topic = $this->topic(['mode' => 'evergreen']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['enabled' => false])]);
        $this->assertFieldError('site', fn () => $service->publish($topic, 1));
        $this->assertNull($topic->fresh()->public_revision_id);
        $task = Task::query()->create(['name' => 'Disabled topic task', 'content_type' => 'topic', 'target_site_key' => 'primary', 'topic_limit' => 10, 'topic_settings' => ['after' => 'auto_publish']]);
        $this->assertFalse(app(TopicTaskService::class)->hasWork($task));
        $this->assertNull(app(TopicTaskService::class)->dueRun($task));
        $this->assertFalse(app(TopicTaskService::class)->readiness($task)['can_activate']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['enabled' => true])]);
        $service->publish($topic, 1);
        $this->assertNotNull($service->publicView($topic));
    }

    public function test_checker_uses_utc_indexes_at_exact_intraday_boundaries(): void
    {
        $topic = $this->topic(array_replace($this->event(), ['effective_from' => '2026-10-01T13:00', 'effective_to' => '2026-10-01T14:00']));
        app(TopicService::class)->publish($topic, 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T04:59:59Z'));
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame('scheduled', $topic->fresh()->freshness_status);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T05:00:00Z'));
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame('active', $topic->fresh()->freshness_status);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->artisan('geoflow:check-topic-temporal-state')->assertSuccessful();
        $this->assertSame('expired', $topic->fresh()->freshness_status);
    }

    public function test_a_malformed_frozen_date_stays_unknown_and_public_rendering_does_not_fail(): void
    {
        $topic = $this->topic(['mode' => 'as_of', 'coverage_note' => 'Reviewed scope.', 'last_verified_at' => '2026-09-30', 'effective_to' => '2026-12-31']);
        $revision = app(TopicService::class)->publish($topic, 1);
        $snapshot = array_replace($revision->freshness_snapshot_json, ['last_verified_at' => 'malformed']);
        DB::table('topic_revisions')->where('id', $revision->id)->update(['freshness_snapshot_json' => json_encode($snapshot)]);
        $this->assertSame('unknown', app(TopicService::class)->publicView($topic)['freshness_status']);
        $this->get('/topics/'.$topic->slug)->assertOk()->assertDontSee('截至2026年9月')->assertDontSee('Current verified claim.');
    }

    public function test_advanced_freshness_fields_render_in_editor_and_unknown_modes_return_field_errors(): void
    {
        $this->actingAs($this->actor(), 'admin')->get(route('admin.topics.create', ['site' => 'primary']))->assertOk()->assertSee('freshness[effective_from]', false)->assertSee('freshness[public_from]', false)->assertSee('freshness[timezone]', false)->assertSee('实际整理日期');
        $this->post(route('admin.topics.store'), ['site' => 'primary', 'title' => 'Mode error', 'template_key' => 'default', 'freshness' => ['mode' => 'invented']])->assertRedirect()->assertSessionHasErrors('freshness.mode');
    }

    public function test_review_evergreen_cannot_claim_continuous_updates_without_review(): void
    {
        $this->assertFieldError('freshness.title_template', fn () => $this->topic(['mode' => 'evergreen', 'title_template' => '{title}：持续更新']));
        $this->assertFieldError('freshness.title_template', fn () => $this->topic(['mode' => 'composed_at', 'title_template' => '截至{composed_at}的{title}']));
    }

    public function test_review_legacy_end_day_honors_the_selected_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T20:00:00Z'));
        $topic = $this->topic(['mode' => 'event', 'timezone' => 'America/New_York', 'coverage_note' => 'Reviewed local event window.', 'effective_from' => '2026-09-30', 'valid_until' => '2026-10-01']);
        app(TopicService::class)->publish($topic, 1);
        $this->assertSame('active', app(TopicService::class)->publicView($topic)['freshness_status']);
    }

    public function test_review_score_end_day_honors_the_selected_timezone(): void
    {
        $topic = $this->topic(['mode' => 'evergreen', 'timezone' => 'America/New_York']);
        $topic = app(TopicService::class)->save($topic, ['score' => ['enabled' => true, 'type' => 'editorial', 'source' => 'Editorial assessment', 'name' => 'Verified quality', 'dimensions' => [['name' => 'completeness', 'score' => 8]], 'weights' => [1], 'total' => 8, 'evidence' => [['text' => 'Verified source evidence.', 'article_ids' => [$topic->draft_payload['articles'][0]['article_id']]]], 'rated_at' => now()->toIso8601String(), 'valid_until' => '2026-10-01']], 1);
        app(TopicService::class)->publish($topic, 2);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T00:00:00Z'));
        $this->assertNotNull(app(TopicService::class)->publicView($topic)['score'], 'The local New York end day has not expired yet');
    }

    public function test_review_explicit_offset_does_not_silently_normalize_invalid_seconds(): void
    {
        $this->assertFieldError('freshness.effective_from', fn () => app(TopicFreshnessService::class)->normalize(['mode' => 'event', 'effective_from' => '2026-10-01T12:59:60Z']));
    }

    private function actor(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'freshness_editor'], ['password' => 'password', 'email' => 'freshness@example.test', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function event(): array
    {
        return ['mode' => 'event', 'timezone' => 'Asia/Shanghai', 'coverage_note' => 'Verified calendar, location and planned event status from the cited sources.', 'effective_from' => '2026-10-05', 'effective_to' => '2026-10-06', 'public_from' => '2026-09-30'];
    }

    private function topic(array $freshness, array $overrides = []): Topic
    {
        $sources = [$this->article(), $this->article()];

        return app(TopicService::class)->create('primary', array_replace(['title' => 'Product guide', 'intro' => 'Current verified claim.', 'freshness' => $freshness, 'summary' => ['one_sentence' => 'Current answer.', 'facts' => [['text' => 'Current fact.', 'article_ids' => [$sources[0]->id], 'evidence' => [$this->sourceEvidence($sources[0])]]]], 'basic_info' => [['label' => 'Current price', 'value' => '10 units']], 'faq' => [['question' => 'Current version?', 'answer' => 'Verified version.', 'article_ids' => [$sources[0]->id]]], 'articles' => array_map(fn ($a) => ['article_id' => $a->id, 'reason' => 'Current selection reason.'], $sources)], $overrides));
    }

    private function article(): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'freshness'], ['name' => 'Freshness']);
        $author = Author::query()->firstOrCreate(['email' => 'freshness_source@example.test'], ['name' => 'Source Editor']);
        $key = (string) Str::uuid();

        return Article::query()->create(['title' => 'Product source '.$key, 'slug' => 'source-'.$key, 'content' => 'Unique verified product source '.$key, 'excerpt' => 'Source excerpt.', 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'category_id' => $category->id, 'author_id' => $author->id]);
    }

    private function assertFieldError(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation error for '.$field);
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey($field, $failure->errors());
        }
    }

    private function sourceEvidence(Article $article): array
    {
        $text = mb_substr((string) $article->content, 0, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
    }
}
