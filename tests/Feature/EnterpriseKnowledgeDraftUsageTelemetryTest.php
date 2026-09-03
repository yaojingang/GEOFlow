<?php

namespace Tests\Feature;

use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeSource;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\EnterpriseKnowledgeAiExecutionGuard;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftUsageDelivery;
use App\Services\GeoFlow\EnterpriseKnowledgeExecutionFence;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class EnterpriseKnowledgeDraftUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_draft_provider_usage_succeeds_only_after_the_revision_is_persisted(): void
    {
        $admin = $this->admin('enterprise-ledger-personal');
        $model = $this->model($admin, 'enterprise-ledger-model');
        $project = $this->project($admin, $model, '企业知识账本单稿');
        Http::fake([
            'https://enterprise.test/*' => Http::response(
                $this->chatCompletion($this->completeDraft('企业知识账本草稿')),
            ),
        ]);
        $job = new GenerateEnterpriseKnowledgeDraftJob((int) $project->id);

        $job->handle(app(EnterpriseKnowledgeDraftService::class));

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame((string) $job->claimLeaseToken, $event->request_id);
        $this->assertSame('a1.c1.single.p1', $event->call_key);
        $this->assertSame((int) $model->id, (int) $event->ai_model_id);
        $this->assertSame((int) $admin->id, (int) $event->execution_admin_id);
        $this->assertSame((int) $admin->id, (int) $event->config_owner_admin_id);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $event->model_source);
        $this->assertSame('enterprise_knowledge.generate', $event->operation);
        $this->assertSame('enterprise_knowledge_draft', $event->business_source);
        $this->assertSame(EnterpriseKnowledgeProject::class, $event->source_type);
        $this->assertSame((string) $project->id, (string) $event->source_id);
        $this->assertSame(1, (int) $project->fresh()->execution_attempt);
        $this->assertSame('reviewing', $project->fresh()->status);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => $project->id,
            'source' => 'ai',
        ]);
    }

    public function test_modular_draft_records_each_adopted_provider_call_after_one_durable_commit(): void
    {
        $admin = $this->admin('enterprise-ledger-modular');
        $model = $this->model($admin, 'enterprise-ledger-modular-model');
        $project = $this->project($admin, $model, '企业知识账本模块稿');
        $this->addSources($project, 2);
        $responses = collect([
            $this->moduleDraft('profile'),
            $this->moduleDraft('capabilities'),
            $this->moduleDraft('scenarios_cases'),
            $this->moduleDraft('faq_risk'),
        ]);
        Http::fake(fn () => Http::response($this->chatCompletion((string) $responses->shift())));

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(4, $events);
        $this->assertSame([
            'a1.c1.profile.p1',
            'a1.c1.capabilities.p1',
            'a1.c1.scenarios_cases.p1',
            'a1.c1.faq_risk.p1',
        ], $events->pluck('call_key')->all());
        $this->assertSame(
            array_fill(0, 4, AiModelUsageEvent::STATUS_SUCCEEDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame([36, 36, 36, 36], $events->pluck('total_tokens')->all());
        $this->assertSame('reviewing', $project->fresh()->status);
    }

    public function test_invalid_second_module_discards_modular_calls_and_records_single_fallback_success(): void
    {
        $admin = $this->admin('enterprise-ledger-module-fallback');
        $model = $this->model($admin, 'enterprise-ledger-module-fallback-model');
        $project = $this->project($admin, $model, '企业知识账本模块降级');
        $this->addSources($project, 2);
        $responses = collect([
            $this->moduleDraft('profile'),
            '## 无关章节'."\n".'模型没有返回产品能力。',
            $this->completeDraft('模块降级单稿'),
        ]);
        Http::fake(fn () => Http::response($this->chatCompletion((string) $responses->shift())));

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame([
            'a1.c1.profile.p1',
            'a1.c1.capabilities.p1',
            'a1.c1.single.p1',
        ], $events->pluck('call_key')->all());
        $this->assertSame([
            AiModelUsageEvent::STATUS_DISCARDED,
            AiModelUsageEvent::STATUS_DISCARDED,
            AiModelUsageEvent::STATUS_SUCCEEDED,
        ], $events->pluck('status')->all());
        $this->assertSame('modular_superseded', $events[0]->error_code);
        $this->assertSame('modular_superseded', $events[1]->error_code);
        $this->assertStringContainsString('模块降级单稿', (string) $project->fresh()->draft_content);
    }

    public function test_transient_personal_failure_and_shared_success_have_separate_attribution(): void
    {
        $provider = $this->admin('enterprise-ledger-provider', 'super_admin');
        $admin = $this->admin('enterprise-ledger-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'enterprise-ledger-personal-fail', [
            'api_url' => 'https://personal-enterprise.test/v1',
            'failover_priority' => 50,
        ]);
        $shared = $this->model($provider, 'enterprise-ledger-shared', [
            'api_url' => 'https://shared-enterprise.test/v1',
            'failover_priority' => 1,
        ]);
        $project = $this->project($admin, $personal, '企业知识账本共享切换');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'personal-enterprise.test')) {
                return Http::response(['error' => ['message' => 'temporary']], 500);
            }

            return Http::response($this->chatCompletion($this->completeDraft('共享成功草稿')));
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $events[0]->status);
        $this->assertSame((int) $personal->id, (int) $events[0]->ai_model_id);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $events[0]->model_source);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[1]->status);
        $this->assertSame((int) $shared->id, (int) $events[1]->ai_model_id);
        $this->assertSame((int) $provider->id, (int) $events[1]->config_owner_admin_id);
        $this->assertSame((int) $admin->id, (int) $events[1]->execution_admin_id);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $events[1]->model_source);
        $this->assertSame('a1.c2.single.p1', $events[1]->call_key);
    }

    public function test_provider_result_is_revoked_when_admin_access_changes_before_persist(): void
    {
        $admin = $this->admin('enterprise-ledger-revoked');
        $model = $this->model($admin, 'enterprise-ledger-revoked-model');
        $project = $this->project($admin, $model, '企业知识账本撤权');
        Http::fake(function () use ($admin) {
            $admin->forceFill(['ai_config_access_version' => 2])->save();

            return Http::response($this->chatCompletion($this->completeDraft('撤权结果')));
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame('ai_config_access_revoked', $event->error_code);
        $this->assertSame('failed', $project->fresh()->status);
        $this->assertSame(0, $project->revisions()->count());
    }

    public function test_reclaimed_execution_discards_the_old_provider_result(): void
    {
        $admin = $this->admin('enterprise-ledger-reclaimed');
        $model = $this->model($admin, 'enterprise-ledger-reclaimed-model');
        $project = $this->project($admin, $model, '企业知识账本租约回收');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        Http::fake(function () use ($guard, $project) {
            EnterpriseKnowledgeProject::query()->whereKey($project->id)->update([
                'lease_expires_at' => now()->subMinute(),
            ]);
            $guard->claim($project->fresh());

            return Http::response($this->chatCompletion($this->completeDraft('旧执行结果')));
        });

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame('processing', $project->fresh()->status);
        $this->assertSame(0, $project->revisions()->count());
        $this->assertSame(2, (int) $project->fresh()->execution_attempt);
    }

    public function test_same_lease_token_reclaim_fences_the_old_attempt_and_its_failed_callback(): void
    {
        $admin = $this->admin('enterprise-ledger-same-token');
        $model = $this->model($admin, 'enterprise-ledger-same-token-model');
        $project = $this->project($admin, $model, '企业知识账本同令牌围栏');
        $guard = app(EnterpriseKnowledgeAiExecutionGuard::class);
        $job = new GenerateEnterpriseKnowledgeDraftJob((int) $project->id);
        $sameLeaseToken = (string) $job->claimLeaseToken;
        $attemptTwoFence = null;
        $providerCalls = 0;
        Http::fake(function () use ($guard, $project, $sameLeaseToken, &$attemptTwoFence, &$providerCalls) {
            $providerCalls++;
            if ($providerCalls === 1) {
                EnterpriseKnowledgeProject::query()->whereKey($project->id)->update([
                    'lease_expires_at' => now()->subMinute(),
                ]);
                $reclaimed = $guard->claim($project->fresh(), $sameLeaseToken);
                $this->assertTrue($reclaimed['claimed']);
                $this->assertSame(2, (int) $reclaimed['project']->execution_attempt);
                $attemptTwoFence = $reclaimed['fence'];

                return Http::response($this->chatCompletion($this->completeDraft('旧执行结果')));
            }

            return Http::response($this->chatCompletion($this->completeDraft('新执行结果')));
        });

        $job->handle(app(EnterpriseKnowledgeDraftService::class));

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('a1.c1.single.p1', $event->call_key);
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertSame($sameLeaseToken, (string) $project->execution_lease_token);
        $this->assertSame(2, (int) $project->execution_attempt);
        $this->assertSame(0, $project->revisions()->count());

        $job->failed(new RuntimeException('old attempt failed callback'));

        $project->refresh();
        $this->assertSame('processing', $project->status);
        $this->assertSame(2, (int) $project->execution_attempt);
        $this->assertNull($project->error_code);
        $this->assertInstanceOf(EnterpriseKnowledgeExecutionFence::class, $attemptTwoFence);
        $mutationLock = app(AiModelInvocationLock::class)->acquireForMutation((int) $model->id);
        $this->assertNotNull($mutationLock);
        app(AiModelInvocationLock::class)->release($mutationLock);

        $attemptTwoProject = $project->fresh(['sources']);
        $draftService = app(EnterpriseKnowledgeDraftService::class);
        $draft = $draftService->generateDraft($attemptTwoProject, $attemptTwoFence);
        $this->assertSame('ai', $draft['source'], (string) $draft['error']);
        $delivery = $draft['usage_delivery'] ?? null;
        $this->assertInstanceOf(EnterpriseKnowledgeDraftUsageDelivery::class, $delivery);
        $content = trim((string) $draft['content']);
        $persistDraft = new \ReflectionMethod(GenerateEnterpriseKnowledgeDraftJob::class, 'persistDraft');
        $persistDraft->invoke(
            new GenerateEnterpriseKnowledgeDraftJob((int) $project->id, $sameLeaseToken),
            $attemptTwoProject,
            $attemptTwoFence,
            $content,
            $draftService->validateDraft($content),
            $draft,
            $guard,
        );
        $delivery->succeeded();

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame(['a1.c1.single.p1', 'a2.c1.single.p1'], $events->pluck('call_key')->all());
        $this->assertSame([
            AiModelUsageEvent::STATUS_DISCARDED,
            AiModelUsageEvent::STATUS_SUCCEEDED,
        ], $events->pluck('status')->all());
        $this->assertSame([$sameLeaseToken], $events->pluck('request_id')->unique()->values()->all());
        $this->assertSame('reviewing', $project->fresh()->status);
        $this->assertStringContainsString('新执行结果', (string) $project->fresh()->draft_content);
    }

    public function test_missing_execution_identity_fails_before_provider_and_records_no_usage(): void
    {
        $admin = $this->admin('enterprise-ledger-missing-identity');
        $this->model($admin, 'enterprise-ledger-unused-model');
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '企业知识账本缺身份',
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
        $this->assertSame(0, AiModelUsageEvent::query()->count());
        $this->assertSame('ai_config_access_revoked', $project->fresh()->error_code);
    }

    private function admin(string $username, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'display_name' => $username,
            'password' => 'secret',
            'role' => $role,
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);
    }

    private function model(Admin $owner, string $modelId, array $attributes = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('enterprise-ledger-secret'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://enterprise.test/v1',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $attributes));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function project(Admin $admin, AiModel $model, string $name): EnterpriseKnowledgeProject
    {
        return DB::transaction(function () use ($admin, $model, $name): EnterpriseKnowledgeProject {
            $identity = app(EnterpriseKnowledgeAiExecutionGuard::class)->snapshotForCreation($admin, $model);
            $project = EnterpriseKnowledgeProject::query()->create(array_merge([
                'name' => $name,
                'status' => 'queued',
                'created_by_admin_id' => $admin->id,
            ], $identity));
            EnterpriseKnowledgeSource::query()->create([
                'enterprise_knowledge_project_id' => $project->id,
                'original_name' => 'source.md',
                'file_type' => 'markdown',
                'content' => '# 企业背景'."\n".'企业提供 GEO 内容工程和知识管理服务。',
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
            'id' => 'chatcmpl-enterprise-ledger',
            'object' => 'chat.completion',
            'model' => 'enterprise-ledger-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 24,
                'total_tokens' => 36,
            ],
        ];
    }

    private function completeDraft(string $title): string
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
- 避免绝对化承诺。

## 风险与冲突
- 数据和案例需人工复核。

## 待人工确认
- 公开范围需人工确认。
MARKDOWN;
    }

    private function moduleDraft(string $module): string
    {
        return match ($module) {
            'profile' => <<<'MARKDOWN'
## 企业介绍
企业提供知识管理和内容工程服务。

## 业务信息摘要
- 面向企业内容运营团队。
MARKDOWN,
            'capabilities' => <<<'MARKDOWN'
## 产品能力
- 知识整理、内容生成和审核。
MARKDOWN,
            'scenarios_cases' => <<<'MARKDOWN'
## 应用场景
- 企业知识库建设。

## 典型案例
- 案例需人工核验。
MARKDOWN,
            'faq_risk' => <<<'MARKDOWN'
## FAQ
### 可以解决什么问题？
支持知识整理和内容审核。

## 禁用表述
- 避免绝对化承诺。

## 风险与冲突
- 数据和案例需人工复核。

## 待人工确认
- 公开范围需人工确认。
MARKDOWN,
        };
    }
}
