<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\TitleGenerationException;
use App\Jobs\ProcessTitleGenerationBatchJob;
use App\Jobs\ResumeTitleGenerationRunJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use App\Services\GeoFlow\TitleGenerationOutcome;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AdminTitleGenerationAiExecutionIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_submission_freezes_authoritative_admin_and_requested_model_identity(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $admin->forceFill(['ai_config_access_version' => 7])->save();

        $this->actingAs($admin, 'admin')->post(
            route('admin.title-libraries.ai-generate.submit', ['libraryId' => $titleLibrary->id]),
            $this->validPayload($keywordLibrary, $model),
        )->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $titleLibrary->id]));

        $run = TitleGenerationRun::query()->sole();
        $this->assertSame((int) $admin->id, (int) $run->model_access_admin_id);
        $this->assertSame('admin', $run->model_access_admin_role);
        $this->assertSame(7, (int) $run->ai_config_access_version);
        $this->assertSame(AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION, (int) $run->resolver_policy_version);
        $this->assertSame((int) $model->id, (int) $run->requested_ai_model_id);
        $this->assertNull($run->resolved_ai_model_id);

        Queue::assertPushed(ProcessTitleGenerationBatchJob::class, function (ProcessTitleGenerationBatchJob $job) use ($run): bool {
            $payload = serialize($job);

            return $job->runId === (int) $run->id
                && ! str_contains($payload, 'title-test-key')
                && ! str_contains($payload, 'https://ai.test')
                && ! str_contains($payload, 'custom_prompt');
        });
    }

    public function test_requested_model_precedes_remaining_personal_and_shared_candidates(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $personal] = $this->fixtures();
        $provider = $this->admin('title_candidate_provider', ['role' => 'super_admin']);
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $requestedShared = $this->model($provider, 'title-requested-shared', ['failover_priority' => 50]);
        $remainingShared = $this->model($provider, 'title-remaining-shared', ['failover_priority' => 60]);
        $personal->forceFill(['failover_priority' => 1])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $seen = [];
        $service->shouldReceive('generateTitles')->times(3)->andReturnUsing(
            function (AiModel $model) use (&$seen): TitleGenerationOutcome {
                $seen[] = (int) $model->id;

                return count($seen) < 3
                    ? TitleGenerationOutcome::failed('ai_provider_request_failed', retryable: true)
                    : TitleGenerationOutcome::success(['隔离后的标题']);
            },
        );
        $coordinator = new TitleGenerationCoordinator($service);
        $run = $coordinator->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $requestedShared),
            (int) $admin->id,
            'zh_CN',
        );

        $coordinator->processBatch((int) $run->id, 0, (string) $run->dispatch_token);

        $this->assertSame([
            (int) $requestedShared->id,
            (int) $personal->id,
            (int) $remainingShared->id,
        ], $seen);
        $run->refresh();
        $this->assertSame((int) $remainingShared->id, (int) $run->resolved_ai_model_id);
        $this->assertSame('shared', $run->resolved_model_source);
    }

    public function test_access_revocation_during_provider_call_discards_titles_and_fails_permanently(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturnUsing(
            function () use ($admin): TitleGenerationOutcome {
                $admin->forceFill([
                    'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version) + 1,
                ])->save();

                return TitleGenerationOutcome::success(['撤权后不得保存']);
            },
        );
        $coordinator = new TitleGenerationCoordinator($service);
        $run = $coordinator->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );
        $job = new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $model->id,
            0,
            (string) $run->dispatch_token,
        );

        $job->handle($coordinator);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertFalse((bool) $run->retryable_failure);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertDatabaseCount('titles', 0);
        $this->assertSame(0, (int) $titleLibrary->fresh()->title_count);
    }

    public function test_enforced_worker_fails_closed_for_historical_queued_run_without_identity(): void
    {
        Queue::fake();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        [, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'available_at' => now(),
            'dispatch_token' => 'legacy-title-dispatch',
        ]);
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');
        $coordinator = new TitleGenerationCoordinator($service);

        (new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $model->id,
            0,
            'legacy-title-dispatch',
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertFalse((bool) $run->retryable_failure);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_default_shadow_mode_rejects_a_legacy_owned_run_without_frozen_identity(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures();
        $peer = $this->admin('title-legacy-peer');
        $peerModel = $this->model($peer, 'title-legacy-peer-model');
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $peerModel->id,
            'created_by_admin_id' => $admin->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'available_at' => now(),
            'dispatch_token' => 'legacy-peer-dispatch',
        ]);
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');
        $coordinator = new TitleGenerationCoordinator($service);

        (new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $peerModel->id,
            0,
            'legacy-peer-dispatch',
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
    }

    public function test_default_shadow_mode_permanently_rejects_unowned_legacy_runs_before_any_model_call(): void
    {
        Queue::fake();
        [, $keywordLibrary, $titleLibrary] = $this->fixtures();
        $peer = $this->admin('title-unowned-peer');
        $superAdmin = $this->admin('title-unowned-system-owner', ['role' => 'super_admin']);
        $peerModel = $this->model($peer, 'title-unowned-peer-model');
        $systemModel = $this->model($superAdmin, 'title-unowned-system-model');
        $systemModel->forceFill(['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');
        $coordinator = new TitleGenerationCoordinator($service);

        foreach ([$peerModel, $systemModel] as $index => $model) {
            $dispatchToken = 'unowned-title-dispatch-'.$index;
            $run = TitleGenerationRun::query()->create([
                'title_library_id' => $titleLibrary->id,
                'keyword_library_id' => $keywordLibrary->id,
                'ai_model_id' => $model->id,
                'status' => TitleGenerationRun::STATUS_QUEUED,
                'active_key' => 'title-library:'.$titleLibrary->id,
                'requested_count' => 1,
                'batch_size' => 1,
                'model_request_budget' => 3,
                'title_style' => 'professional',
                'locale' => 'zh_CN',
                'keyword_snapshot' => ['GEO identity'],
                'available_at' => now(),
                'dispatch_token' => $dispatchToken,
            ]);

            (new ProcessTitleGenerationBatchJob(
                (int) $run->id,
                (int) $model->id,
                0,
                $dispatchToken,
            ))->handle($coordinator);

            $run->refresh();
            $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
            $this->assertSame('ai_config_access_revoked', $run->error_code);
            $this->assertFalse((bool) $run->retryable_failure);
        }

        $this->assertDatabaseCount('titles', 0);
    }

    public function test_partial_persisted_identity_never_downgrades_to_legacy_global_access(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'created_by_admin_id' => $admin->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'available_at' => now(),
            'dispatch_token' => 'partial-title-dispatch',
        ]);
        $run->forceFill(['model_access_admin_id' => $admin->id])->save();
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldNotReceive('generateTitles');

        (new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $model->id,
            0,
            'partial-title-dispatch',
        ))->handle(new TitleGenerationCoordinator($service));

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
    }

    public function test_another_admin_cannot_guess_status_cancel_or_retry_urls(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $peer = $this->admin('title_run_peer');
        $run = app(TitleGenerationCoordinator::class)->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );

        $this->actingAs($peer, 'admin')->getJson(route('admin.title-libraries.ai-generate.status', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertNotFound();
        $this->actingAs($peer, 'admin')->post(route('admin.title-libraries.ai-generate.cancel', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertNotFound();

        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'dispatch_token' => null,
            'failure_code' => 'batch_failed',
            'retryable_failure' => true,
        ])->save();
        $this->actingAs($peer, 'admin')->post(route('admin.title-libraries.ai-generate.retry', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertNotFound();

        $this->actingAs($admin, 'admin')->getJson(route('admin.title-libraries.ai-generate.status', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertOk();
    }

    public function test_unowned_legacy_run_id_is_not_visible_to_any_admin(): void
    {
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'available_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')->getJson(route('admin.title-libraries.ai-generate.status', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertNotFound();
    }

    public function test_identity_run_retry_and_cancel_require_the_original_actor_at_service_boundary(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $coordinator = app(TitleGenerationCoordinator::class);
        $run = $coordinator->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );

        try {
            $coordinator->cancel($run);
            $this->fail('Expected actor-less cancellation to be rejected.');
        } catch (TitleGenerationException $exception) {
            $this->assertSame('ai_model_not_accessible', $exception->reason);
        }

        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'dispatch_token' => null,
            'failure_code' => 'batch_failed',
            'retryable_failure' => true,
        ])->save();
        try {
            $coordinator->retry($run);
            $this->fail('Expected actor-less retry to be rejected.');
        } catch (TitleGenerationException $exception) {
            $this->assertSame('ai_model_not_accessible', $exception->reason);
        }
    }

    public function test_retry_keeps_original_identity_and_rejects_revoked_access_version(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $admin->forceFill(['ai_config_access_version' => 3])->save();
        $run = app(TitleGenerationCoordinator::class)->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'dispatch_token' => null,
            'failure_code' => 'batch_failed',
            'retryable_failure' => true,
        ])->save();
        $admin->forceFill(['ai_config_access_version' => 4])->save();

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.retry', [
            'libraryId' => $titleLibrary->id,
            'runId' => $run->id,
        ]))->assertSessionHasErrors();

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame(3, (int) $run->ai_config_access_version);
        Queue::assertPushed(ProcessTitleGenerationBatchJob::class, 1);
    }

    public function test_permanent_provider_failure_does_not_switch_candidate_or_retry_the_run(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $personal] = $this->fixtures();
        $provider = $this->admin('title_permanent_provider', ['role' => 'super_admin']);
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $this->model($provider, 'title-permanent-shared');
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->withArgs(
            static fn (AiModel $model): bool => (int) $model->id === (int) $personal->id,
        )->andReturn(TitleGenerationOutcome::failed('ai_key_missing'));
        $coordinator = new TitleGenerationCoordinator($service);
        $run = $coordinator->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $personal),
            (int) $admin->id,
            'zh_CN',
        );

        (new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $personal->id,
            0,
            (string) $run->dispatch_token,
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertFalse((bool) $run->retryable_failure);
        $this->assertSame('ai_provider_request_rejected', $run->error_code);
        $this->assertSame(1, (int) $run->batch_attempt_count);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_recovery_rotates_the_lease_and_fences_the_old_worker_result(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $coordinator = null;
        $runId = 0;
        $service = Mockery::mock(TitleAiGenerationService::class);
        $service->shouldReceive('generateTitles')->once()->andReturnUsing(
            function () use (&$coordinator, &$runId): TitleGenerationOutcome {
                TitleGenerationRun::query()->whereKey($runId)->update([
                    'lease_expires_at' => now()->subSecond(),
                    'updated_at' => now()->subMinutes(10),
                ]);
                $this->assertSame(1, $coordinator->recoverStalled(1));

                return TitleGenerationOutcome::success(['旧 Worker 结果']);
            },
        );
        $coordinator = new TitleGenerationCoordinator($service);
        $run = $coordinator->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );
        $runId = (int) $run->id;
        $oldLease = (string) $run->dispatch_token;

        (new ProcessTitleGenerationBatchJob($runId, (int) $model->id, 0, $oldLease))->handle($coordinator);

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_QUEUED, $run->status);
        $this->assertNotSame($oldLease, (string) $run->dispatch_token);
        $this->assertTrue(Str::isUuid((string) $run->dispatch_token));
        $this->assertDatabaseCount('titles', 0);
        $this->assertSame(0, (int) $titleLibrary->fresh()->title_count);
    }

    public function test_failed_callback_sanitizes_provider_urls_and_credentials(): void
    {
        [, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'lease_token' => 'title-secret-lease',
            'lease_expires_at' => now()->addMinute(),
        ]);
        $job = new ProcessTitleGenerationBatchJob(
            (int) $run->id,
            (int) $model->id,
            0,
            'title-secret-lease',
        );

        $job->failed(new \RuntimeException(
            'provider https://api.test/v1?token=secret Authorization: Bearer sk-provider-secret api_key=visible-secret',
        ));

        $error = (string) $run->fresh()->last_error;
        $this->assertStringNotContainsString('api.test', $error);
        $this->assertStringNotContainsString('sk-provider-secret', $error);
        $this->assertStringNotContainsString('visible-secret', $error);
        $this->assertLessThanOrEqual(500, mb_strlen($error));
    }

    public function test_recovery_fails_closed_for_an_expired_historical_run_without_identity(): void
    {
        Queue::fake();
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);
        [, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'lease_token' => 'historical-title-lease',
            'lease_expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->assertSame(0, app(TitleGenerationCoordinator::class)->recoverStalled(1));

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
        Queue::assertNothingPushed();
    }

    public function test_deferred_resume_keeps_the_snapshot_and_stops_after_revocation(): void
    {
        Queue::fake();
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();
        $run = app(TitleGenerationCoordinator::class)->start(
            $titleLibrary,
            $this->servicePayload($keywordLibrary, $model),
            (int) $admin->id,
            'zh_CN',
        );
        $run->forceFill([
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'failure_code' => 'quota_wait',
            'available_at' => now()->subMinute(),
            'dispatch_token' => null,
        ])->save();
        $admin->forceFill([
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version) + 1,
        ])->save();
        Queue::fake();

        (new ResumeTitleGenerationRunJob((int) $run->id, 0))
            ->handle(app(TitleGenerationCoordinator::class));

        $run->refresh();
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
        Queue::assertNothingPushed();
    }

    public function test_admin_and_model_deletion_dependencies_include_title_execution_identity(): void
    {
        [$admin, $keywordLibrary, $titleLibrary, $otherModel] = $this->fixtures();
        $requested = $this->model($admin, 'title-dependency-requested');
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $otherModel->id,
            'created_by_admin_id' => $admin->id,
            'status' => TitleGenerationRun::STATUS_RUNNING,
            'active_key' => 'title-library:'.$titleLibrary->id,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'lease_token' => 'title-dependency-lease',
            'lease_expires_at' => now()->addMinute(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 1,
            'requested_ai_model_id' => $requested->id,
            'resolver_policy_version' => 1,
        ])->save();

        $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($admin);
        $this->assertTrue($dependencies->blocksDeletion());
        $this->assertSame(1, $dependencies->executionTitleGenerationRunCount);
        $blocked = app(AdminAiModelMutationService::class)->delete($admin, (int) $requested->id);
        $this->assertFalse($blocked->succeeded());
        $this->assertSame('title_generation', $blocked->error);
        $this->assertDatabaseHas('title_generation_runs', ['id' => $run->id]);
    }

    public function test_retryable_terminal_runs_block_model_update_and_delete_across_all_title_model_snapshots(): void
    {
        [$admin, $keywordLibrary, $titleLibrary, $fallbackModel] = $this->fixtures();
        $models = [
            'ai_model_id' => $this->model($admin, 'title-retryable-legacy'),
            'requested_ai_model_id' => $this->model($admin, 'title-retryable-requested'),
            'resolved_ai_model_id' => $this->model($admin, 'title-retryable-resolved'),
        ];
        $statuses = [
            'ai_model_id' => TitleGenerationRun::STATUS_PARTIAL,
            'requested_ai_model_id' => TitleGenerationRun::STATUS_FAILED,
            'resolved_ai_model_id' => TitleGenerationRun::STATUS_CANCELLED,
        ];

        foreach ($models as $column => $model) {
            $run = TitleGenerationRun::query()->create([
                'title_library_id' => $titleLibrary->id,
                'keyword_library_id' => $keywordLibrary->id,
                'ai_model_id' => $column === 'ai_model_id' ? $model->id : $fallbackModel->id,
                'created_by_admin_id' => $admin->id,
                'status' => $statuses[$column],
                'requested_count' => 1,
                'batch_size' => 1,
                'model_request_budget' => 3,
                'title_style' => 'professional',
                'locale' => 'zh_CN',
                'keyword_snapshot' => ['GEO identity'],
                'failure_code' => 'batch_failed',
                'manual_retry_count' => 0,
            ]);
            $run->forceFill([
                $column => $model->id,
                'retryable_failure' => true,
            ])->save();

            $updated = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                ['name' => $model->name.' updated'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            $deleted = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);

            $this->assertFalse($updated->succeeded(), $column.' update should be blocked');
            $this->assertSame('title_generation', $updated->error);
            $this->assertFalse($deleted->succeeded(), $column.' delete should be blocked');
            $this->assertSame('title_generation', $deleted->error);
            $this->assertDatabaseHas('ai_models', ['id' => $model->id]);
        }
    }

    public function test_non_retryable_terminal_runs_do_not_block_model_update_or_delete(): void
    {
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures();
        $maxRetries = (int) config('geoflow.title_ai_max_manual_retries', 3);
        $cases = [
            [
                'model' => $this->model($admin, 'title-budget-exhausted-model'),
                'status' => TitleGenerationRun::STATUS_FAILED,
                'failure_code' => 'request_budget_exhausted',
                'manual_retry_count' => 0,
                'retryable_failure' => true,
            ],
            [
                'model' => $this->model($admin, 'title-manual-retries-exhausted-model'),
                'status' => TitleGenerationRun::STATUS_CANCELLED,
                'failure_code' => 'batch_failed',
                'manual_retry_count' => $maxRetries,
                'retryable_failure' => true,
            ],
            [
                'model' => $this->model($admin, 'title-permanent-failure-model'),
                'status' => TitleGenerationRun::STATUS_PARTIAL,
                'failure_code' => 'permanent_ai_failure',
                'manual_retry_count' => 0,
                'retryable_failure' => false,
            ],
        ];

        foreach ($cases as $case) {
            $model = $case['model'];
            TitleGenerationRun::query()->create([
                'title_library_id' => $titleLibrary->id,
                'keyword_library_id' => $keywordLibrary->id,
                'ai_model_id' => $model->id,
                'created_by_admin_id' => $admin->id,
                'status' => $case['status'],
                'requested_count' => 1,
                'batch_size' => 1,
                'model_request_budget' => 3,
                'title_style' => 'professional',
                'locale' => 'zh_CN',
                'keyword_snapshot' => ['GEO identity'],
                'failure_code' => $case['failure_code'],
                'manual_retry_count' => $case['manual_retry_count'],
            ])->forceFill(['retryable_failure' => $case['retryable_failure']])->save();

            $updated = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                ['name' => $model->name.' updated'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            $this->assertTrue($updated->succeeded());

            $deleted = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);
            $this->assertTrue($deleted->succeeded());
            $this->assertDatabaseMissing('ai_models', ['id' => $model->id]);
        }
    }

    public function test_identity_migration_rolls_back_and_reapplies_title_execution_columns(): void
    {
        $migration = require database_path(
            'migrations/2026_09_02_140103_add_admin_ai_execution_identity_to_title_generation_runs_table.php',
        );

        $migration->down();
        foreach ([
            'model_access_admin_id',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'resolver_policy_version',
            'error_code',
            'retryable_failure',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('title_generation_runs', $column));
        }

        $migration->up();
        foreach ([
            'model_access_admin_id',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'resolver_policy_version',
            'error_code',
            'retryable_failure',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('title_generation_runs', $column));
        }
    }

    public function test_business_mass_assignment_cannot_override_authoritative_execution_fields(): void
    {
        [$admin, $keywordLibrary, $titleLibrary, $model] = $this->fixtures();

        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $titleLibrary->id,
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'requested_count' => 1,
            'batch_size' => 1,
            'model_request_budget' => 3,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO identity'],
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 999,
            'requested_ai_model_id' => $model->id,
            'resolved_ai_model_id' => $model->id,
            'resolved_model_source' => 'personal',
            'resolver_policy_version' => 999,
            'error_code' => 'forged',
            'retryable_failure' => false,
        ]);
        $run->refresh();

        $this->assertNull($run->model_access_admin_id);
        $this->assertNull($run->model_access_admin_role);
        $this->assertNull($run->ai_config_access_version);
        $this->assertNull($run->requested_ai_model_id);
        $this->assertNull($run->resolved_ai_model_id);
        $this->assertNull($run->resolver_policy_version);
        $this->assertNull($run->error_code);
        $this->assertTrue((bool) $run->retryable_failure);
    }

    /** @return array{Admin,KeywordLibrary,TitleLibrary,AiModel} */
    private function fixtures(): array
    {
        $admin = Admin::query()->create([
            'username' => 'title_identity_admin',
            'password' => 'secret-123',
            'email' => 'title-identity@example.com',
            'display_name' => 'Title Identity Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'Title identity keywords',
            'description' => '',
            'keyword_count' => 1,
        ]);
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'GEO identity',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Title identity library',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => false,
        ]);
        $model = new AiModel([
            'name' => 'Title Identity Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('title-test-key'),
            'model_id' => 'title-identity-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return [$admin, $keywordLibrary, $titleLibrary, $model];
    }

    /** @param array<string,mixed> $attributes */
    private function admin(string $username, array $attributes = []): Admin
    {
        return Admin::query()->create(array_merge([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ], $attributes));
    }

    /** @param array<string,mixed> $attributes */
    private function model(Admin $owner, string $modelId, array $attributes = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt($modelId.'-secret'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://'.$modelId.'.test/v1',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ], $attributes));
        $model->forceFill(['owner_admin_id' => $owner->id])->save();

        return $model;
    }

    /** @return array<string,int|string> */
    private function validPayload(KeywordLibrary $keywordLibrary, AiModel $model): array
    {
        return [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => (int) $model->id,
            'title_count' => 1,
            'title_style' => 'professional',
            'custom_prompt' => '',
            'confirmed_large_run' => 1,
            'confirmed_keyword_reuse' => 1,
        ];
    }

    /** @return array<string,int|string|bool> */
    private function servicePayload(KeywordLibrary $keywordLibrary, AiModel $model): array
    {
        return [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => (int) $model->id,
            'title_count' => 1,
            'title_style' => 'professional',
            'custom_prompt' => '',
            'confirmed_keyword_reuse' => true,
        ];
    }
}
