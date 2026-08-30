<?php

namespace Tests\Feature;

use App\Exceptions\TitleGenerationException;
use App\Jobs\ProcessTitleGenerationBatchJob;
use App\Jobs\ResumeTitleGenerationRunJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Title;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use App\Services\GeoFlow\TitleGenerationOutcome;
use App\Support\AdminUiRegistry;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TitleGenerationQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_generation_page_accepts_up_to_one_hundred_thousand_titles(): void
    {
        [$admin, , $library] = $this->createFixtures();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.ai-generate', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('max="100000"', false)
            ->assertSee('data-keyword-count="1"', false)
            ->assertSee('data-keyword-reuse-title', false)
            ->assertSee(__('admin.title_ai_generate.button.async'));
    }

    public function test_title_count_above_the_effective_keyword_count_requires_reuse_confirmation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $keywordLibrary->update(['keyword_count' => 999]);
        $payload = $this->validPayload($keywordLibrary, $aiModel, 2);
        unset($payload['confirmed_keyword_reuse']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]), $payload)
            ->assertSessionHasErrors('confirmed_keyword_reuse');

        $this->assertDatabaseCount('title_generation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_title_count_within_the_effective_keyword_count_does_not_require_reuse_confirmation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'GEO 品牌增长',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $payload = $this->validPayload($keywordLibrary, $aiModel, 2);
        unset($payload['confirmed_keyword_reuse']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]), $payload)
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $library->id]));

        $this->assertDatabaseCount('title_generation_runs', 1);
    }

    public function test_the_full_keyword_library_count_is_used_for_reuse_confirmation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        foreach (range(2, 11) as $index) {
            Keyword::query()->create([
                'library_id' => $keywordLibrary->id,
                'keyword' => 'GEO 关键词 '.$index,
                'used_count' => 0,
                'usage_count' => 0,
            ]);
        }
        $payload = $this->validPayload($keywordLibrary, $aiModel, 11);
        unset($payload['confirmed_keyword_reuse']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]), $payload)
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $library->id]));

        $this->assertDatabaseCount('title_generation_runs', 1);
    }

    public function test_coordinator_rechecks_confirmation_against_its_final_keyword_snapshot(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'GEO 品牌增长',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $service = Mockery::mock(TitleAiGenerationService::class);

        try {
            (new TitleGenerationCoordinator($service))->start($library, [
                'keyword_library_id' => (int) $keywordLibrary->id,
                'ai_model_id' => (int) $aiModel->id,
                'title_count' => 3,
                'title_style' => 'professional',
                'custom_prompt' => '',
                'confirmed_keyword_reuse' => false,
            ], null, 'zh_CN');
            $this->fail('Expected keyword reuse confirmation to be required.');
        } catch (TitleGenerationException $exception) {
            $this->assertSame('title_generation_keyword_reuse_confirmation_required', $exception->reason);
        }

        $this->assertDatabaseCount('title_generation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_successive_batches_rotate_an_immutable_full_keyword_snapshot(): void
    {
        Queue::fake();
        config()->set('geoflow.title_ai_batch_size', 2);
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        foreach (['第二个关键词', '第三个关键词'] as $keyword) {
            Keyword::query()->create([
                'library_id' => $keywordLibrary->id,
                'keyword' => $keyword,
                'used_count' => 0,
                'usage_count' => 0,
            ]);
        }
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturn(
            TitleGenerationOutcome::success(['第一批标题一', '第一批标题二']),
        );
        $coordinator = new TitleGenerationCoordinator($service);
        $run = $coordinator->start($library, [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => (int) $aiModel->id,
            'title_count' => 3,
            'title_style' => 'professional',
            'custom_prompt' => '',
            'confirmed_keyword_reuse' => false,
        ], null, 'zh_CN');
        $firstWindow = (array) data_get($run->keyword_snapshot, 'keywords', []);

        Keyword::query()->where('library_id', $keywordLibrary->id)->delete();
        $coordinator->processBatch(
            (int) $run->id,
            0,
            (string) $run->dispatch_token,
        );

        $nextWindow = (array) data_get($run->fresh()->keyword_snapshot, 'keywords', []);
        $this->assertCount(2, $firstWindow);
        $this->assertCount(1, $nextWindow);
        $this->assertSame([], array_values(array_intersect($firstWindow, $nextWindow)));
        $this->assertDatabaseCount('title_generation_run_keywords', 3);
    }

    public function test_submitting_the_maximum_creates_one_background_run(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();

        $response = $this->actingAs($admin, 'admin')->post(
            route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]),
            $this->validPayload($keywordLibrary, $aiModel, 100_000),
        );

        $response->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $library->id]));
        $run = TitleGenerationRun::query()->sole();
        $this->assertSame(100_000, (int) $run->requested_count);
        $this->assertSame(50, (int) $run->batch_size);
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->status);
        Queue::assertPushed(
            ProcessTitleGenerationBatchJob::class,
            fn (ProcessTitleGenerationBatchJob $job): bool => $job->runId === (int) $run->id
                && $job->batchSequence === 0
                && $job->tries === 0
                && $job->timeout === 360
                && $job->failOnTimeout
                && $job->queue === 'geoflow',
        );
    }

    public function test_count_above_the_maximum_is_rejected_before_queueing(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.title-libraries.ai-generate', ['libraryId' => $library->id]))
            ->post(
                route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]),
                $this->validPayload($keywordLibrary, $aiModel, 100_001),
            )
            ->assertRedirect(route('admin.title-libraries.ai-generate', ['libraryId' => $library->id]))
            ->assertSessionHasErrors('title_count');

        $this->assertDatabaseCount('title_generation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_large_run_requires_explicit_cost_confirmation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $payload = $this->validPayload($keywordLibrary, $aiModel, 100_000);
        unset($payload['confirmed_large_run']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]), $payload)
            ->assertSessionHasErrors('confirmed_large_run');

        $this->assertDatabaseCount('title_generation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_small_run_does_not_require_large_run_confirmation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $payload = $this->validPayload($keywordLibrary, $aiModel, 10);
        unset($payload['confirmed_large_run']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]), $payload)
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $library->id]));

        $this->assertDatabaseCount('title_generation_runs', 1);
    }

    public function test_a_library_cannot_start_two_active_runs(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $payload = $this->validPayload($keywordLibrary, $aiModel, 10);

        $this->actingAs($admin, 'admin')->post(
            route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]),
            $payload,
        );
        $this->actingAs($admin, 'admin')->post(
            route('admin.title-libraries.ai-generate.submit', ['libraryId' => $library->id]),
            $payload,
        )->assertSessionHasErrors();

        $this->assertDatabaseCount('title_generation_runs', 1);
        Queue::assertPushed(ProcessTitleGenerationBatchJob::class, 1);
    }

    public function test_submission_capacity_limits_active_runs_per_admin(): void
    {
        Queue::fake();
        config()->set('geoflow.title_ai_max_active_runs_per_admin', 1);
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $this->createRun($library, $keywordLibrary, $aiModel, 10)->update([
            'created_by_admin_id' => $admin->id,
        ]);
        $otherLibrary = TitleLibrary::query()->create([
            'name' => '第二个标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $coordinator = new TitleGenerationCoordinator(Mockery::mock(TitleAiGenerationService::class));

        try {
            $coordinator->start($otherLibrary, [
                'keyword_library_id' => (int) $keywordLibrary->id,
                'ai_model_id' => (int) $aiModel->id,
                'title_count' => 10,
                'title_style' => 'professional',
                'custom_prompt' => '',
                'confirmed_keyword_reuse' => true,
            ], (int) $admin->id, 'zh_CN');
            $this->fail('Expected the active-run capacity limit to reject this task.');
        } catch (TitleGenerationException $exception) {
            $this->assertSame('title_generation_capacity_exceeded', $exception->reason);
        }

        $this->assertDatabaseCount('title_generation_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_retry_cannot_bypass_the_active_run_capacity_limit(): void
    {
        Queue::fake();
        config()->set('geoflow.title_ai_max_active_runs_per_admin', 1);
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $this->createRun($library, $keywordLibrary, $aiModel, 10)->update([
            'created_by_admin_id' => $admin->id,
        ]);
        $otherLibrary = TitleLibrary::query()->create([
            'name' => '待重试标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $retryRun = $this->createRun($otherLibrary, $keywordLibrary, $aiModel, 10);
        $retryRun->forceFill([
            'created_by_admin_id' => $admin->id,
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'failure_code' => 'batch_failed',
        ])->save();

        try {
            (new TitleGenerationCoordinator(Mockery::mock(TitleAiGenerationService::class)))->retry($retryRun);
            $this->fail('Expected the retry capacity limit to reject this task.');
        } catch (TitleGenerationException $exception) {
            $this->assertSame('title_generation_capacity_exceeded', $exception->reason);
        }

        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $retryRun->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_batch_bulk_inserts_new_titles_and_queues_the_next_batch(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $library->update(['title_count' => 1]);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '重复标题',
            'keyword' => 'GEO',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 3);
        $run->update(['dispatch_token' => 'lease-one']);
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturn(
            TitleGenerationOutcome::success(['重复标题', '全新标题一', '全新标题二']),
        );

        (new TitleGenerationCoordinator($service))->processBatch((int) $run->id, 0, 'lease-one');

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->status);
        $this->assertSame(2, (int) $run->saved_count);
        $this->assertSame(1, (int) $run->duplicate_count);
        $this->assertSame(3, (int) $run->generated_count);
        $this->assertSame(1, (int) $run->batch_count);
        $this->assertSame(3, Title::query()->where('library_id', $library->id)->count());
        Queue::assertPushed(
            ProcessTitleGenerationBatchJob::class,
            fn (ProcessTitleGenerationBatchJob $job): bool => $job->runId === (int) $run->id
                && $job->batchSequence === 1,
        );
    }

    public function test_model_failure_pauses_the_run_without_inserting_template_titles(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->update(['dispatch_token' => 'failed-lease']);
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturn(
            TitleGenerationOutcome::failed('ai_provider_request_failed'),
        );
        $coordinator = new TitleGenerationCoordinator($service);

        try {
            $coordinator->processBatch((int) $run->id, 0, 'failed-lease');
            $this->fail('Expected title generation to fail.');
        } catch (RuntimeException $exception) {
            $coordinator->markFailed((int) $run->id, 0, 'failed-lease', $exception);
        }

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('batch_failed', $run->failure_code);
        $this->assertNull($run->active_key);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_a_stale_job_token_cannot_overwrite_the_current_lease(): void
    {
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'lease_token' => 'current-lease',
            'lease_expires_at' => now()->addMinute(),
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');

        (new TitleGenerationCoordinator($service))->processBatch((int) $run->id, 0, 'stale-lease');

        $run->refresh();
        $this->assertSame('current-lease', $run->lease_token);
        $this->assertSame(TitleGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_recovery_requeues_an_expired_lease(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'lease_token' => 'expired-lease',
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $this->artisan('geoflow:recover-title-generations', ['--limit' => 1])
            ->expectsOutput('Recovered title generation runs: 1')
            ->assertExitCode(0);

        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->fresh()->status);
        Queue::assertPushed(
            ProcessTitleGenerationBatchJob::class,
            fn (ProcessTitleGenerationBatchJob $job): bool => $job->runId === (int) $run->id,
        );
    }

    public function test_retry_preserves_saved_progress_and_queues_the_current_batch(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 25);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_PARTIAL,
            'active_key' => null,
            'saved_count' => 12,
            'batch_sequence' => 4,
            'model_request_budget' => 75,
            'model_request_count' => 40,
            'failure_code' => 'batch_failed',
            'last_error' => 'provider unavailable',
            'failed_at' => now(),
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);

        $retried = (new TitleGenerationCoordinator($service))->retry($run);

        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $retried->status);
        $this->assertSame(12, (int) $retried->saved_count);
        $this->assertSame(75, (int) $retried->model_request_budget);
        $this->assertSame(40, (int) $retried->model_request_count);
        $this->assertSame(1, (int) $retried->manual_retry_count);
        $this->assertNull($retried->failure_code);
        Queue::assertPushed(
            ProcessTitleGenerationBatchJob::class,
            fn (ProcessTitleGenerationBatchJob $job): bool => $job->runId === (int) $run->id
                && $job->batchSequence === 4,
        );
    }

    public function test_status_endpoint_is_scoped_to_the_title_library(): void
    {
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 25);
        $otherLibrary = TitleLibrary::query()->create([
            'name' => '其他标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->assertSame(
            'endpoint',
            app(AdminUiRegistry::class)->routeClassification('admin.title-libraries.ai-generate.status'),
        );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee('data-title-generation-progress', false)
            ->assertSee(route('admin.title-libraries.ai-generate.status', [
                'libraryId' => $library->id,
                'runId' => $run->id,
            ]), false);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.title-libraries.ai-generate.status', [
                'libraryId' => $library->id,
                'runId' => $run->id,
            ]))
            ->assertOk()
            ->assertJsonPath('requested_count', 25)
            ->assertJsonPath('active', true);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.title-libraries.ai-generate.status', [
                'libraryId' => $otherLibrary->id,
                'runId' => $run->id,
            ]))
            ->assertNotFound();
    }

    public function test_a_low_yield_run_stops_when_its_model_request_budget_is_exhausted(): void
    {
        Queue::fake();
        config()->set('geoflow.title_ai_max_empty_batches', 10);
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '重复标题',
            'keyword' => 'GEO',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 2);
        $run->forceFill([
            'dispatch_token' => 'budget-lease',
            'model_request_budget' => 6,
            'model_request_count' => 4,
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturn(
            TitleGenerationOutcome::success(['重复标题', '仅有一个新标题']),
        );

        (new TitleGenerationCoordinator($service))->processBatch((int) $run->id, 0, 'budget-lease');

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_PARTIAL, $run->status);
        $this->assertSame('request_budget_exhausted', $run->failure_code);
        $this->assertSame(1, (int) $run->saved_count);
        $this->assertNull($run->active_key);
        $this->assertFalse($run->isRetryable());
        Queue::assertNothingPushed();
    }

    public function test_an_exhausted_request_budget_stops_before_another_model_call(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 2);
        $run->forceFill([
            'dispatch_token' => 'exhausted-budget-lease',
            'model_request_budget' => 6,
            'model_request_count' => 6,
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');

        (new TitleGenerationCoordinator($service))->processBatch(
            (int) $run->id,
            0,
            'exhausted-budget-lease',
        );

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_PARTIAL, $run->status);
        $this->assertSame('request_budget_exhausted', $run->failure_code);
        $this->assertNull($run->active_key);
        Queue::assertNothingPushed();
    }

    public function test_daily_quota_exhaustion_defers_the_same_batch_until_the_next_day(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->update(['dispatch_token' => 'quota-lease']);
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturn(
            TitleGenerationOutcome::quotaExhausted(),
        );

        $coordinator = new TitleGenerationCoordinator($service);
        $coordinator->processBatch((int) $run->id, 0, 'quota-lease');

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->status);
        $this->assertSame('quota_wait', $run->failure_code);
        $this->assertTrue($run->available_at->isFuture());
        $this->assertSame(0, (int) $run->batch_attempt_count);
        Queue::assertPushed(
            ResumeTitleGenerationRunJob::class,
            fn (ResumeTitleGenerationRunJob $job): bool => $job->runId === (int) $run->id
                && $job->batchSequence === 0,
        );

        $this->travelTo($run->available_at->copy()->addSecond());
        $coordinator->resumeDeferred((int) $run->id, 0);
        Queue::assertPushed(
            ProcessTitleGenerationBatchJob::class,
            fn (ProcessTitleGenerationBatchJob $job): bool => $job->runId === (int) $run->id
                && $job->batchSequence === 0,
        );
        $this->travelBack();
    }

    public function test_a_pre_handle_job_failure_releases_the_active_run(): void
    {
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->update(['dispatch_token' => 'dispatch-lease']);
        $service = Mockery::mock(TitleAiGenerationService::class);

        (new TitleGenerationCoordinator($service))->markFailed(
            (int) $run->id,
            0,
            'dispatch-lease',
            new RuntimeException('container failed'),
        );

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->active_key);
        $this->assertNull($run->dispatch_token);
        $this->assertSame('title_generation_batch_failed', $run->last_error);
    }

    public function test_manual_retry_limit_cannot_be_reset_by_repeated_user_actions(): void
    {
        Queue::fake();
        config()->set('geoflow.title_ai_max_manual_retries', 1);
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'failure_code' => 'batch_failed',
        ])->save();
        $coordinator = new TitleGenerationCoordinator(Mockery::mock(TitleAiGenerationService::class));

        $coordinator->retry($run);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'dispatch_token' => null,
            'failure_code' => 'batch_failed',
        ])->save();

        $this->expectException(TitleGenerationException::class);
        $coordinator->retry($run->fresh());
    }

    public function test_an_active_run_prevents_its_ai_model_from_being_changed_or_deleted(): void
    {
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.ai-models.edit', ['modelId' => $aiModel->id]))
            ->put(route('admin.ai-models.update', ['modelId' => $aiModel->id]), [
                'name' => 'Changed While Running',
                'version' => 'test',
                'api_key' => '',
                'model_id' => 'changed-model',
                'model_type' => 'chat',
                'api_url' => 'https://changed.test/v1',
                'failover_priority' => 100,
                'daily_limit' => 100_000,
                'status' => 'active',
            ])
            ->assertSessionHasErrors();
        $this->assertSame('Title Model', $aiModel->fresh()->name);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $aiModel->id]))
            ->assertSessionHasErrors();
        $this->assertNotNull($aiModel->fresh());

        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_CANCELLED,
            'active_key' => null,
            'dispatch_token' => null,
            'failure_code' => null,
        ])->save();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $aiModel->id]))
            ->assertRedirect(route('admin.ai-models.index'));
        $run->refresh();
        $this->assertNull($run->ai_model_id);
        $this->assertFalse($run->isRetryable());
    }

    public function test_recovery_cannot_reset_the_persisted_batch_attempt_limit(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'dispatch_token' => null,
            'lease_token' => 'recovered-lease',
            'lease_expires_at' => now()->subMinute(),
            'batch_attempt_count' => 3,
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');
        $coordinator = new TitleGenerationCoordinator($service);

        $this->assertSame(1, $coordinator->recoverStalled());
        $recoveredToken = (string) $run->fresh()->dispatch_token;
        $this->assertNotSame('recovered-lease', $recoveredToken);
        $coordinator->processBatch((int) $run->id, 0, 'recovered-lease');
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->fresh()->status);
        $coordinator->processBatch((int) $run->id, 0, $recoveredToken);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('batch_attempts_exhausted', $run->failure_code);
        $this->assertNull($run->active_key);
    }

    public function test_cancelling_an_active_run_invalidates_its_in_flight_lease(): void
    {
        Queue::fake();
        [, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'dispatch_token' => null,
            'lease_token' => 'active-lease',
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');
        $coordinator = new TitleGenerationCoordinator($service);

        $coordinator->cancel($run);
        $coordinator->processBatch((int) $run->id, 0, 'active-lease');

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_CANCELLED, $run->status);
        $this->assertNull($run->active_key);
        $this->assertNull($run->lease_token);
        $this->assertNotNull($run->cancelled_at);
        $this->assertTrue($run->isRetryable());
        $this->assertDatabaseCount('titles', 0);

        $coordinator->retry($run);
        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->status);
        $this->assertNull($run->cancelled_at);
    }

    public function test_cancel_endpoint_is_scoped_to_the_title_library(): void
    {
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $run = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $otherLibrary = TitleLibrary::query()->create([
            'name' => '其他标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.cancel', [
                'libraryId' => $otherLibrary->id,
                'runId' => $run->id,
            ]))
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.ai-generate.cancel', [
                'libraryId' => $library->id,
                'runId' => $run->id,
            ]))
            ->assertRedirect();
        $this->assertSame(TitleGenerationRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    public function test_detail_prefers_an_active_retried_run_over_a_newer_completed_run(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $library, $aiModel] = $this->createFixtures();
        $oldRun = $this->createRun($library, $keywordLibrary, $aiModel, 5);
        $oldRun->forceFill([
            'status' => TitleGenerationRun::STATUS_PARTIAL,
            'active_key' => null,
            'failure_code' => 'batch_failed',
            'failed_at' => now(),
        ])->save();
        TitleGenerationRun::query()->create([
            'title_library_id' => $library->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $aiModel->id,
            'status' => TitleGenerationRun::STATUS_COMPLETED,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO 内容工程'],
            'completed_at' => now(),
        ]);
        $service = Mockery::mock(TitleAiGenerationService::class);

        (new TitleGenerationCoordinator($service))->retry($oldRun);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => $library->id]))
            ->assertOk()
            ->assertSee(route('admin.title-libraries.ai-generate.status', [
                'libraryId' => $library->id,
                'runId' => $oldRun->id,
            ]), false);
    }

    public function test_numbered_prefix_normalization_preserves_years_and_quantities(): void
    {
        $this->assertSame('2026年GEO发展趋势', TitleAiGenerationService::normalizeTitle('2026年GEO发展趋势'));
        $this->assertSame('10个提升转化率的方法', TitleAiGenerationService::normalizeTitle('10个提升转化率的方法'));
        $this->assertSame('真正的标题', TitleAiGenerationService::normalizeTitle('1. 真正的标题'));
        $this->assertSame('真正的标题', TitleAiGenerationService::normalizeTitle('1 - 真正的标题'));
        $this->assertSame('3.0 时代的 GEO', TitleAiGenerationService::normalizeTitle('3.0 时代的 GEO'));
        $this->assertSame('1.5 倍增长', TitleAiGenerationService::normalizeTitle('1.5 倍增长'));
        $this->assertSame('1-3 年实践', TitleAiGenerationService::normalizeTitle('1-3 年实践'));
        $this->assertSame('1 - 3 年实践', TitleAiGenerationService::normalizeTitle('1 - 3 年实践'));
        $this->assertSame('GEO标题', TitleAiGenerationService::normalizeTitle("GEO\u{200B}标题"));
        $this->assertSame('', TitleAiGenerationService::normalizeTitle('以下是本批标题列表：'));
        $this->assertSame(
            Title::fingerprintFor('GEO标题'),
            Title::fingerprintFor("GEO\u{200B}标题"),
        );
    }

    public function test_all_new_titles_receive_a_fingerprint_and_required_query_indexes_exist(): void
    {
        [, , $library] = $this->createFixtures();
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => '手工标题',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->assertSame(Title::fingerprintFor('手工标题'), $title->title_fingerprint);
        $this->assertSame(0, DB::table('titles')->insertOrIgnore([
            'library_id' => $library->id,
            'title' => '手工标题',
            'title_fingerprint' => Title::fingerprintFor('手工标题'),
            'keyword' => '',
            'is_ai_generated' => true,
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ]));
        $indexes = Schema::getIndexListing('titles');
        $this->assertContains('titles_library_title_idx', $indexes);
        $this->assertContains('titles_library_created_id_idx', $indexes);
    }

    public function test_the_data_upgrade_claims_one_canonical_fingerprint_for_historical_variants(): void
    {
        [, , $library] = $this->createFixtures();
        foreach ([
            'GEO标题',
            "GEO\u{200B}标题",
            "👩\u{200D}💻",
            '👩💻',
            "می\u{200C}روم",
            'میروم',
        ] as $title) {
            DB::table('titles')->insert([
                'library_id' => $library->id,
                'title' => $title,
                'title_fingerprint' => null,
                'keyword' => '',
                'is_ai_generated' => false,
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_08_28_000100_backfill_title_fingerprints.php');
        $migration->up();

        $this->assertSame(5, DB::table('titles')
            ->where('library_id', $library->id)
            ->whereNotNull('title_fingerprint')
            ->count());
        $this->assertSame(5, DB::table('titles')
            ->where('library_id', $library->id)
            ->whereNotNull('title_fingerprint')
            ->distinct()
            ->count('title_fingerprint'));
        $this->assertSame(0, DB::table('titles')->insertOrIgnore([
            'library_id' => $library->id,
            'title' => 'GEO标题',
            'title_fingerprint' => Title::fingerprintFor('GEO标题'),
            'keyword' => '',
            'is_ai_generated' => true,
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ]));
    }

    /** @return array{Admin,KeywordLibrary,TitleLibrary,AiModel} */
    private function createFixtures(): array
    {
        $admin = Admin::query()->create([
            'username' => 'title_generation_admin',
            'password' => 'secret-123',
            'email' => 'title-generation@example.com',
            'display_name' => 'Title Generation Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'GEO 关键词库',
            'description' => '',
            'keyword_count' => 1,
        ]);
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'GEO 内容工程',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $library = TitleLibrary::query()->create([
            'name' => 'GEO 标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Title Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('title-test-key'),
            'model_id' => 'title-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'daily_limit' => 100_000,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        return [$admin, $keywordLibrary, $library, $aiModel];
    }

    /** @return array<string, int|string> */
    private function validPayload(KeywordLibrary $keywordLibrary, AiModel $aiModel, int $count): array
    {
        return [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => (int) $aiModel->id,
            'title_count' => $count,
            'title_style' => 'professional',
            'custom_prompt' => '',
            'confirmed_large_run' => 1,
            'confirmed_keyword_reuse' => 1,
        ];
    }

    private function createRun(
        TitleLibrary $library,
        KeywordLibrary $keywordLibrary,
        AiModel $aiModel,
        int $requestedCount,
    ): TitleGenerationRun {
        return TitleGenerationRun::query()->create([
            'title_library_id' => $library->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $aiModel->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'active_key' => 'title-library:'.$library->id,
            'requested_count' => $requestedCount,
            'batch_size' => min(50, $requestedCount),
            'model_request_budget' => $requestedCount * 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO 内容工程'],
            'available_at' => now(),
        ]);
    }
}
