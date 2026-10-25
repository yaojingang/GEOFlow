<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Jobs\CollectAiVisibilityKeywordJob;
use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityCompetitorDetection;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\SiteSetting;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorReportService;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class AiVisibilityCompetitorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_combines_every_provider_answer_and_counts_overlapping_aliases_once(): void
    {
        AiVisibilityCompetitor::query()->create(['name' => 'Acme', 'aliases' => ['Acme Labs', 'ACME'], 'is_active' => true]);
        $this->sample('No named brand here.');
        $this->sample('ACME Labs and Acme.', AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES);
        $this->sample('acme', AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM);
        $report = app(AiVisibilityCompetitorReportService::class)->stats();
        $this->assertSame(1, $report['total_samples']);
        $this->assertSame(3, $report['competitors'][0]['mentions']);
        $this->assertSame(1, $report['competitors'][0]['samples_mentioned']);
        $this->assertSame(100.0, $report['competitors'][0]['mention_rate']);
    }

    public function test_detection_uses_configured_model_and_preserves_manual_competitor(): void
    {
        Http::preventStrayRequests();
        MarkdownContentWriterAgent::fake(['[{"name":"Acme"}]'])->preventStrayPrompts();
        $model = $this->model(42);
        $competitor = AiVisibilityCompetitor::query()->create(['name' => 'Acme', 'aliases' => ['Acme Labs'], 'is_active' => false, 'source' => 'manual']);
        $this->sample('Acme provides software.');
        $result = app(AiVisibilityCompetitorDetectionService::class)->detect(1);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(['Acme Labs'], $competitor->fresh()->aliases);
        $this->assertFalse($competitor->fresh()->is_active);
        $this->assertSame('manual', $competitor->fresh()->source);
        $this->assertSame(1, $model->fresh()->used_today);
        $this->assertDatabaseCount('ai_model_usage_events', 1);
    }

    #[DataProvider('invalidBindings')]
    public function test_invalid_system_bindings_never_invoke_a_model(string $change): void
    {
        Http::preventStrayRequests();
        MarkdownContentWriterAgent::fake(['[]'])->preventStrayPrompts();
        $model = $this->model();
        match ($change) {
            'private' => $model->forceFill(['access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save(),
            'archived' => $model->forceFill(['archived_at' => now()])->save(),
            'inactive-model' => $model->forceFill(['status' => 'inactive'])->save(),
            'inactive-owner' => Admin::query()->whereKey($model->owner_admin_id)->update(['status' => 'inactive']),
            'ordinary-owner' => Admin::query()->whereKey($model->owner_admin_id)->update(['role' => 'admin']),
            'missing-binding' => SiteSetting::query()->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')->delete(),
            'credential' => AiModel::query()->whereKey($model->id)->update(['api_key' => 'bad-encryption']),
        };
        $run = $this->sample('Acme');
        try {
            app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
            $this->fail('An invalid binding must fail before inference.');
        } catch (RuntimeException $exception) {
            $this->assertContains($exception->getMessage(), ['ai_model_not_accessible', 'ai_model_unavailable', 'ai_config_access_revoked']);
        }
        MarkdownContentWriterAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 0);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertSame(0, $model->fresh()->used_today);
    }

    public static function invalidBindings(): array
    {
        return array_map(static fn (string $change): array => [$change], ['private', 'archived', 'inactive-model', 'inactive-owner', 'ordinary-owner', 'missing-binding', 'credential']);
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_or_failed_responses_remain_retryable_without_false_success(string $response): void
    {
        Http::preventStrayRequests();
        $model = $this->model();
        $run = $this->sample('Acme');
        MarkdownContentWriterAgent::fake(static function () use ($response): string {
            if ($response === 'transport') {
                throw new RuntimeException('HTTP 503 private-provider-detail');
            }

            return $response;
        })->preventStrayPrompts();
        try {
            app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
            $this->fail('Invalid output must fail.');
        } catch (RuntimeException $exception) {
            $this->assertContains($exception->getMessage(), ['ai_competitor_response_invalid', 'ai_provider_request_failed']);
        }
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 0);
        $this->assertSame([$run->id], app(AiVisibilityCompetitorDetectionService::class)->pendingRunIds());
        $this->assertSame(1, $model->fresh()->used_today);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::query()->sole()->status);
        MarkdownContentWriterAgent::fake(['[]'])->preventStrayPrompts();
        app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
        $this->assertDatabaseHas('ai_visibility_competitor_detections', ['run_id' => $run->id, 'names_json' => '[]']);
    }

    public static function invalidResponses(): array
    {
        return [[''], ['not json'], ['{"name":"Acme"}'], ['[{"wrong":"Acme"}]'], ['transport']];
    }

    public function test_quota_and_owner_revocation_use_existing_metering_boundary(): void
    {
        Http::preventStrayRequests();
        $model = $this->model();
        $model->forceFill(['daily_limit' => 1, 'used_today' => 1, 'usage_date' => now()->toDateString()])->save();
        $run = $this->sample('Acme');
        MarkdownContentWriterAgent::fake(['[]'])->preventStrayPrompts();
        try {
            app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
            $this->fail('Quota must be enforced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_model_quota_exhausted', $exception->getMessage());
        }
        MarkdownContentWriterAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $model->forceFill(['used_today' => 0])->save();
        MarkdownContentWriterAgent::fake(function () use ($model): string {
            Admin::query()->whereKey($model->owner_admin_id)->update(['status' => 'inactive']);

            return '[{"name":"Acme"}]';
        })->preventStrayPrompts();
        try {
            app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
            $this->fail('Revoked output must be discarded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 0);
        $this->assertDatabaseCount('ai_visibility_competitors', 0);
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, AiModelUsageEvent::query()->sole()->status);
        $this->assertSame(1, $model->fresh()->used_today);
    }

    public function test_persistence_retry_uses_saved_output_after_binding_removal_and_preserves_known_aliases(): void
    {
        Http::preventStrayRequests();
        $model = $this->model();
        $run = $this->sample('Acme Labs');
        AiVisibilityCompetitor::query()->create(['name' => 'Acme', 'aliases' => ['Acme Labs'], 'is_active' => false, 'source' => 'manual']);
        MarkdownContentWriterAgent::fake(['[{"name":"Acme Labs"}]'])->preventStrayPrompts();
        $failOnce = true;
        AiVisibilityCompetitorDetection::creating(static function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('temporary_persistence_failure');
            }
        });
        try {
            app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
            $this->fail('The first persistence attempt must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary_persistence_failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 0);
        SiteSetting::query()->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')->delete();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
        app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 1);
        $this->assertDatabaseCount('ai_visibility_competitors', 1);
        $this->assertDatabaseCount('ai_model_usage_events', 1);
        $this->assertSame(1, $model->fresh()->used_today);
    }

    public function test_brand_exclusions_follow_site_configuration_and_internal_runs_do_not_become_samples(): void
    {
        Http::preventStrayRequests();
        $this->model();
        config()->set('geoflow.site_name', 'Our Site');
        config()->set('geoflow.site_full_name', 'Our Site Company');
        $run = $this->sample('Our Site and 多次元 and GEOFlow and Acme.');
        MarkdownContentWriterAgent::fake(['[{"name":"Our Site"},{"name":"多次元"},{"name":"GEOFlow"},{"name":"Acme"},{"name":"Invented"}]'])->preventStrayPrompts();
        $result = app(AiVisibilityCompetitorDetectionService::class)->detectRun($run->id);
        $this->assertSame(['多次元', 'GEOFlow', 'Acme'], $result);
        $this->assertSame([], app(AiVisibilityCompetitorDetectionService::class)->pendingRunIds());
        $this->assertSame(1, app(AiVisibilityCompetitorReportService::class)->stats()['total_samples']);
        $this->assertSame(1, app(AiVisibilityAnalyticsService::class)->overview()['polling']['runs']);
        $this->assertSame(1, app(AiVisibilityAnalyticsService::class)->snapshot()['sampled_runs']);
    }

    public function test_batch_queues_one_unique_job_per_keyword_and_validates_fifty_limit(): void
    {
        Queue::fake();
        $this->model();
        $admin = Admin::query()->sole();
        $library = KeywordLibrary::query()->create(['name' => 'Software']);
        $one = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'one']);
        $two = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'two']);
        $this->actingAs($admin, 'admin')->post(route('admin.analytics.ai-visibility.collect'), ['keyword_ids' => [$one->id, $two->id, $one->id]])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(CollectAiVisibilityKeywordJob::class, 2);
        Queue::assertPushed(CollectAiVisibilityKeywordJob::class, fn ($job): bool => $job->keyword === 'one' && $job->tries === 1 && $job->timeout < config('queue.connections.database.retry_after'));
        $this->post(route('admin.analytics.ai-visibility.collect'), ['keyword_ids' => [$one->id]])->assertRedirect();
        Queue::assertPushed(CollectAiVisibilityKeywordJob::class, 2);
        $this->post(route('admin.analytics.ai-visibility.collect'), ['keyword_ids' => array_fill(0, 51, $one->id)])->assertSessionHasErrors('keyword_ids');
        Queue::assertPushed(CollectAiVisibilityKeywordJob::class, 2);
    }

    public function test_keyword_job_queues_detection_separately_and_detection_failures_never_recollect(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $model = $this->model();
        MarkdownContentWriterAgent::fake(['Acme'])->preventStrayPrompts();
        $job = unserialize(serialize(new CollectAiVisibilityKeywordJob('software')));
        $job->handle(app(AiVisibilityCollectionService::class));
        Queue::assertPushed(DetectAiVisibilityCompetitorsJob::class, 1);
        $sample = AiVisibilityRun::query()->sole();
        $detectionJob = (new DetectAiVisibilityCompetitorsJob($sample->id))->withFakeQueueInteractions();
        MarkdownContentWriterAgent::fake(['invalid'])->preventStrayPrompts();
        $detectionJob->handle(app(AiVisibilityCompetitorDetectionService::class));
        $detectionJob->assertFailed();
        $this->assertSame(1, AiVisibilityRun::query()->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)->count());
        $this->assertSame(2, $model->fresh()->used_today);
        $this->assertSame(1, $job->tries);
    }

    public function test_admin_permissions_and_page_hide_unfinished_schedule_and_unsafe_urls(): void
    {
        Queue::fake();
        $this->withoutVite();
        $this->model();
        $admin = Admin::query()->sole();
        $run = $this->sample('Acme');
        foreach (['https://example.com/article', 'javascript:alert(1)'] as $url) {
            AiVisibilitySource::query()->create(['ai_visibility_run_id' => $run->id, 'source_type' => 'web', 'url' => $url, 'title' => '<script>danger</script>']);
        }
        $this->actingAs($admin, 'admin')->get(route('admin.analytics.ai-visibility'))->assertOk()
            ->assertSee('https://example.com/article', false)->assertSee('&lt;script&gt;danger&lt;/script&gt;', false)
            ->assertDontSee('href="javascript:', false)->assertDontSee('/ai-visibility/schedule', false)
            ->assertSee(__('admin.analytics.ai_visibility.collect.panel_title'));
        $ordinary = Admin::query()->create(['username' => 'viewer', 'password' => 'secret', 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($ordinary, 'admin');
        foreach (['collect', 'competitors.store', 'competitors.detect'] as $action) {
            $this->post(route('admin.analytics.ai-visibility.'.$action), [])->assertForbidden();
        }
        $this->delete(route('admin.analytics.ai-visibility.competitors.destroy', 1))->assertForbidden();
        Queue::assertNothingPushed();
        $this->get(route('admin.analytics.ai-visibility'))->assertOk()->assertDontSee(route('admin.analytics.ai-visibility.collect'), false);
    }

    public function test_failure_only_marks_the_execution_owned_by_this_detection_job(): void
    {
        $source = $this->sample('Acme');
        $owner = new DetectAiVisibilityCompetitorsJob($source->id);
        $duplicate = new DetectAiVisibilityCompetitorsJob($source->id);
        $execution = AiVisibilityRun::query()->create([
            'uuid' => $owner->executionUuid, 'parent_run_id' => $source->id,
            'keyword' => $source->keyword, 'prompt' => 'extract',
            'provider_type' => AiVisibilityRun::PROVIDER_COMPETITOR_DETECTION, 'status' => AiVisibilityRun::STATUS_RUNNING,
        ]);
        $duplicate->failed(new RuntimeException('ai_competitor_detection_outcome_unknown'));
        $this->assertSame(AiVisibilityRun::STATUS_RUNNING, $execution->fresh()->status);
        $owner->failed(new RuntimeException('worker_timeout'));
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $execution->fresh()->status);
        $this->assertSame('ai_competitor_execution_interrupted', $execution->fresh()->error_message);
        $this->assertSame([$source->id], app(AiVisibilityCompetitorDetectionService::class)->pendingRunIds());
        $this->assertSame($owner->executionUuid, unserialize(serialize($owner))->executionUuid);
        $this->assertGreaterThan(720, (new CollectAiVisibilityKeywordJob('keyword'))->timeout);
        $this->assertLessThan(930, (new CollectAiVisibilityKeywordJob('keyword'))->timeout);
    }

    public function test_retry_of_a_failed_execution_uuid_is_terminal_without_another_insert_or_charge(): void
    {
        Http::preventStrayRequests();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        $this->model();
        $source = $this->sample('Acme');
        $job = (new DetectAiVisibilityCompetitorsJob($source->id))->withFakeQueueInteractions();
        AiVisibilityRun::query()->create([
            'uuid' => $job->executionUuid, 'parent_run_id' => $source->id,
            'keyword' => $source->keyword, 'prompt' => 'extract',
            'provider_type' => AiVisibilityRun::PROVIDER_COMPETITOR_DETECTION,
            'status' => AiVisibilityRun::STATUS_FAILED, 'error_message' => 'ai_model_unavailable',
        ]);
        $job->handle(app(AiVisibilityCompetitorDetectionService::class));
        $job->assertFailed();
        $job->handle(app(AiVisibilityCompetitorDetectionService::class));
        $job->assertFailed();
        MarkdownContentWriterAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_visibility_runs', 2);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertDatabaseCount('ai_visibility_competitor_detections', 0);
    }

    private function sample(string $answer, string $provider = AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS): AiVisibilityRun
    {
        return AiVisibilityRun::query()->create(['keyword' => 'software', 'prompt' => 'software', 'provider_type' => $provider, 'status' => 'completed', 'answer_text' => $answer, 'completed_at' => now()]);
    }

    private function model(int $id = 1): AiModel
    {
        $owner = Admin::query()->create(['username' => 'system-owner', 'password' => 'secret', 'role' => 'super_admin', 'status' => 'active']);
        $model = new AiModel;
        $model->forceFill(['id' => $id, 'owner_admin_id' => $owner->id, 'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY, 'name' => 'Analysis', 'version' => 'test', 'model_id' => 'deepseek-chat', 'model_type' => 'chat', 'api_url' => 'https://api.deepseek.com', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'status' => 'active', 'daily_limit' => 20])->save();
        SiteSetting::query()->create(['setting_key' => 'ai_visibility_deepseek_analysis_model_id', 'setting_value' => (string) $model->id]);

        return $model;
    }
}
