<?php

namespace Tests\Feature;

use App\Jobs\ProcessUrlImportJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportAiExecutionGuard;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Services\GeoFlow\UrlImportRecoveryDispatcher;
use App\Services\GeoFlow\UrlImportRecoveryService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class UrlImportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_command_recovers_empty_and_expired_leases_and_the_jobs_complete(): void
    {
        Queue::fake();
        $admin = $this->admin('url-recovery-admin');
        $model = $this->model($admin, 'url-recovery-model');
        $emptyLease = $this->executionJob($admin, $model, 'https://source.test/recovery-empty');
        $emptyLease->forceFill([
            'status' => 'running',
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ])->save();
        $expiredLease = $this->executionJob($admin, $model, 'https://source.test/recovery-expired');
        $expiredLease->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('geoflow:recover-url-imports', ['--limit' => 10])
            ->expectsOutput('Recovered stale URL imports: 2; dispatch failures: 0')
            ->assertSuccessful();

        Queue::assertPushed(ProcessUrlImportJob::class, 2);
        $this->assertSame('queued', $emptyLease->refresh()->status);
        $this->assertNull($emptyLease->execution_lease_token);
        $this->assertSame('queued', $expiredLease->refresh()->status);
        $this->assertNull($expiredLease->execution_lease_token);

        $this->fakeSuccessfulImports();
        Queue::pushed(ProcessUrlImportJob::class)
            ->each(fn (ProcessUrlImportJob $job) => $job->handle(app(UrlImportProcessingService::class)));

        $this->assertSame('completed', $emptyLease->refresh()->status, (string) $emptyLease->error_message);
        $this->assertSame('completed', $expiredLease->refresh()->status, (string) $expiredLease->error_message);
    }

    public function test_dispatch_failure_restores_a_retryable_stale_state_for_the_next_run(): void
    {
        $admin = $this->admin('url-recovery-failure-admin');
        $model = $this->model($admin, 'url-recovery-failure-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/recovery-failure');
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ])->save();
        $this->app->instance(UrlImportRecoveryDispatcher::class, new class extends UrlImportRecoveryDispatcher
        {
            public function dispatch(int $jobId): void
            {
                throw new RuntimeException('queue endpoint secret must not persist');
            }
        });

        $this->artisan('geoflow:recover-url-imports', ['--limit' => 10])
            ->expectsOutput('Recovered stale URL imports: 0; dispatch failures: 1')
            ->assertFailed();

        $job->refresh();
        $this->assertSame('running', $job->status);
        $this->assertNull($job->execution_lease_token);
        $this->assertNull($job->lease_expires_at);
        $this->assertSame('url_import_recovery_dispatch_failed', $job->error_code);
        $this->assertTrue($job->retryable_failure);
        $this->assertStringNotContainsString('secret', (string) $job->error_message);

        $this->app->forgetInstance(UrlImportRecoveryDispatcher::class);
        Queue::fake();
        $this->artisan('geoflow:recover-url-imports', ['--limit' => 10])
            ->expectsOutput('Recovered stale URL imports: 1; dispatch failures: 0')
            ->assertSuccessful();
        Queue::assertPushed(ProcessUrlImportJob::class, 1);
    }

    public function test_overlapping_reconcilers_dispatch_each_stale_job_once(): void
    {
        Queue::fake();
        $admin = $this->admin('url-recovery-once-admin');
        $model = $this->model($admin, 'url-recovery-once-model');
        $job = $this->executionJob($admin, $model, 'https://source.test/recovery-once');
        $job->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $nestedResult = null;
        $dispatches = 0;
        $recovery = null;
        $this->app->instance(UrlImportRecoveryDispatcher::class, new class(function () use (&$recovery, &$nestedResult, &$dispatches): void {
            $dispatches++;
            $nestedResult = $recovery->reconcile(10);
        }) extends UrlImportRecoveryDispatcher
        {

            public function __construct(private readonly \Closure $onDispatch) {}

            public function dispatch(int $jobId): void
            {
                ($this->onDispatch)();
            }
        });
        $recovery = app(UrlImportRecoveryService::class);

        $first = $recovery->reconcile(10);
        $second = $recovery->reconcile(10);

        $this->assertSame(['recovered' => 1, 'dispatch_failed' => 0], $first);
        $this->assertSame(['recovered' => 0, 'dispatch_failed' => 0], $nestedResult);
        $this->assertSame(['recovered' => 0, 'dispatch_failed' => 0], $second);
        $this->assertSame(1, $dispatches);
        Queue::assertNothingPushed();
        $this->assertSame('queued', $job->refresh()->status);
    }

    public function test_reconciliation_never_requeues_active_or_terminal_jobs(): void
    {
        Queue::fake();
        $admin = $this->admin('url-recovery-terminal-admin');
        $model = $this->model($admin, 'url-recovery-terminal-model');
        $active = $this->executionJob($admin, $model, 'https://source.test/recovery-active');
        $active->forceFill([
            'status' => 'running',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();
        foreach (['completed', 'imported', 'failed'] as $status) {
            $terminal = $this->executionJob($admin, $model, 'https://source.test/recovery-'.$status);
            $terminal->forceFill([
                'status' => $status,
                'execution_lease_token' => null,
                'lease_expires_at' => null,
                'retryable_failure' => false,
            ])->save();
        }

        $result = app(UrlImportRecoveryService::class)->reconcile(10);

        $this->assertSame(['recovered' => 0, 'dispatch_failed' => 0], $result);
        Queue::assertNothingPushed();
        $this->assertSame('running', $active->refresh()->status);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $active->execution_lease_token);
    }

    public function test_scheduler_registers_the_production_recovery_command(): void
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($consoleRoutes);
        $this->assertStringContainsString("Schedule::command('geoflow:recover-url-imports')", $consoleRoutes);
        $this->assertStringContainsString('->everyMinute()', $consoleRoutes);
        $this->assertStringContainsString('->onOneServer()', $consoleRoutes);
        $this->assertStringContainsString('->withoutOverlapping(2)', $consoleRoutes);
    }

    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function model(Admin $owner, string $modelId): AiModel
    {
        $model = AiModel::query()->create([
            'name' => $modelId,
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('url-recovery-secret'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 10,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
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

    private function fakeSuccessfulImports(): void
    {
        $responses = [
            ['clean_title' => 'Recovered URL', 'clean_summary' => 'Recovered.', 'clean_text' => 'Recovered content.', 'facts' => ['Recovered content.']],
            ['summary' => 'Recovered.', 'library_name' => 'Recovered URL', 'knowledge_markdown' => "# Recovered URL\n\nRecovered content."],
            ['keywords' => ['恢复任务']],
            ['titles' => ['恢复后的 URL 内容']],
        ];
        $index = 0;

        Http::fake(function ($request) use (&$index, $responses) {
            if (str_starts_with($request->url(), 'https://source.test/')) {
                return Http::response('<html><head><title>Recovered URL</title></head><body><main>Recovered content.</main></body></html>');
            }

            $payload = $responses[$index % count($responses)];
            $index++;

            return Http::response(['choices' => [['message' => [
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]]]]);
        });
    }
}
