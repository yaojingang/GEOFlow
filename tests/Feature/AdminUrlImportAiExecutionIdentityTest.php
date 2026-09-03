<?php

namespace Tests\Feature;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\ProcessUrlImportJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\AiWorkspace\AiCapabilityExecutor;
use App\Services\GeoFlow\UrlImportAiExecutionGuard;
use App\Services\GeoFlow\UrlImportExecutionBoundaryHook;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUrlImportAiExecutionIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_web_creation_persists_stable_execution_identity_and_requested_model_snapshot(): void
    {
        $admin = $this->admin('url-identity-owner', [
            'role' => 'super_admin',
        ]);
        $admin->forceFill(['ai_config_access_version' => 7])->save();
        $model = $this->model($admin, 'url-identity-model');
        $model->forceFill([
            'name' => 'URL identity display',
            'model_id' => 'sensitive-provider-deployment',
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'https://source.test/identity',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->assertSame((int) $admin->id, (int) $job->model_access_admin_id);
        $this->assertSame('super_admin', $job->model_access_admin_role);
        $this->assertSame(7, (int) $job->ai_config_access_version);
        $this->assertSame(1, (int) $job->resolver_policy_version);
        $this->assertSame((int) $model->id, (int) $job->requested_ai_model_id);
        $this->assertStringContainsString('URL identity display', (string) $job->requested_ai_model_snapshot);
        $this->assertStringNotContainsString('sensitive-provider-deployment', (string) $job->requested_ai_model_snapshot);
        $this->assertStringNotContainsString('url-import-secret', (string) $job->requested_ai_model_snapshot);
        $this->assertNull($job->resolved_ai_model_id);
        $this->assertNull($job->resolved_model_source);
        $this->assertStringNotContainsString('url-import-secret', $job->toJson());
    }

    public function test_ai_workspace_creation_persists_the_calling_admin_identity(): void
    {
        $admin = $this->admin('url-workspace-admin');
        $admin->forceFill(['ai_config_access_version' => 9])->save();
        $model = $this->model($admin, 'url-workspace-model');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/workspace', $requestedModels);

        $result = app(AiCapabilityExecutor::class)->executeRegisteredAction(
            'url_import.preview',
            ['url' => 'https://source.test/workspace'],
            $admin,
            'url-workspace-execution',
        );

        $job = UrlImportJob::query()->firstOrFail();
        $this->assertSame((int) $job->id, (int) $result->payload['job_id']);
        $this->assertSame((int) $admin->id, (int) $job->model_access_admin_id);
        $this->assertSame(9, (int) $job->ai_config_access_version);
        $this->assertSame((int) $model->id, (int) $job->requested_ai_model_id);
        $this->assertSame('completed', $job->status, (string) $job->error_message);
        $this->assertSame(['url-workspace-model'], array_values(array_unique($requestedModels)));
    }

    public function test_cli_fails_closed_when_historical_job_has_no_execution_identity(): void
    {
        $admin = $this->admin('legacy-url-operator', ['role' => 'super_admin']);
        $this->model($admin, 'legacy-url-model');
        $job = UrlImportJob::query()->create([
            'url' => 'https://source.test/legacy',
            'normalized_url' => 'https://source.test/legacy',
            'source_domain' => 'source.test',
            'page_title' => '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => '{}',
            'result_json' => '',
            'error_message' => '',
            'created_by' => $admin->username,
        ]);
        Http::fake();

        $this->artisan('geoflow:process-url-import', ['jobId' => $job->id])
            ->expectsOutput('URL import job failed: '.AiModelAccessException::AI_CONFIG_ACCESS_REVOKED)
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseCount((new AiModelUsageEvent)->getTable(), 0);
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $job->error_code);
        $this->assertFalse($job->retryable_failure);
        $this->assertSame('', (string) $job->result_json);
    }

    public function test_a_historical_job_with_backfilled_identity_records_each_provider_call(): void
    {
        $admin = $this->admin('legacy-url-backfilled-operator', ['role' => 'super_admin']);
        $model = $this->model($admin, 'legacy-url-backfilled-model');
        $job = UrlImportJob::query()->create([
            'url' => 'https://source.test/legacy-backfilled',
            'normalized_url' => 'https://source.test/legacy-backfilled',
            'source_domain' => 'source.test',
            'page_title' => '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => '{}',
            'result_json' => '',
            'error_message' => '',
            'created_by' => $admin->username,
        ]);
        DB::transaction(function () use ($admin, $job, $model): void {
            $job->forceFill(app(UrlImportAiExecutionGuard::class)->snapshotForCreation($admin, $model))->save();
        });
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/legacy-backfilled', $requestedModels);

        $processed = app(UrlImportProcessingService::class)->process($job->fresh());

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $processed->status, (string) $processed->error_message);
        $this->assertCount(4, $events);
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_SUCCEEDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame([$admin->id], $events->pluck('execution_admin_id')->unique()->values()->all());
    }

    public function test_processing_uses_personal_model_before_shared_and_excludes_peer_and_system_models(): void
    {
        $provider = $this->admin('url-shared-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-execution-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $peer = $this->admin('url-peer-admin');
        $personal = $this->model($admin, 'url-personal-model', ['failover_priority' => 90]);
        $this->model($provider, 'url-shared-model', ['failover_priority' => 1]);
        $this->model($peer, 'url-peer-model', ['failover_priority' => 1]);
        $this->model($provider, 'url-system-model', [
            'failover_priority' => 1,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $job = $this->executionJob($admin, $personal, 'https://source.test/personal');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/personal', $requestedModels);

        $processed = app(UrlImportProcessingService::class)->process($job);

        $this->assertSame(
            'completed',
            $processed->status,
            (string) $processed->error_message.' | '.json_encode($requestedModels).' | '.json_encode($processed->logs()->pluck('message')->all()),
        );
        $this->assertSame(['url-personal-model'], array_values(array_unique($requestedModels)));
        $this->assertSame((int) $personal->id, (int) $processed->resolved_ai_model_id);
        $this->assertSame('personal', $processed->resolved_model_source);
        $this->assertStringContainsString('url-personal-model', (string) $processed->resolved_ai_model_snapshot);
        $this->assertStringNotContainsString('url-import-secret', (string) $processed->resolved_ai_model_snapshot);
        $this->assertNotNull($processed->model_resolved_at);
    }

    public function test_transient_personal_failure_uses_shared_fallback_and_records_shared_source(): void
    {
        $provider = $this->admin('url-transient-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-transient-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'url-transient-personal', [
            'api_url' => 'https://personal-ai.test/v1',
        ]);
        $shared = $this->model($provider, 'url-transient-shared', [
            'api_url' => 'https://shared-ai.test/v1',
        ]);
        $job = $this->executionJob($admin, $personal, 'https://source.test/shared-fallback');
        $requestedModels = [];
        $this->fakeSuccessfulImport(
            'https://source.test/shared-fallback',
            $requestedModels,
            transientModel: 'url-transient-personal',
        );

        $processed = app(UrlImportProcessingService::class)->process($job);

        $this->assertSame('completed', $processed->status, (string) $processed->error_message);
        $this->assertSame(
            ['url-transient-personal', 'url-transient-shared'],
            array_values(array_unique($requestedModels)),
        );
        $this->assertSame((int) $shared->id, (int) $processed->resolved_ai_model_id);
        $this->assertSame('shared', $processed->resolved_model_source);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(7, $events);
        $this->assertSame(
            [
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
            ],
            $events->pluck('status')->all(),
        );
        $this->assertSame([$personal->id], $events->take(3)->pluck('ai_model_id')->unique()->values()->all());
        $this->assertSame(['personal'], $events->take(3)->pluck('model_source')->unique()->values()->all());
        $this->assertSame([$shared->id], $events->skip(3)->pluck('ai_model_id')->unique()->values()->all());
        $this->assertSame(['shared'], $events->skip(3)->pluck('model_source')->unique()->values()->all());
        $this->assertSame([$admin->id], $events->pluck('execution_admin_id')->unique()->values()->all());
        $this->assertSame([$admin->id, $provider->id], $events->pluck('config_owner_admin_id')->unique()->values()->all());
    }

    public function test_url_import_records_each_successful_analysis_stage_after_preview_commit(): void
    {
        $admin = $this->admin('url-ledger-success-admin');
        $model = $this->model($admin, 'url-ledger-success-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/ledger-success');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/ledger-success', $requestedModels);

        $processed = app(UrlImportProcessingService::class)->process($job);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $processed->status, (string) $processed->error_message);
        $this->assertCount(4, $events);
        $this->assertSame(
            ['clean', 'knowledge', 'keywords', 'titles'],
            $events->map(static fn (AiModelUsageEvent $event): string => (string) Str::of($event->call_key)->between('.s', '.p'))->all(),
        );
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_SUCCEEDED),
            $events->pluck('status')->all(),
        );
        $this->assertCount(1, $events->pluck('request_id')->unique());
        $this->assertTrue(Str::isUuid((string) $events->first()->request_id));
        $this->assertSame([$model->id], $events->pluck('ai_model_id')->unique()->values()->all());
        $this->assertSame([$admin->id], $events->pluck('execution_admin_id')->unique()->values()->all());
        $this->assertSame([$admin->id], $events->pluck('config_owner_admin_id')->unique()->values()->all());
        $this->assertSame(['personal'], $events->pluck('model_source')->unique()->values()->all());
        $this->assertSame([(int) $admin->ai_config_access_version], $events->pluck('ai_config_access_version')->unique()->values()->all());
        $this->assertSame(['url_import.analysis'], $events->pluck('operation')->unique()->values()->all());
        $this->assertSame(['url_import'], $events->pluck('business_source')->unique()->values()->all());
        $this->assertSame([UrlImportJob::class], $events->pluck('source_type')->unique()->values()->all());
        $this->assertSame([(string) $job->id], $events->pluck('source_id')->unique()->values()->all());
        $this->assertSame(1, (int) $processed->execution_attempt);
        $this->assertTrue($events->every(static fn (AiModelUsageEvent $event): bool => strlen((string) $event->call_key) <= 100));
        $serialized = $events->toJson();
        $this->assertStringNotContainsString('url-import-secret', $serialized);
        $this->assertStringNotContainsString('source.test/ledger-success', $serialized);
        $this->assertStringNotContainsString('Identity-safe import content', $serialized);
    }

    public function test_late_stage_validation_failure_discards_the_first_try_before_repeating_all_stages(): void
    {
        $admin = $this->admin('url-ledger-retry-admin');
        $model = $this->model($admin, 'url-ledger-retry-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/ledger-retry');
        $successfulPayloads = [
            [
                'clean_title' => 'URL retry source',
                'clean_summary' => 'Retry-safe import.',
                'clean_text' => 'Retry-safe import content.',
                'core_business' => [],
                'entities' => [],
                'facts' => ['Retry-safe import content.'],
                'noise_removed' => [],
            ],
            [
                'summary' => 'Retry-safe import.',
                'library_name' => 'Retry import',
                'knowledge_markdown' => "# Retry import\n\nRetry-safe import content.",
            ],
            ['keywords' => ['重试采集']],
            ['titles' => ['重试安全的 URL 导入']],
        ];
        $providerCall = 0;
        Http::fake(function ($request) use (&$providerCall, $successfulPayloads) {
            if ($request->url() === 'https://source.test/ledger-retry') {
                return Http::response('<html><body><main>Retry-safe import content.</main></body></html>');
            }

            $indexWithinTry = $providerCall % 4;
            $providerCall++;
            $content = $providerCall === 4
                ? ''
                : json_encode($successfulPayloads[$indexWithinTry], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return Http::response(['choices' => [['message' => ['content' => $content]]]]);
        });

        $processed = app(UrlImportProcessingService::class)->process($job);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $processed->status, (string) $processed->error_message);
        $this->assertSame(8, $providerCall);
        $this->assertSame(
            [
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
            ],
            $events->pluck('status')->all(),
        );
        $invalidTitle = $events->first(
            static fn (AiModelUsageEvent $event): bool => str_contains((string) $event->call_key, '.t1.stitles.'),
        );
        $this->assertInstanceOf(AiModelUsageEvent::class, $invalidTitle);
        $this->assertSame('url_import_invalid_model_output', $invalidTitle->error_code);
        $this->assertTrue($events->take(4)->every(static fn (AiModelUsageEvent $event): bool => str_contains((string) $event->call_key, '.t1.')));
        $this->assertTrue($events->skip(4)->every(static fn (AiModelUsageEvent $event): bool => str_contains((string) $event->call_key, '.t2.')));
        $this->assertCount(1, $events->pluck('request_id')->unique());
    }

    public function test_permanent_provider_failure_never_retries_or_uses_shared_fallback(): void
    {
        $provider = $this->admin('url-permanent-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-permanent-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'url-permanent-personal');
        $this->model($provider, 'url-permanent-shared');
        $job = $this->executionJob($admin, $personal, 'https://source.test/permanent');
        $requestedModels = [];

        Http::fake(function ($request) use (&$requestedModels) {
            if ($request->url() === 'https://source.test/permanent') {
                return Http::response('<html><body><main>Permanent provider failure.</main></body></html>');
            }

            $requestedModels[] = (string) $request['model'];

            return Http::response(['error' => ['message' => 'invalid api key']], 401);
        });

        $processed = app(UrlImportProcessingService::class)->process($job);

        $this->assertSame('failed', $processed->status);
        $this->assertSame(PermanentAiProviderException::ERROR_CODE, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
        $this->assertSame(['url-permanent-personal'], $requestedModels);
        $this->assertSame('', (string) $processed->result_json);
        $providerEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $providerEvent->status);
        $this->assertSame($personal->id, $providerEvent->ai_model_id);

        Http::fake();
        $this->artisan('geoflow:process-url-import', ['jobId' => $job->id])
            ->expectsOutput('URL import job cannot be retried: '.PermanentAiProviderException::ERROR_CODE)
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_missing_personal_model_credentials_never_uses_shared_fallback(): void
    {
        $provider = $this->admin('url-missing-key-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-missing-key-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'url-missing-key-personal');
        $this->model($provider, 'url-missing-key-shared');
        $job = $this->executionJob($admin, $personal, 'https://source.test/missing-key');
        $personal->forceFill(['api_key' => ''])->save();
        Http::fake(function ($request) {
            if ($request->url() === 'https://source.test/missing-key') {
                return Http::response('<html><body><main>Missing key.</main></body></html>');
            }

            return Http::response(['unexpected' => true]);
        });

        $processed = app(UrlImportProcessingService::class)->process($job);

        $this->assertSame('failed', $processed->status);
        $this->assertSame(AiModelAccessException::AI_MODEL_UNAVAILABLE, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
        Http::assertSentCount(1);
        $this->assertDatabaseCount((new AiModelUsageEvent)->getTable(), 0);
    }

    public function test_web_run_refuses_a_permanently_failed_job_without_external_requests(): void
    {
        $admin = $this->admin('url-web-permanent-admin', ['role' => 'super_admin']);
        $model = $this->model($admin, 'url-web-permanent-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/web-permanent');
        $job->forceFill([
            'status' => 'failed',
            'error_code' => PermanentAiProviderException::ERROR_CODE,
            'error_message' => PermanentAiProviderException::ERROR_CODE,
            'retryable_failure' => false,
            'finished_at' => now(),
        ])->save();
        Http::fake();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => $job->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', PermanentAiProviderException::ERROR_CODE)
            ->assertJsonPath('retryable_failure', false);

        Http::assertNothingSent();
    }

    public function test_web_actor_cannot_run_or_commit_another_admins_job(): void
    {
        $owner = $this->admin('url-web-job-owner');
        $peer = $this->admin('url-web-job-peer', ['role' => 'super_admin']);
        $model = $this->model($owner, 'url-web-owner-model');
        $job = $this->executionJob($owner, $model, 'https://source.test/peer-run');
        Http::fake();

        $this->actingAs($peer, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => $job->id]))
            ->assertNotFound();
        $this->actingAs($peer, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => $job->id]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('queued', $job->refresh()->status);
        $this->assertDatabaseCount((new KnowledgeBase)->getTable(), 0);
        $this->assertDatabaseCount((new KeywordLibrary)->getTable(), 0);
        $this->assertDatabaseCount((new TitleLibrary)->getTable(), 0);
    }

    public function test_access_revoked_after_provider_response_discards_the_preview_result(): void
    {
        $admin = $this->admin('url-revoked-admin');
        $model = $this->model($admin, 'url-revoked-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/revoked');
        $requestedModels = [];
        $this->fakeSuccessfulImport(
            'https://source.test/revoked',
            $requestedModels,
            afterAiResponse: function (int $responseIndex) use ($admin): void {
                if ($responseIndex === 4) {
                    Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');
                }
            },
        );

        $processed = app(UrlImportProcessingService::class)->process($job);

        $this->assertSame('failed', $processed->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
        $this->assertSame('', (string) $processed->result_json);
        $this->assertNull($processed->resolved_ai_model_id);
        $this->assertSame(['url-revoked-model'], array_values(array_unique($requestedModels)));
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(4, $events);
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_REVOKED),
            $events->pluck('status')->all(),
        );
        $this->assertSame(
            array_fill(0, 4, AiModelAccessException::AI_CONFIG_ACCESS_REVOKED),
            $events->pluck('error_code')->all(),
        );
    }

    public function test_preview_transaction_rollback_discards_all_returned_provider_results(): void
    {
        $admin = $this->admin('url-preview-rollback-admin');
        $model = $this->model($admin, 'url-preview-rollback-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/preview-rollback');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/preview-rollback', $requestedModels);
        $this->app->instance(
            UrlImportExecutionBoundaryHook::class,
            new class extends UrlImportExecutionBoundaryHook
            {
                public function beforePreviewCommit(UrlImportJob $job): void
                {
                    throw new \RuntimeException('preview transaction rejected');
                }
            },
        );

        $processed = app(UrlImportProcessingService::class)->process($job);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('failed', $processed->status);
        $this->assertSame('', (string) $processed->result_json);
        $this->assertSame($model->id, $processed->resolved_ai_model_id);
        $this->assertCount(4, $events);
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_DISCARDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame(
            array_fill(0, 4, 'url_import_result_not_committed'),
            $events->pluck('error_code')->all(),
        );
    }

    public function test_post_commit_refresh_failure_keeps_the_durable_preview_usage_succeeded(): void
    {
        $admin = $this->admin('url-post-commit-refresh-admin');
        $model = $this->model($admin, 'url-post-commit-refresh-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/post-commit-refresh');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/post-commit-refresh', $requestedModels);
        $refreshFailed = false;
        UrlImportJob::retrieved(static function (UrlImportJob $retrieved) use (&$refreshFailed): void {
            if (! $refreshFailed && (string) $retrieved->status === 'completed') {
                $refreshFailed = true;

                throw new \RuntimeException('simulated post-commit refresh failure');
            }
        });

        $processed = app(UrlImportProcessingService::class)->process($job);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertTrue($refreshFailed);
        $this->assertSame('completed', $processed->status);
        $this->assertNotSame('', (string) $processed->result_json);
        $this->assertCount(4, $events);
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_SUCCEEDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame(array_fill(0, 4, null), $events->pluck('error_code')->all());
    }

    public function test_cancelled_lease_discards_returned_results_without_marking_an_access_revocation(): void
    {
        $admin = $this->admin('url-ledger-cancel-admin');
        $model = $this->model($admin, 'url-ledger-cancel-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/ledger-cancel');
        $requestedModels = [];
        $this->fakeSuccessfulImport(
            'https://source.test/ledger-cancel',
            $requestedModels,
            afterAiResponse: function (int $responseIndex) use ($job): void {
                if ($responseIndex !== 4) {
                    return;
                }
                UrlImportJob::query()->whereKey($job->id)->update([
                    'status' => 'failed',
                    'retryable_failure' => false,
                    'execution_lease_token' => null,
                    'lease_expires_at' => null,
                ]);
            },
        );

        $processed = app(UrlImportProcessingService::class)->process($job);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('failed', $processed->status);
        $this->assertFalse($processed->retryable_failure);
        $this->assertSame('', (string) $processed->result_json);
        $this->assertCount(4, $events);
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_DISCARDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame(
            array_fill(0, 4, 'url_import_execution_invalidated'),
            $events->pluck('error_code')->all(),
        );
    }

    public function test_worker_retry_uses_a_new_claim_request_and_persistent_attempt_ordinal(): void
    {
        $admin = $this->admin('url-ledger-worker-retry-admin');
        $model = $this->model($admin, 'url-ledger-worker-retry-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/ledger-worker-retry');
        $providerEnabled = false;
        $successIndex = 0;
        $successfulPayloads = [
            [
                'clean_title' => 'Worker retry source',
                'clean_summary' => 'Worker retry import.',
                'clean_text' => 'Worker retry import content.',
                'core_business' => [],
                'entities' => [],
                'facts' => ['Worker retry import content.'],
                'noise_removed' => [],
            ],
            [
                'summary' => 'Worker retry import.',
                'library_name' => 'Worker retry import',
                'knowledge_markdown' => "# Worker retry import\n\nWorker retry import content.",
            ],
            ['keywords' => ['任务重试']],
            ['titles' => ['任务重试后的 URL 导入']],
        ];
        Http::fake(function ($request) use (&$providerEnabled, &$successIndex, $successfulPayloads) {
            if ($request->url() === 'https://source.test/ledger-worker-retry') {
                return Http::response('<html><body><main>Worker retry import content.</main></body></html>');
            }
            if (! $providerEnabled) {
                return Http::response(['error' => ['message' => 'temporary provider failure']], 503);
            }
            $payload = $successfulPayloads[$successIndex] ?? end($successfulPayloads);
            $successIndex++;

            return Http::response([
                'choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
            ]);
        });
        $processing = app(UrlImportProcessingService::class);

        (new ProcessUrlImportJob((int) $job->id))->handle($processing);
        $first = $job->fresh();
        $this->assertSame('failed', $first->status);
        $this->assertTrue($first->retryable_failure);
        $providerEnabled = true;
        (new ProcessUrlImportJob((int) $job->id))->handle($processing);
        $completed = $job->fresh();

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $completed->status, (string) $completed->error_message);
        $this->assertSame(2, (int) $completed->execution_attempt);
        $this->assertCount(7, $events);
        $this->assertCount(2, $events->pluck('request_id')->unique());
        $this->assertCount(7, $events->pluck('call_key')->unique());
        $this->assertSame(
            [
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
            ],
            $events->pluck('status')->all(),
        );
        $this->assertTrue($events->take(3)->every(static fn (AiModelUsageEvent $event): bool => str_starts_with((string) $event->call_key, 'a1.')));
        $this->assertTrue($events->skip(3)->every(static fn (AiModelUsageEvent $event): bool => str_starts_with((string) $event->call_key, 'a2.')));
    }

    public function test_inactive_shared_owner_blocks_processing_before_any_external_request(): void
    {
        $provider = $this->admin('url-inactive-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-shared-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $shared = $this->model($provider, 'url-inactive-shared-model');
        $job = $this->executionJob($admin, $shared, 'https://source.test/inactive-owner');
        $provider->update(['status' => 'disabled']);
        Http::fake();

        $processed = app(UrlImportProcessingService::class)->process($job);

        Http::assertNothingSent();
        $this->assertSame('failed', $processed->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_OWNER_INACTIVE, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
        $this->assertSame('', (string) $processed->result_json);
    }

    public function test_inactive_execution_admin_blocks_processing_before_any_external_request(): void
    {
        $admin = $this->admin('url-inactive-execution-admin');
        $model = $this->model($admin, 'url-inactive-execution-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/inactive-admin');
        $admin->update(['status' => 'disabled']);
        Http::fake();

        $processed = app(UrlImportProcessingService::class)->process($job);

        Http::assertNothingSent();
        $this->assertSame('failed', $processed->status);
        $this->assertSame(AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
    }

    public function test_stale_execution_lease_cannot_record_a_model_result(): void
    {
        $admin = $this->admin('url-stale-lease-admin');
        $model = $this->model($admin, 'url-stale-lease-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/stale-lease');
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
        ])->save();
        $staleWorkerJob = $job->fresh();
        UrlImportJob::query()->whereKey($job->id)->update([
            'execution_lease_token' => '22222222-2222-4222-8222-222222222222',
        ]);

        try {
            app(UrlImportAiExecutionGuard::class)->recordResolvedModel($staleWorkerJob, $model);
            $this->fail('Expected the stale URL import execution lease to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $this->assertNull($job->refresh()->resolved_ai_model_id);
        $this->assertSame(
            '22222222-2222-4222-8222-222222222222',
            $job->execution_lease_token,
        );
    }

    public function test_an_active_execution_lease_cannot_be_stolen_by_another_worker(): void
    {
        $admin = $this->admin('url-active-lease-admin');
        $model = $this->model($admin, 'url-active-lease-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/active-lease');
        $lease = '11111111-1111-4111-8111-111111111111';
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => $lease,
        ])->save();
        Http::fake();

        $processed = app(UrlImportProcessingService::class)->process($job);

        Http::assertNothingSent();
        $this->assertSame('running', $processed->status);
        $this->assertSame($lease, $processed->execution_lease_token);
        $this->assertSame('', (string) $processed->result_json);
    }

    public function test_a_stale_queued_instance_cannot_reclaim_a_terminal_job(): void
    {
        $admin = $this->admin('url-stale-terminal-admin');
        $model = $this->model($admin, 'url-stale-terminal-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/stale-terminal');
        $staleQueuedJob = $job->fresh();
        $terminalResult = json_encode(['import' => ['status' => 'preview']], JSON_THROW_ON_ERROR);
        UrlImportJob::query()->whereKey($job->id)->update([
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
            'result_json' => $terminalResult,
            'execution_lease_token' => null,
            'finished_at' => now(),
        ]);
        Http::fake();

        $processed = app(UrlImportProcessingService::class)->process($staleQueuedJob);

        Http::assertNothingSent();
        $this->assertSame('completed', $processed->status);
        $this->assertSame($terminalResult, $processed->result_json);
        $this->assertNull($processed->execution_lease_token);
    }

    public function test_an_empty_worker_lease_cannot_bypass_the_running_lease_guard(): void
    {
        $admin = $this->admin('url-empty-lease-admin');
        $model = $this->model($admin, 'url-empty-lease-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/empty-lease');
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
        ])->save();
        $emptyLeaseWorker = $job->fresh();
        $emptyLeaseWorker->forceFill(['execution_lease_token' => null]);

        try {
            app(UrlImportAiExecutionGuard::class)->recordResolvedModel($emptyLeaseWorker, $model);
            $this->fail('Expected an empty URL import worker lease to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $this->assertNull($job->refresh()->resolved_ai_model_id);
    }

    public function test_an_expired_lease_is_recovered_then_reauthorized_before_external_work(): void
    {
        $admin = $this->admin('url-expired-lease-admin');
        $model = $this->model($admin, 'url-expired-lease-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/expired-lease');
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $admin->increment('ai_config_access_version');
        Http::fake();

        $processed = app(UrlImportProcessingService::class)->process($job);

        Http::assertNothingSent();
        $this->assertSame('failed', $processed->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $processed->error_code);
        $this->assertFalse($processed->retryable_failure);
        $this->assertNull($processed->execution_lease_token);
        $this->assertNull($processed->lease_expires_at);
    }

    public function test_provider_errors_are_sanitized_in_job_and_log_storage(): void
    {
        $admin = $this->admin('url-sanitize-admin');
        $model = $this->model($admin, 'url-sanitize-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/sanitize');
        $sensitive = 'https://provider-secret.test/v1?token=url-secret Authorization: Bearer bearer-secret api_key=plain-secret sk-super-secret-value';
        Http::fake(function ($request) use ($sensitive) {
            if ($request->url() === 'https://source.test/sanitize') {
                return Http::response('<html><body><main>Sanitize error content.</main></body></html>');
            }

            return Http::response(['error' => ['message' => $sensitive]], 503);
        });

        $processed = app(UrlImportProcessingService::class)->process($job);
        $storedState = json_encode([
            'error_message' => $processed->error_message,
            'logs' => $processed->logs()->pluck('message')->all(),
        ], JSON_THROW_ON_ERROR);

        foreach (['provider-secret.test', 'url-secret', 'bearer-secret', 'plain-secret', 'super-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $storedState);
        }
        $this->assertLessThanOrEqual(500, mb_strlen((string) $processed->error_message, 'UTF-8'));
    }

    public function test_commit_revalidates_access_and_writes_no_content_after_revocation(): void
    {
        $admin = $this->admin('url-commit-revoked-admin');
        $model = $this->model($admin, 'url-commit-revoked-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/commit-revoked');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/commit-revoked', $requestedModels);
        $processed = app(UrlImportProcessingService::class)->process($job);
        $this->assertSame('completed', $processed->status, (string) $processed->error_message);
        Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');

        try {
            app(UrlImportProcessingService::class)->commit($processed);
            $this->fail('Expected URL import commit access to be revoked.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $this->assertDatabaseCount((new KnowledgeBase)->getTable(), 0);
        $this->assertDatabaseCount((new KeywordLibrary)->getTable(), 0);
        $this->assertDatabaseCount((new TitleLibrary)->getTable(), 0);
        $storedResult = json_decode((string) $processed->refresh()->result_json, true);
        $this->assertSame('preview', data_get($storedResult, 'import.status'));
    }

    public function test_commit_rejects_a_stale_preview_when_the_locked_result_has_changed(): void
    {
        $admin = $this->admin('url-stale-commit-admin');
        $model = $this->model($admin, 'url-stale-commit-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/stale-commit');
        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/stale-commit', $requestedModels);
        $completed = app(UrlImportProcessingService::class)->process($job);
        $stalePreview = $completed->fresh();
        $changedResult = json_decode((string) $completed->result_json, true, flags: JSON_THROW_ON_ERROR);
        data_set($changedResult, 'analysis.summary', 'A concurrently replaced preview.');
        UrlImportJob::query()->whereKey($completed->id)->update([
            'result_json' => json_encode($changedResult, JSON_THROW_ON_ERROR),
        ]);

        try {
            app(UrlImportProcessingService::class)->commit($stalePreview);
            $this->fail('Expected a stale URL import preview to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $this->assertDatabaseCount((new KnowledgeBase)->getTable(), 0);
        $this->assertDatabaseCount((new KeywordLibrary)->getTable(), 0);
        $this->assertDatabaseCount((new TitleLibrary)->getTable(), 0);
        $this->assertSame('completed', $completed->refresh()->status);
    }

    public function test_url_jobs_block_admin_and_model_deletion_while_active_and_preserve_safe_snapshots_after_completion(): void
    {
        $provider = $this->admin('url-deletion-lifecycle-provider', ['role' => 'super_admin']);
        $admin = $this->admin('url-deletion-lifecycle-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $model = $this->model($provider, 'url-deletion-lifecycle-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/delete-lifecycle');

        $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($admin);
        $this->assertSame(0, $dependencies->ownedModelCount);
        $this->assertSame(1, $dependencies->pendingTaskCounts['url_import_jobs']);
        $this->assertSame(1, $dependencies->executionUrlImportJobCount);
        $this->assertTrue($dependencies->blocksDeletion());
        $blocked = app(AdminAiModelMutationService::class)->delete($provider, (int) $model->id);
        $this->assertSame('task', $blocked->error);
        $this->assertSame(1, $blocked->dependencyCount);
        $this->assertModelExists($model);

        $requestedModels = [];
        $this->fakeSuccessfulImport('https://source.test/delete-lifecycle', $requestedModels);
        $job = app(UrlImportProcessingService::class)->process($job);
        $this->assertSame('completed', $job->status, (string) $job->error_message);

        $deleted = app(AdminAiModelMutationService::class)->delete($provider, (int) $model->id);
        $this->assertTrue($deleted->succeeded());
        $job->refresh();
        $this->assertNull($job->requested_ai_model_id);
        $this->assertNull($job->resolved_ai_model_id);
        $this->assertStringContainsString('url-deletion-lifecycle-model', (string) $job->requested_ai_model_snapshot);
        $this->assertStringContainsString('url-deletion-lifecycle-model', (string) $job->resolved_ai_model_snapshot);

        $summary = app(UrlImportProcessingService::class)->commit($job);
        $this->assertGreaterThan(0, $summary['knowledge_base']);
        $this->assertSame('imported', $job->refresh()->status);
    }

    public function test_url_import_execution_identity_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_09_02_102306_add_ai_execution_identity_to_url_import_jobs_table.php');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('url_import_jobs', 'model_access_admin_id'));
            $this->assertFalse(Schema::hasColumn('url_import_jobs', 'execution_lease_token'));
            $this->assertFalse(Schema::hasColumn('url_import_jobs', 'lease_expires_at'));
            $this->assertFalse(Schema::hasColumn('url_import_jobs', 'requested_ai_model_snapshot'));

            $migration->up();
            $this->assertTrue(Schema::hasColumns('url_import_jobs', [
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'resolver_policy_version',
                'resolved_ai_model_id',
                'resolved_model_source',
                'model_resolved_at',
                'execution_lease_token',
                'lease_expires_at',
                'requested_ai_model_snapshot',
                'resolved_ai_model_snapshot',
                'error_code',
                'retryable_failure',
            ]));
        } finally {
            if (! Schema::hasColumn('url_import_jobs', 'model_access_admin_id')) {
                $migration->up();
            }
        }
    }

    public function test_url_import_execution_attempt_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_03_010000_add_execution_attempt_to_url_import_jobs_table.php');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('url_import_jobs', 'execution_attempt'));

            $migration->up();
            $this->assertTrue(Schema::hasColumn('url_import_jobs', 'execution_attempt'));
        } finally {
            if (! Schema::hasColumn('url_import_jobs', 'execution_attempt')) {
                $migration->up();
            }
        }
    }

    private function admin(string $username, array $attributes = []): Admin
    {
        return Admin::query()->create(array_merge([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ], $attributes));
    }

    private function model(Admin $owner, string $modelId, array $attributes = []): AiModel
    {
        $model = AiModel::query()->create(array_merge([
            'name' => $modelId,
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('url-import-secret'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 10,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $attributes));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $attributes['access_scope'] ?? AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function executionJob(Admin $admin, AiModel $requestedModel, string $url): UrlImportJob
    {
        return DB::transaction(function () use ($admin, $requestedModel, $url): UrlImportJob {
            $identity = app(UrlImportAiExecutionGuard::class)->snapshotForCreation($admin, $requestedModel);

            return UrlImportJob::query()->create(array_merge([
                'url' => $url,
                'normalized_url' => $url,
                'source_domain' => 'source.test',
                'page_title' => '',
                'status' => 'queued',
                'current_step' => 'queued',
                'progress_percent' => 0,
                'options_json' => '{}',
                'result_json' => '',
                'error_message' => '',
                'created_by' => $admin->username,
            ], $identity));
        });
    }

    /** @param list<string> $requestedModels */
    private function fakeSuccessfulImport(
        string $sourceUrl,
        array &$requestedModels,
        ?string $transientModel = null,
        ?callable $afterAiResponse = null,
    ): void {
        $aiResponses = [
            [
                'clean_title' => 'URL identity source',
                'clean_summary' => 'Identity-safe import.',
                'clean_text' => 'Identity-safe import content.',
                'core_business' => [],
                'entities' => [],
                'facts' => ['Identity-safe import content.'],
                'noise_removed' => [],
            ],
            [
                'summary' => 'Identity-safe import.',
                'library_name' => 'Identity import',
                'knowledge_markdown' => "# Identity import\n\nIdentity-safe import content.",
            ],
            ['keywords' => ['身份采集']],
            ['titles' => ['身份安全的 URL 导入']],
        ];
        $responseIndex = 0;

        Http::fake(function ($request) use ($sourceUrl, &$requestedModels, $aiResponses, &$responseIndex, $transientModel, $afterAiResponse) {
            if ($request->url() === $sourceUrl) {
                return Http::response(
                    '<html><head><title>URL identity source</title></head><body><main>Identity-safe import content.</main></body></html>',
                    200,
                    ['Content-Type' => 'text/html; charset=utf-8'],
                );
            }

            $requestedModels[] = (string) $request['model'];
            if ($transientModel !== null && (string) $request['model'] === $transientModel) {
                return Http::response(['error' => ['message' => 'temporary upstream failure']], 503);
            }
            $payload = $aiResponses[$responseIndex] ?? end($aiResponses);
            $responseIndex++;
            if ($afterAiResponse !== null) {
                $afterAiResponse($responseIndex);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]);
        });
    }
}
