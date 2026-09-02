<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\GeoFlow\EnterpriseKnowledgeAiExecutionGuard;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftRecoveryDispatcher;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class AdminEnterpriseKnowledgeAiExecutionIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_web_creation_freezes_ai_identity_and_queues_without_admin_credentials(): void
    {
        Queue::fake();
        $admin = $this->admin('enterprise-identity-owner', ['role' => 'super_admin']);
        $admin->forceFill(['ai_config_access_version' => 7])->save();
        $model = $this->model($admin, 'enterprise-identity-model');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => '企业身份隔离草稿',
                'content' => '# 企业背景'."\n".'企业提供知识管理服务。',
            ])
            ->assertRedirect();

        $project = EnterpriseKnowledgeProject::query()->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $project->model_access_admin_id);
        $this->assertSame('super_admin', $project->model_access_admin_role);
        $this->assertSame(7, (int) $project->ai_config_access_version);
        $this->assertSame(1, (int) $project->resolver_policy_version);
        $this->assertSame((int) $model->id, (int) $project->requested_ai_model_id);
        $this->assertNull($project->resolved_ai_model_id);
        $this->assertNull($project->resolved_model_source);

        Queue::assertPushed(
            GenerateEnterpriseKnowledgeDraftJob::class,
            fn (GenerateEnterpriseKnowledgeDraftJob $job): bool => $job->projectId === (int) $project->id
                && ! property_exists($job, 'adminId')
                && Str::isUuid((string) $job->claimLeaseToken),
        );
        $serialized = serialize(new GenerateEnterpriseKnowledgeDraftJob((int) $project->id));
        $this->assertStringNotContainsString('enterprise-identity-secret', $serialized);
        $this->assertStringNotContainsString('api_key', $serialized);
    }

    public function test_queue_fails_closed_when_a_historical_project_has_no_execution_identity(): void
    {
        $admin = $this->admin('enterprise-legacy-creator', ['role' => 'super_admin']);
        $this->model($admin, 'enterprise-legacy-model');
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '历史企业知识草稿',
            'status' => 'queued',
            'created_by_admin_id' => $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => $project->id,
            'original_name' => 'legacy.md',
            'file_type' => 'markdown',
            'content' => '# 企业背景'."\n".'历史资料。',
            'character_count' => 10,
            'sort_order' => 0,
        ]);
        Http::fake();

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        Http::assertNothingSent();
        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $project->error_code);
        $this->assertFalse($project->retryable_failure);
        $this->assertSame('', (string) $project->draft_content);
        $this->assertSame(0, EnterpriseKnowledgeRevision::query()->where(
            'enterprise_knowledge_project_id',
            $project->id,
        )->count());
    }

    public function test_ai_workspace_creation_freezes_the_calling_admin_identity(): void
    {
        $provider = $this->admin('enterprise-workspace-provider', ['role' => 'super_admin']);
        $admin = $this->admin('enterprise-workspace-admin', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 4,
        ]);
        $sharedModel = $this->model($provider, 'enterprise-workspace-shared');

        $project = app(EnterpriseKnowledgeDraftService::class)->createWorkspaceDraft([
            'name' => '工作台企业知识草稿',
            'content' => '# 企业介绍'."\n".'工作台生成内容。',
        ], $admin);

        $this->assertSame((int) $admin->id, (int) $project->model_access_admin_id);
        $this->assertSame('admin', $project->model_access_admin_role);
        $this->assertSame(4, (int) $project->ai_config_access_version);
        $this->assertSame((int) $sharedModel->id, (int) $project->requested_ai_model_id);
        $this->assertSame(AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION, (int) $project->resolver_policy_version);
        $this->assertStringNotContainsString('enterprise-identity-secret', (string) $project->requested_ai_model_snapshot);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => $project->id,
            'source' => 'ai_workspace',
            'created_by_admin_id' => $admin->id,
        ]);
    }

    public function test_personal_model_is_tried_before_shared_and_transient_failure_uses_shared_only(): void
    {
        $provider = $this->admin('enterprise-failover-provider', ['role' => 'super_admin']);
        $admin = $this->admin('enterprise-failover-admin', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 3,
        ]);
        $personal = $this->model($admin, 'enterprise-personal', [
            'api_url' => 'https://personal-ai.test/v1',
            'failover_priority' => 50,
        ]);
        $shared = $this->model($provider, 'enterprise-shared', [
            'api_url' => 'https://shared-ai.test/v1',
            'failover_priority' => 1,
        ]);
        $peer = $this->admin('enterprise-peer');
        $this->model($peer, 'enterprise-peer-model', ['api_url' => 'https://peer-ai.test/v1']);
        $this->model($admin, 'enterprise-system-model', [
            'api_url' => 'https://system-ai.test/v1',
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $project = $this->executionProject($admin, $personal, '企业模型故障切换');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'personal-ai.test')) {
                return Http::response(['error' => ['message' => 'temporary']], 500);
            }

            return Http::response($this->chatCompletion($this->completeDraftContent('共享模型草稿')), 200);
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('reviewing', $project->status, (string) $project->error_message);
        $this->assertSame((int) $shared->id, (int) $project->resolved_ai_model_id);
        $this->assertSame('shared', $project->resolved_model_source);
        $this->assertStringContainsString('共享模型草稿', (string) $project->draft_content);
        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
        $this->assertStringContainsString('personal-ai.test', implode(' ', $urls));
        $this->assertStringContainsString('shared-ai.test', implode(' ', $urls));
        $this->assertStringNotContainsString('peer-ai.test', implode(' ', $urls));
        $this->assertStringNotContainsString('system-ai.test', implode(' ', $urls));
    }

    public function test_permanent_provider_rejection_never_uses_shared_fallback(): void
    {
        $provider = $this->admin('enterprise-permanent-provider', ['role' => 'super_admin']);
        $admin = $this->admin('enterprise-permanent-admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'enterprise-permanent-personal', [
            'api_url' => 'https://personal-reject.test/v1',
        ]);
        $this->model($provider, 'enterprise-permanent-shared', [
            'api_url' => 'https://shared-unused.test/v1',
        ]);
        $project = $this->executionProject($admin, $personal, '企业永久失败');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'personal-reject.test')) {
                return Http::response(['error' => ['message' => 'invalid parameter']], 400);
            }

            return Http::response($this->chatCompletion($this->completeDraftContent('不应使用')), 200);
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame('ai_provider_request_rejected', $project->error_code);
        $this->assertFalse($project->retryable_failure);
        $this->assertSame('', (string) $project->draft_content);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'shared-unused.test'));
    }

    public function test_modular_permanent_provider_rejection_does_not_retry_as_single_pass_or_use_shared(): void
    {
        $provider = $this->admin('enterprise-modular-permanent-provider', ['role' => 'super_admin']);
        $admin = $this->admin('enterprise-modular-permanent-admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'enterprise-modular-permanent-personal', [
            'api_url' => 'https://modular-personal-reject.test/v1',
        ]);
        $this->model($provider, 'enterprise-modular-permanent-shared', [
            'api_url' => 'https://modular-shared-unused.test/v1',
        ]);
        $project = $this->executionProject($admin, $personal, '企业模块化永久失败');
        $this->addSources($project, 2);
        Http::fake(fn () => Http::response(['error' => ['message' => 'unsupported capability']], 422));

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame('ai_provider_request_rejected', $project->error_code);
        $this->assertFalse($project->retryable_failure);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'modular-shared-unused.test'));
    }

    public function test_modular_transient_failure_can_fall_back_to_single_pass_on_the_same_model(): void
    {
        $admin = $this->admin('enterprise-modular-transient-admin');
        $model = $this->model($admin, 'enterprise-modular-transient-model', [
            'api_url' => 'https://modular-transient.test/v1',
        ]);
        $project = $this->executionProject($admin, $model, '企业模块化临时失败');
        $this->addSources($project, 2);
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return Http::response(['error' => ['message' => 'temporary overload']], 500);
            }

            return Http::response($this->chatCompletion($this->completeDraftContent('单次降级草稿')), 200);
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('reviewing', $project->status, (string) $project->error_message);
        $this->assertSame((int) $model->id, (int) $project->resolved_ai_model_id);
        $this->assertStringContainsString('单次降级草稿', (string) $project->draft_content);
        Http::assertSentCount(2);
    }

    public function test_missing_requested_model_credentials_never_uses_shared_fallback(): void
    {
        $provider = $this->admin('enterprise-missing-key-provider', ['role' => 'super_admin']);
        $admin = $this->admin('enterprise-missing-key-admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'enterprise-missing-key-personal');
        $this->model($provider, 'enterprise-missing-key-shared', [
            'api_url' => 'https://shared-key-unused.test/v1',
        ]);
        $project = $this->executionProject($admin, $personal, '企业缺少密钥');
        $personal->forceFill(['api_key' => ''])->save();
        Http::fake();

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame(AiModelAccessException::AI_MODEL_UNAVAILABLE, $project->error_code);
        $this->assertFalse($project->retryable_failure);
        Http::assertNothingSent();
    }

    public function test_access_revoked_after_provider_response_discards_draft_and_revision(): void
    {
        $admin = $this->admin('enterprise-revoked-admin', ['ai_config_access_version' => 6]);
        $model = $this->model($admin, 'enterprise-revoked-model');
        $project = $this->executionProject($admin, $model, '企业撤权结果丢弃');
        Http::fake(function () use ($admin) {
            $admin->forceFill(['ai_config_access_version' => 7])->save();

            return Http::response($this->chatCompletion($this->completeDraftContent('撤权后草稿')), 200);
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $project->error_code);
        $this->assertFalse($project->retryable_failure);
        $this->assertSame('', (string) $project->draft_content);
        $this->assertSame(0, EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $project->id)
            ->count());
    }

    public function test_inactive_execution_admin_and_shared_owner_block_before_external_calls(): void
    {
        $provider = $this->admin('enterprise-inactive-provider', ['role' => 'super_admin']);
        $consumer = $this->admin('enterprise-inactive-consumer', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $shared = $this->model($provider, 'enterprise-inactive-shared');
        $ownerProject = $this->executionProject($consumer, $shared, '共享方停用');
        $provider->forceFill(['status' => 'inactive'])->save();
        Http::fake();

        (new GenerateEnterpriseKnowledgeDraftJob((int) $ownerProject->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $ownerProject->refresh();
        $this->assertSame(AiModelAccessException::AI_CONFIG_OWNER_INACTIVE, $ownerProject->error_code);
        Http::assertNothingSent();

        $admin = $this->admin('enterprise-inactive-executor');
        $personal = $this->model($admin, 'enterprise-inactive-personal');
        $adminProject = $this->executionProject($admin, $personal, '执行人停用');
        $admin->forceFill(['status' => 'inactive'])->save();
        (new GenerateEnterpriseKnowledgeDraftJob((int) $adminProject->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $this->assertSame(
            AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
            $adminProject->refresh()->error_code,
        );
        Http::assertNothingSent();
    }

    public function test_expired_lease_can_be_reclaimed_and_old_worker_is_fenced(): void
    {
        $admin = $this->admin('enterprise-lease-admin');
        $model = $this->model($admin, 'enterprise-lease-model');
        $project = $this->executionProject($admin, $model, '企业租约恢复');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        $oldClaim = $guard->claim($project);
        $oldWorker = $oldClaim['project'];
        $oldLease = (string) $oldWorker->execution_lease_token;
        $project->forceFill([
            'status' => 'processing',
            'execution_lease_token' => $oldLease,
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $newClaim = $guard->claim($project->refresh());

        $this->assertTrue($newClaim['claimed']);
        $this->assertNotSame($oldLease, (string) $newClaim['project']->execution_lease_token);
        $guard->assertCurrent($newClaim['project'], $model);
        $this->expectException(AiModelAccessException::class);
        $guard->assertCurrent($oldWorker, $model);
    }

    public function test_reclaimed_lease_discards_the_old_provider_result_without_overwriting_the_new_worker(): void
    {
        $admin = $this->admin('enterprise-result-fence-admin');
        $model = $this->model($admin, 'enterprise-result-fence-model');
        $project = $this->executionProject($admin, $model, '企业旧 Worker 结果隔离');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        $newLease = null;
        Http::fake(function () use ($guard, $project, &$newLease) {
            EnterpriseKnowledgeProject::query()->whereKey($project->id)->update([
                'lease_expires_at' => now()->subMinute(),
            ]);
            $reclaimed = $guard->claim($project->fresh());
            $newLease = (string) $reclaimed['project']->execution_lease_token;

            return Http::response($this->chatCompletion($this->completeDraftContent('旧 Worker 草稿')), 200);
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertNotSame('', $newLease);
        $this->assertSame($newLease, (string) $project->execution_lease_token);
        $this->assertSame('', (string) $project->draft_content);
        $this->assertSame(0, EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $project->id)
            ->count());
    }

    public function test_reconstructed_failed_callback_cannot_overwrite_a_new_worker_lease(): void
    {
        $admin = $this->admin('enterprise-failed-callback-admin');
        $model = $this->model($admin, 'enterprise-failed-callback-model');
        $project = $this->executionProject($admin, $model, '企业失败回调隔离');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        $oldJob = new GenerateEnterpriseKnowledgeDraftJob((int) $project->id);
        $oldClaim = $guard->claim($project, $oldJob->claimLeaseToken);
        $project->forceFill([
            'execution_lease_token' => $oldClaim['project']->execution_lease_token,
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $newJob = new GenerateEnterpriseKnowledgeDraftJob((int) $project->id);
        $newClaim = $guard->claim($project->refresh(), $newJob->claimLeaseToken);
        $newLease = (string) $newClaim['project']->execution_lease_token;
        $reconstructedOldJob = unserialize(serialize($oldJob));

        $reconstructedOldJob->failed(new RuntimeException('old worker timeout'));

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertSame($newLease, (string) $project->execution_lease_token);
        $this->assertNull($project->error_code);
        $this->assertNull($project->error_message);
    }

    public function test_legacy_failed_callback_without_an_original_lease_leaves_recovery_state_untouched(): void
    {
        $admin = $this->admin('enterprise-legacy-failed-callback-admin');
        $model = $this->model($admin, 'enterprise-legacy-failed-callback-model');
        $project = $this->executionProject($admin, $model, '企业旧任务失败回调');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        $claim = $guard->claim($project);
        $lease = (string) $claim['project']->execution_lease_token;
        $legacyJob = new GenerateEnterpriseKnowledgeDraftJob((int) $project->id);
        $legacyJob->claimLeaseToken = null;

        $legacyJob->failed(new RuntimeException('legacy timeout'));

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertSame($lease, (string) $project->execution_lease_token);
        $this->assertNull($project->error_code);
        $this->assertNull($project->error_message);
    }

    public function test_scheduled_recovery_is_idempotent_and_skips_terminal_projects(): void
    {
        Queue::fake();
        $admin = $this->admin('enterprise-recovery-admin');
        $model = $this->model($admin, 'enterprise-recovery-model');
        $stale = $this->executionProject($admin, $model, '企业恢复项目');
        $stale->forceFill([
            'status' => 'processing',
            'execution_lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $terminal = $this->executionProject($admin, $model, '企业终态项目');
        $terminal->forceFill([
            'status' => 'reviewing',
            'draft_content' => '# 已完成',
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ])->save();

        $this->artisan('geoflow:recover-enterprise-knowledge-drafts', ['--limit' => 10])
            ->expectsOutput('Recovered enterprise knowledge drafts: 1; dispatch failures: 0')
            ->assertSuccessful();
        $this->artisan('geoflow:recover-enterprise-knowledge-drafts', ['--limit' => 10])
            ->expectsOutput('Recovered enterprise knowledge drafts: 0; dispatch failures: 0')
            ->assertSuccessful();

        Queue::assertPushed(GenerateEnterpriseKnowledgeDraftJob::class, 1);
        $this->assertSame('queued', $stale->refresh()->status);
        $this->assertSame('reviewing', $terminal->refresh()->status);

        Http::fake(fn () => Http::response(
            $this->chatCompletion($this->completeDraftContent('恢复后的企业草稿')),
            200,
        ));
        Queue::pushed(GenerateEnterpriseKnowledgeDraftJob::class)->first()
            ->handle(app(EnterpriseKnowledgeDraftService::class));
        $this->assertSame('reviewing', $stale->refresh()->status, (string) $stale->error_message);
        $this->assertStringContainsString('恢复后的企业草稿', (string) $stale->draft_content);
    }

    public function test_recovery_dispatch_failure_keeps_a_sanitized_retryable_state(): void
    {
        $admin = $this->admin('enterprise-recovery-failure-admin');
        $model = $this->model($admin, 'enterprise-recovery-failure-model');
        $project = $this->executionProject($admin, $model, '企业恢复派发失败');
        $project->forceFill([
            'status' => 'processing',
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ])->save();
        $this->app->instance(
            EnterpriseKnowledgeDraftRecoveryDispatcher::class,
            new class extends EnterpriseKnowledgeDraftRecoveryDispatcher
            {
                public function dispatch(int $projectId): void
                {
                    throw new RuntimeException('queue secret should never persist');
                }
            },
        );

        $this->artisan('geoflow:recover-enterprise-knowledge-drafts', ['--limit' => 10])
            ->expectsOutput('Recovered enterprise knowledge drafts: 0; dispatch failures: 1')
            ->assertFailed();

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertNull($project->execution_lease_token);
        $this->assertNull($project->lease_expires_at);
        $this->assertSame('enterprise_knowledge_recovery_dispatch_failed', $project->error_code);
        $this->assertTrue($project->retryable_failure);
        $this->assertStringNotContainsString('secret', (string) $project->error_message);
    }

    public function test_enterprise_projects_participate_in_admin_and_model_deletion_lifecycle(): void
    {
        $admin = $this->admin('enterprise-dependency-admin');
        $model = $this->model($admin, 'enterprise-dependency-model');
        $project = $this->executionProject($admin, $model, '企业删除依赖');
        $project->forceFill([
            'resolved_ai_model_id' => $model->id,
            'resolved_ai_model_snapshot' => (string) $project->requested_ai_model_snapshot,
            'resolved_model_source' => 'personal',
            'model_resolved_at' => now(),
        ])->save();

        $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($admin);
        $this->assertTrue($dependencies->blocksDeletion());
        $this->assertSame(1, $dependencies->pendingTaskCounts['enterprise_knowledge_projects']);
        $this->assertSame(1, $dependencies->executionEnterpriseKnowledgeProjectCount);
        $blocked = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);
        $this->assertFalse($blocked->succeeded());
        $this->assertSame('task', $blocked->error);

        $project->forceFill([
            'status' => 'reviewing',
            'retryable_failure' => false,
        ])->save();
        $deleted = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);

        $this->assertTrue($deleted->succeeded());
        $project->refresh();
        $this->assertNull($project->requested_ai_model_id);
        $this->assertNull($project->resolved_ai_model_id);
        $this->assertStringNotContainsString('enterprise-identity-secret', (string) $project->requested_ai_model_snapshot);
        $this->assertTrue(app(AdminAiDependencyInspector::class)->deletionDependencies($admin)->blocksDeletion());
    }

    public function test_identity_migration_rolls_back_and_reapplies_all_execution_columns(): void
    {
        $migration = require database_path('migrations/2026_09_02_105656_add_ai_execution_identity_to_enterprise_knowledge_projects_table.php');

        $migration->down();
        foreach ([
            'model_access_admin_id',
            'requested_ai_model_snapshot',
            'resolved_ai_model_snapshot',
            'execution_lease_token',
            'lease_expires_at',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('enterprise_knowledge_projects', $column));
        }

        $migration->up();
        foreach ([
            'model_access_admin_id',
            'model_access_admin_role',
            'ai_config_access_version',
            'requested_ai_model_id',
            'requested_ai_model_snapshot',
            'resolver_policy_version',
            'resolved_ai_model_id',
            'resolved_ai_model_snapshot',
            'resolved_model_source',
            'execution_lease_token',
            'lease_expires_at',
            'error_code',
            'retryable_failure',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('enterprise_knowledge_projects', $column));
        }
    }

    private function admin(string $username, array $attributes = []): Admin
    {
        $admin = Admin::query()->create(array_merge([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ], $attributes));
        $admin->forceFill($attributes)->save();

        return $admin;
    }

    private function model(Admin $owner, string $modelId, array $attributes = []): AiModel
    {
        $model = AiModel::query()->create(array_merge([
            'name' => $modelId,
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('enterprise-identity-secret'),
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

    private function executionProject(
        Admin $admin,
        AiModel $requestedModel,
        string $name,
    ): EnterpriseKnowledgeProject {
        return DB::transaction(function () use ($admin, $requestedModel, $name): EnterpriseKnowledgeProject {
            $identity = app(EnterpriseKnowledgeAiExecutionGuard::class)->snapshotForCreation($admin, $requestedModel);
            $project = EnterpriseKnowledgeProject::query()->create(array_merge([
                'name' => $name,
                'status' => 'queued',
                'created_by_admin_id' => $admin->id,
            ], $identity));
            EnterpriseKnowledgeSource::query()->create([
                'enterprise_knowledge_project_id' => $project->id,
                'original_name' => 'source.md',
                'file_type' => 'markdown',
                'content' => '# 企业背景'."\n".'企业提供知识管理、内容生成和审核服务。',
                'character_count' => 28,
                'sort_order' => 0,
            ]);

            return $project;
        });
    }

    private function addSources(EnterpriseKnowledgeProject $project, int $count): void
    {
        foreach (range(1, $count) as $index) {
            EnterpriseKnowledgeSource::query()->create([
                'enterprise_knowledge_project_id' => $project->id,
                'original_name' => 'source-'.$index.'.md',
                'file_type' => 'markdown',
                'content' => '# 补充资料 '.$index."\n".'企业产品能力、应用场景和审核流程。',
                'character_count' => 24,
                'sort_order' => $index,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-enterprise-identity',
            'object' => 'chat.completion',
            'model' => 'enterprise-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];
    }

    private function completeDraftContent(string $title): string
    {
        return <<<MARKDOWN
# {$title}

## 企业介绍
企业提供知识管理和内容工程服务。

## 业务信息摘要
- 面向企业内容运营团队。

## 产品能力
- 知识整理、内容生成和审核。

## 应用场景
- 企业知识库建设。

## 典型案例
- 案例需人工核验。

## FAQ
### 可以解决什么问题？
支持知识整理和内容审核。

## 禁用表述
- 不使用绝对化承诺。

## 风险与冲突
- 数据和案例需人工复核。

## 待人工确认
- 公开范围需人工确认。
MARKDOWN;
    }
}
