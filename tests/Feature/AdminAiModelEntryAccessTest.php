<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiModelEntryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_catalog_is_scoped_and_sanitized_with_personal_models_before_shared_models(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $peer = $this->admin('peer', 'admin');
        $personalChat = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $personalEmbedding = $this->model($actor, 'Personal Embedding', 'embedding', priority: 80);
        $sharedChat = $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $peerChat = $this->model($peer, 'Peer Chat', 'chat');
        $systemModel = $this->model($provider, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        $response = $this->actingWithToken($actor, ['catalog:read'])->getJson('/api/v1/catalog')->assertOk();

        $modelIds = collect($response->json('data.models'))->pluck('id')->all();
        $this->assertSame([$personalEmbedding->id, $personalChat->id, $sharedChat->id], $modelIds);
        $this->assertNotContains($peerChat->id, $modelIds);
        $this->assertNotContains($systemModel->id, $modelIds);
        foreach ($response->json('data.models') as $modelData) {
            $this->assertSame(
                ['id', 'name', 'version', 'model_type', 'status', 'failover_priority', 'is_available', 'is_shared'],
                array_keys($modelData),
            );
        }
        $this->assertStringNotContainsString('catalog-secret', $response->getContent());
        $this->assertStringNotContainsString('provider.example', $response->getContent());

        $superResponse = $this->actingWithToken($provider, ['catalog:read'])->getJson('/api/v1/catalog')->assertOk();
        $this->assertSame(
            [$sharedChat->id],
            collect($superResponse->json('data.models'))->pluck('id')->all(),
        );
    }

    public function test_closing_sharing_removes_shared_models_from_the_catalog_immediately(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $shared = $this->model($provider, 'Shared Chat', 'chat');
        $token = $actor->createToken('catalog', ['catalog:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('data.models.0.id', $shared->id);

        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonCount(0, 'data.models');
    }

    public function test_task_api_only_projects_model_references_the_token_admin_can_call(): void
    {
        $provider = $this->admin('task-response-provider', 'super_admin');
        $actor = $this->admin('task-response-actor', 'admin', $provider);
        $peer = $this->admin('task-response-peer', 'admin');
        $personal = $this->model($actor, 'Task Response Personal', 'chat');
        $shared = $this->model($provider, 'Task Response Shared', 'chat');
        $peerModel = $this->model($peer, 'Task Response Peer Secret', 'chat');
        $systemModel = $this->model(
            $provider,
            'Task Response System Secret',
            'chat',
            AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        );
        $archivedModel = $this->model($actor, 'Task Response Archived Secret', 'chat');
        $archivedModel->forceFill(['archived_at' => now()])->save();

        $personalTask = Task::query()->create([
            'name' => 'Visible personal task',
            'status' => 'paused',
            'ai_model_id' => $personal->id,
            'ai_quality_model_id' => $shared->id,
        ]);
        foreach ([$peerModel, $systemModel, $archivedModel] as $index => $hiddenModel) {
            Task::query()->create([
                'name' => 'Hidden reference task '.($index + 1),
                'status' => 'paused',
                'ai_model_id' => $hiddenModel->id,
                'ai_quality_model_id' => $hiddenModel->id,
            ]);
        }

        $token = $actor->createToken('task-response', ['tasks:read'])->plainTextToken;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks')
            ->assertOk();
        $modelQueries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'ai_models'));
        DB::disableQueryLog();
        $this->assertCount(1, $modelQueries, 'Task model references must be projected in one resolver-scoped batch query.');
        $items = collect($response->json('data.items'))->keyBy('id');
        $visible = $items->get((int) $personalTask->id);
        $this->assertSame((int) $personal->id, data_get($visible, 'ai_model_id'));
        $this->assertSame('Task Response Personal', data_get($visible, 'ai_model_name'));
        $this->assertTrue(data_get($visible, 'ai_model_accessible'));
        $this->assertNull(data_get($visible, 'ai_model_access_reason'));
        $this->assertSame((int) $shared->id, data_get($visible, 'ai_quality_model_id'));
        $this->assertSame('Task Response Shared', data_get($visible, 'ai_quality_model_name'));
        $this->assertTrue(data_get($visible, 'ai_quality_model_accessible'));
        $this->assertNull(data_get($visible, 'ai_quality_model_access_reason'));

        foreach ($items->except((int) $personalTask->id) as $hidden) {
            foreach (['ai_model', 'ai_quality_model'] as $prefix) {
                $this->assertNull(data_get($hidden, $prefix.'_id'));
                $this->assertNull(data_get($hidden, $prefix.'_name'));
                $this->assertFalse(data_get($hidden, $prefix.'_accessible'));
                $this->assertSame('ai_model_not_accessible', data_get($hidden, $prefix.'_access_reason'));
            }
            $serialized = json_encode($hidden, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Task Response Peer Secret', $serialized);
            $this->assertStringNotContainsString('Task Response System Secret', $serialized);
            $this->assertStringNotContainsString('Task Response Archived Secret', $serialized);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks/'.(int) $personalTask->id)
            ->assertOk()
            ->assertJsonPath('data.ai_model_id', (int) $personal->id)
            ->assertJsonPath('data.ai_model_accessible', true)
            ->assertJsonPath('data.ai_quality_model_id', (int) $shared->id)
            ->assertJsonPath('data.ai_quality_model_accessible', true);

        $provider->forceFill(['status' => 'inactive'])->save();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks/'.(int) $personalTask->id)
            ->assertOk()
            ->assertJsonPath('data.ai_model_id', (int) $personal->id)
            ->assertJsonPath('data.ai_quality_model_id', null)
            ->assertJsonPath('data.ai_quality_model_name', null)
            ->assertJsonPath('data.ai_quality_model_accessible', false)
            ->assertJsonPath('data.ai_quality_model_access_reason', 'ai_model_not_accessible');

        $provider->forceFill(['status' => 'active'])->save();
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks/'.(int) $personalTask->id)
            ->assertOk()
            ->assertJsonPath('data.ai_quality_model_id', null)
            ->assertJsonPath('data.ai_quality_model_accessible', false)
            ->assertJsonPath('data.ai_quality_model_access_reason', 'ai_model_not_accessible');
    }

    public function test_super_admin_task_api_hides_an_ordinary_admin_private_model_reference(): void
    {
        $superAdmin = $this->admin('task-response-super', 'super_admin');
        $ordinaryAdmin = $this->admin('task-response-ordinary', 'admin');
        $privateModel = $this->model($ordinaryAdmin, 'Ordinary Private Task Model', 'chat');
        $task = Task::query()->create([
            'name' => 'Task with ordinary private model',
            'status' => 'paused',
            'ai_model_id' => $privateModel->id,
            'ai_quality_model_id' => $privateModel->id,
        ]);

        $response = $this->actingWithToken($superAdmin, ['tasks:read'])
            ->getJson('/api/v1/tasks/'.(int) $task->id)
            ->assertOk()
            ->assertJsonPath('data.ai_model_id', null)
            ->assertJsonPath('data.ai_model_name', null)
            ->assertJsonPath('data.ai_model_accessible', false)
            ->assertJsonPath('data.ai_model_access_reason', 'ai_model_not_accessible')
            ->assertJsonPath('data.ai_quality_model_id', null)
            ->assertJsonPath('data.ai_quality_model_name', null)
            ->assertJsonPath('data.ai_quality_model_accessible', false);

        $this->assertStringNotContainsString('Ordinary Private Task Model', $response->getContent());
    }

    public function test_admin_governance_task_projection_keeps_existing_model_references(): void
    {
        $owner = $this->admin('task-response-governance-owner', 'admin');
        $model = $this->model($owner, 'Governance Model Reference', 'chat');
        $model->forceFill(['status' => 'inactive', 'archived_at' => now()])->save();
        $task = Task::query()->create([
            'name' => 'Governance task detail',
            'status' => 'paused',
            'ai_model_id' => $model->id,
            'ai_quality_model_id' => $model->id,
        ]);

        $detail = app(TaskMonitoringQueryService::class)->getTaskMonitoringDetail((int) $task->id);

        $this->assertSame((int) $model->id, $detail['ai_model_id']);
        $this->assertSame('Governance Model Reference', $detail['ai_model_name']);
        $this->assertSame((int) $model->id, $detail['ai_quality_model_id']);
        $this->assertSame('Governance Model Reference', $detail['ai_quality_model_name']);
        $this->assertArrayNotHasKey('ai_model_accessible', $detail);
        $this->assertArrayNotHasKey('ai_quality_model_accessible', $detail);
    }

    public function test_api_task_error_projection_never_exposes_historical_provider_diagnostics(): void
    {
        $actor = $this->admin('task-error-projection-actor', 'admin');
        $model = $this->model($actor, 'Task Error Private Model Name', 'chat');
        $task = Task::query()->create([
            'name' => 'Task with unsafe historical errors',
            'status' => 'active',
            'ai_model_id' => $model->id,
            'last_error_message' => 'provider https://task-error.example.test?token=task-token api_key=task-key Task Error Private Model Name',
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'failed',
            'error_message' => 'endpoint=https://run-error.example.test api_key=run-key Task Error Private Model Name',
        ]);
        $run->forceFill(['error_code' => 'ai_config_access_revoked'])->save();

        $response = $this->actingWithToken($actor, ['tasks:read'])
            ->getJson('/api/v1/tasks/'.(int) $task->id)
            ->assertOk()
            ->assertJsonPath('data.batch_error_message', 'ai_config_access_revoked')
            ->assertJsonPath('data.task_progress.last_error_message', 'task_execution_failed');

        foreach (['task-error.example.test', 'task-token', 'task-key', 'run-error.example.test', 'run-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $publicErrors = json_encode([
            $response->json('data.batch_error_message'),
            $response->json('data.task_progress.last_error_message'),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Task Error Private Model Name', $publicErrors);

        $governance = app(TaskMonitoringQueryService::class)->getTaskMonitoringDetail((int) $task->id);
        $governanceJson = json_encode($governance, JSON_THROW_ON_ERROR);
        foreach (['task-error.example.test', 'task-token', 'task-key', 'run-error.example.test', 'run-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $governanceJson);
        }
    }

    public function test_task_api_rejects_peer_and_system_models_with_a_stable_error(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $systemModel = $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        foreach ([$peerModel, $systemModel] as $model) {
            $this->actingWithToken($actor, ['tasks:write'])
                ->postJson('/api/v1/tasks', $this->taskPayload((int) $model->id))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_task_update_rejects_a_forged_peer_model_without_changing_the_task(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Personal Chat', 'chat');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $token = $actor->createToken('tasks', ['tasks:write', 'tasks:read'])->plainTextToken;
        $created = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $personal->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $peerModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'ai_model_id' => $personal->id]);
    }

    public function test_task_update_preserves_an_unchanged_formerly_shared_model_after_sharing_is_closed(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $shared = $this->model($provider, 'Shared Chat', 'chat');
        $token = $actor->createToken('tasks', ['tasks:write', 'tasks:read'])->plainTextToken;
        $created = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $shared->id))
            ->assertCreated()
            ->assertJsonPath('data.ai_model_id', (int) $shared->id)
            ->assertJsonPath('data.ai_model_name', 'Shared Chat')
            ->assertJsonPath('data.ai_model_accessible', true)
            ->assertJsonPath('data.ai_model_access_reason', null);
        $taskId = (int) $created->json('data.id');
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $shared->id,
                'name' => 'Updated without changing model',
                'config_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_model_id', null)
            ->assertJsonPath('data.ai_model_name', null)
            ->assertJsonPath('data.ai_model_accessible', false)
            ->assertJsonPath('data.ai_model_access_reason', 'ai_model_not_accessible');

        $this->assertStringNotContainsString('Shared Chat', $response->getContent());

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Updated without changing model',
            'ai_model_id' => $shared->id,
        ]);
    }

    public function test_idempotent_task_store_replay_reprojects_model_access_after_sharing_is_revoked(): void
    {
        $provider = $this->admin('task-replay-provider', 'super_admin');
        $actor = $this->admin('task-replay-actor', 'admin', $provider);
        $shared = $this->model($provider, 'Replay Shared Secret Name', 'chat');
        $token = $actor->createToken('task-replay', ['tasks:write', 'tasks:read'])->plainTextToken;
        $payload = $this->taskPayload((int) $shared->id);

        $created = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Idempotency-Key', 'task-model-access-replay')
            ->postJson('/api/v1/tasks', $payload)
            ->assertCreated()
            ->assertJsonPath('data.ai_model_id', (int) $shared->id)
            ->assertJsonPath('data.ai_model_name', 'Replay Shared Secret Name')
            ->assertJsonPath('data.ai_model_accessible', true);
        $taskId = (int) $created->json('data.id');
        Task::query()->whereKey($taskId)->update([
            'last_error_message' => 'https://replay-error.example.test api_key=replay-error-key Replay Shared Secret Name',
        ]);
        TaskRun::query()->create([
            'task_id' => $taskId,
            'status' => 'failed',
            'error_message' => 'https://replay-run.example.test api_key=replay-run-key Replay Shared Secret Name',
        ]);

        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $replay = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Idempotency-Key', 'task-model-access-replay')
            ->postJson('/api/v1/tasks', $payload)
            ->assertCreated()
            ->assertJsonPath('data.ai_model_id', null)
            ->assertJsonPath('data.ai_model_name', null)
            ->assertJsonPath('data.ai_model_accessible', false)
            ->assertJsonPath('data.ai_model_access_reason', 'ai_model_not_accessible')
            ->assertJsonPath('data.batch_error_message', 'task_execution_failed')
            ->assertJsonPath('data.task_progress.last_error_message', 'task_execution_failed');

        $this->assertStringNotContainsString('Replay Shared Secret Name', $replay->getContent());
        $this->assertStringNotContainsString('replay-error.example.test', $replay->getContent());
        $this->assertStringNotContainsString('replay-run.example.test', $replay->getContent());
        $this->assertStringNotContainsString('replay-error-key', $replay->getContent());
        $this->assertStringNotContainsString('replay-run-key', $replay->getContent());
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_reactivating_a_paused_task_revalidates_its_persisted_content_model(): void
    {
        $provider = $this->admin('reactivation-provider', 'super_admin');
        $actor = $this->admin('reactivation-actor', 'admin', $provider);
        $shared = $this->model($provider, 'Reactivation Shared Chat', 'chat');
        $created = $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $shared->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        try {
            app(TaskLifecycleService::class)->updateTask(
                $taskId,
                ['status' => 'active'],
                auditAdminId: (int) $actor->id,
            );
            $this->fail('Expected reactivation to reject the revoked content model.');
        } catch (ApiException $exception) {
            $this->assertSame('ai_model_not_accessible', $exception->getErrorCode());
            $this->assertArrayHasKey('ai_model_id', $exception->getDetails()['field_errors']);
        }

        $this->assertSame('paused', Task::query()->findOrFail($taskId)->status);
    }

    public function test_reenabling_quality_or_auto_optimization_revalidates_the_effective_quality_model(): void
    {
        $provider = $this->admin('quality-reenable-provider', 'super_admin');
        $actor = $this->admin('quality-reenable-actor', 'admin', $provider);
        $personal = $this->model($actor, 'Quality Reenable Personal Chat', 'chat');
        $sharedQuality = $this->model($provider, 'Quality Reenable Shared Chat', 'chat');
        $created = $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $personal->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');
        Task::query()->whereKey($taskId)->update([
            'ai_quality_enabled' => false,
            'ai_quality_auto_optimize_enabled' => false,
            'ai_quality_model_id' => $sharedQuality->id,
        ]);
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        foreach ([
            ['ai_quality_enabled' => true],
            ['ai_quality_enabled' => true, 'ai_quality_auto_optimize_enabled' => true],
        ] as $update) {
            try {
                app(TaskLifecycleService::class)->updateTask(
                    $taskId,
                    $update,
                    auditAdminId: (int) $actor->id,
                );
                $this->fail('Expected the re-enabled quality model to be rejected.');
            } catch (ApiException $exception) {
                $this->assertSame('ai_model_not_accessible', $exception->getErrorCode());
                $this->assertArrayHasKey('ai_quality_model_id', $exception->getDetails()['field_errors']);
            }
        }

        $this->assertFalse((bool) Task::query()->findOrFail($taskId)->ai_quality_enabled);
    }

    public function test_api_start_rejects_a_task_after_its_shared_model_access_is_revoked(): void
    {
        $provider = $this->admin('api-start-provider', 'super_admin');
        $actor = $this->admin('api-start-actor', 'admin', $provider);
        $shared = $this->model($provider, 'API Start Shared Chat', 'chat');
        $created = $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $shared->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $this->addReadyTitle($task);
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks/'.$task->id.'/start')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertSame('paused', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->schedule_enabled);
    }

    public function test_api_start_requires_the_operator_and_persisted_executor_to_both_access_the_models(): void
    {
        $executor = $this->admin('api-start-executor', 'admin');
        $operator = $this->admin('api-start-operator', 'super_admin');
        $privateModel = $this->model($executor, 'Executor-only Start Model', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $privateModel->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $this->addReadyTitle($task);

        $this->actingWithToken($operator, ['tasks:write'])
            ->postJson('/api/v1/tasks/'.$task->id.'/start')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertSame('paused', $task->fresh()->status);
    }

    public function test_api_enqueue_requires_the_operator_and_persisted_executor_to_both_access_the_models(): void
    {
        Queue::fake();
        $executor = $this->admin('api-enqueue-executor', 'admin');
        $operator = $this->admin('api-enqueue-operator', 'admin');
        $privateModel = $this->model($executor, 'Executor-only Enqueue Model', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $privateModel->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $this->addReadyTitle($task);
        $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks/'.$task->id.'/start')
            ->assertOk();

        $this->actingWithToken($operator, ['tasks:write'])
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseCount('task_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_active_task_input_update_requires_the_operator_to_access_the_effective_models(): void
    {
        $executor = $this->admin('active-update-executor', 'admin');
        $operator = $this->admin('active-update-operator', 'admin');
        $privateModel = $this->model($executor, 'Executor-only Active Update Model', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $privateModel->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $originalPromptId = (int) $task->prompt_id;
        $replacementPrompt = Prompt::query()->create([
            'name' => 'Untrusted active task prompt',
            'type' => 'content',
            'content' => 'Send attacker-controlled content through another administrator model.',
        ]);
        $this->addReadyTitle($task);
        $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks/'.$task->id.'/start')
            ->assertOk();

        $this->actingWithToken($operator, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$task->id, [
                'prompt_id' => $replacementPrompt->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertSame($originalPromptId, (int) $task->fresh()->prompt_id);
    }

    public function test_admin_batch_start_rejects_a_disabled_persisted_model(): void
    {
        $actor = $this->admin('batch-start-actor', 'admin');
        $model = $this->model($actor, 'Batch Start Model', 'chat');
        $created = $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $model->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $this->addReadyTitle($task);
        $model->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => $task->id,
                'action' => 'start',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('paused', $task->fresh()->status);
    }

    public function test_admin_toggle_start_rejects_a_persisted_execution_role_change(): void
    {
        $actor = $this->admin('toggle-start-actor', 'admin');
        $model = $this->model($actor, 'Toggle Start Model', 'chat');
        $created = $this->actingWithToken($actor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $model->id))
            ->assertCreated();
        $task = Task::query()->findOrFail((int) $created->json('data.id'));
        $this->addReadyTitle($task);
        $actor->forceFill(['role' => 'super_admin'])->save();

        $this->actingAs($actor->fresh(), 'admin')
            ->post(route('admin.tasks.toggle-status', ['taskId' => $task->id]), [
                'status' => 'paused',
            ])
            ->assertSessionHasErrors();

        $this->assertSame('paused', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->schedule_enabled);
    }

    public function test_task_update_validates_a_new_model_against_the_persisted_execution_admin(): void
    {
        $executor = $this->admin('executor', 'admin');
        $editor = $this->admin('editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Chat', 'chat');
        $editorModel = $this->model($editor, 'Editor Chat', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $editorModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'model_access_admin_id' => $executor->id,
            'ai_model_id' => $executorModel->id,
        ]);
    }

    public function test_super_admin_can_edit_unrelated_fields_without_gaining_access_to_the_task_model(): void
    {
        $executor = $this->admin('executor', 'admin');
        $editor = $this->admin('editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Private Chat', 'chat');
        $editorModel = $this->model($editor, 'Editor Chat', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'name' => 'Governed task rename',
                'ai_model_id' => $executorModel->id,
                'config_version' => 1,
            ])
            ->assertOk();

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $editorModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Governed task rename',
            'ai_model_id' => $executorModel->id,
            'model_access_admin_id' => $executor->id,
        ]);
    }

    public function test_super_admin_task_edit_shows_the_inaccessible_current_model_as_a_sanitized_disabled_option(): void
    {
        $executor = $this->admin('form-executor', 'admin');
        $editor = $this->admin('form-editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Private Form Model', 'chat');
        $executorQualityModel = $this->model($executor, 'Executor Private Quality Model', 'chat');
        $this->model($editor, 'Editor Form Model', 'chat');
        Category::query()->create(['name' => 'Task form category', 'slug' => 'task-form-category']);
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');
        Task::query()->whereKey($taskId)->update(['ai_quality_model_id' => $executorQualityModel->id]);

        $response = $this->actingAs($editor, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => $taskId]))
            ->assertOk();

        $response->assertViewHas('formOptions', function (array $options) use ($executorModel, $executorQualityModel): bool {
            $current = collect($options['aiModels'])->firstWhere('id', $executorModel->id);
            $quality = collect($options['aiModels'])->firstWhere('id', $executorQualityModel->id);

            return is_array($current)
                && ($current['disabled'] ?? false) === true
                && ($current['current_inaccessible'] ?? false) === true
                && ($current['current_inaccessible_for'] ?? null) === ['ai_model_id']
                && is_array($quality)
                && ($quality['disabled'] ?? false) === true
                && ($quality['current_inaccessible_for'] ?? null) === ['ai_quality_model_id'];
        });
        $response
            ->assertSee('Executor Private Form Model')
            ->assertSee('Executor Private Quality Model')
            ->assertSee('type="hidden" name="ai_model_id" value="'.$executorModel->id.'"', false)
            ->assertSee('type="hidden" name="ai_quality_model_id" value="'.$executorQualityModel->id.'"', false)
            ->assertDontSee('catalog-secret')
            ->assertDontSee('provider.example');

        $task = Task::query()->findOrFail($taskId);
        $taskForm = $response->viewData('taskForm');
        $this->actingAs($editor, 'admin')
            ->put(route('admin.tasks.update', ['taskId' => $taskId]), [
                'task_name' => 'Web governed rename',
                'title_library_id' => $task->title_library_id,
                'prompt_id' => $task->prompt_id,
                'ai_model_id' => $executorModel->id,
                'ai_quality_model_id' => $executorQualityModel->id,
                'status' => 'paused',
                'article_limit' => 1,
                'draft_limit' => 1,
                'publish_interval' => 60,
                'category_mode' => 'smart',
                'model_selection_mode' => 'fixed',
                'task_revision' => $taskForm['task_revision'],
                'config_version' => 1,
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Web governed rename',
            'ai_model_id' => $executorModel->id,
            'ai_quality_model_id' => $executorQualityModel->id,
        ]);
    }

    public function test_admin_task_form_only_lists_models_the_current_admin_can_use(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $shared = $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $this->model($peer, 'Peer Chat', 'chat');
        $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertViewHas('formOptions', function (array $options) use ($personal, $shared): bool {
                return collect($options['aiModels'])->pluck('id')->all() === [$personal->id, $shared->id];
            });
    }

    public function test_ai_workspace_draft_chooses_the_personal_model_before_the_shared_model(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $personal = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $this->taskDependencies();

        $draft = app(TaskLifecycleService::class)->createDraftTask(['name' => 'Scoped draft'], $actor);

        $this->assertSame($personal->id, $draft['ai_model_id']);
        $this->assertSame(
            $actor->id,
            Task::query()->findOrFail((int) $draft['id'])->model_access_admin_id,
        );
    }

    public function test_article_title_and_fact_model_dropdowns_are_scoped_to_the_current_admin(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Legacy Personal', null);
        $this->model($peer, 'Peer Chat', 'chat');
        $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Scoped title library']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Scoped knowledge base', 'content' => 'Evidence']);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.title-libraries.ai-generate', ['libraryId' => $titleLibrary->id]))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('aiModels', fn ($models): bool => $models->pluck('id')->all() === [$personal->id]);
        $this->actingAs($actor, 'admin')
            ->get(route('admin.knowledge-bases.facts.index', ['knowledgeBaseId' => $knowledgeBase->id]))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('factGenerationModels', fn ($models): bool => $models->pluck('id')->all() === [$personal->id]);
        $this->actingAs($actor, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('formOptions', fn (array $options): bool => collect($options['ai_models'])->pluck('id')->all() === [$personal->id]);
    }

    public function test_secondary_ai_entry_points_reject_a_peer_model_before_dispatch_or_outbound_work(): void
    {
        Queue::fake();
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $systemModel = $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Scoped title library']);
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Scoped keyword library']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Scoped knowledge base', 'content' => 'Evidence']);
        $prompt = Prompt::query()->create([
            'name' => 'Scoped article prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $titleLibrary->id]), [
                'keyword_library_id' => $keywordLibrary->id,
                'ai_model_id' => $peerModel->id,
                'title_count' => 1,
                'title_style' => 'professional',
            ])
            ->assertSessionHasErrors('ai_model_id');
        foreach ([$peerModel, $systemModel] as $forbiddenModel) {
            $this->actingAs($actor, 'admin')
                ->postJson(route('admin.knowledge-bases.fact-generation.store', ['knowledgeBaseId' => $knowledgeBase->id]), [
                    'mode' => 'initial',
                    'target_count' => 1,
                    'ai_model_id' => $forbiddenModel->id,
                    'request_key' => (string) Str::uuid(),
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');
        }
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => 'Scoped article',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $peerModel->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ai_model_not_accessible');

        $this->assertDatabaseCount('knowledge_fact_generation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_api_article_optimization_rejects_a_peer_model_before_starting_a_run(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Optimization', 'chat');
        $category = Category::query()->create(['name' => 'Scoped category', 'slug' => 'scoped-category']);
        $author = Author::query()->create(['name' => 'Scoped author']);
        $article = Article::query()->create([
            'title' => 'Scoped optimization article',
            'slug' => 'scoped-optimization-article',
            'content' => 'Scoped content.',
            'status' => 'draft',
            'review_status' => 'approved',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);

        $this->actingWithToken($actor, ['articles:publish'])
            ->withHeader('X-Idempotency-Key', 'scoped-optimization-'.$article->id)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/optimization", [
                'strategy' => 'excellent_80',
                'optimization_model_id' => $peerModel->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseCount('article_ai_optimization_runs', 0);
    }

    public function test_attached_task_optimization_rejects_forged_peer_and_system_models_on_web_and_api(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        $executor = $this->admin('optimization-executor', 'admin');
        $operator = $this->admin('optimization-operator', 'super_admin');
        $peer = $this->admin('optimization-peer', 'admin');
        $taskModel = $this->model($executor, 'Task Private Model', 'chat');
        $peerModel = $this->model($peer, 'Forged Peer Model', 'chat');
        $systemModel = $this->model(
            $operator,
            'Forged System Model',
            'chat',
            AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        );
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $taskModel->id))
            ->assertCreated();
        $category = Category::query()->create(['name' => 'Optimization category', 'slug' => 'optimization-category']);
        $author = Author::query()->create(['name' => 'Optimization author']);
        $article = Article::query()->create([
            'title' => 'Attached optimization article',
            'slug' => 'attached-optimization-article',
            'content' => 'Draft content.',
            'status' => 'draft',
            'review_status' => 'approved',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => (int) $created->json('data.id'),
        ]);

        foreach ([$peerModel, $systemModel] as $forgedModel) {
            $this->actingAs($operator, 'admin')
                ->postJson(route('admin.articles.ai-quality.optimization.store', ['articleId' => $article->id]), [
                    'request_key' => (string) Str::uuid(),
                    'strategy' => 'excellent_80',
                    'optimization_model_id' => $forgedModel->id,
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');

            $this->actingWithToken($operator, ['articles:publish'])
                ->withHeader('X-Idempotency-Key', 'forged-optimization-'.$forgedModel->id)
                ->postJson("/api/v1/articles/{$article->id}/ai-quality/optimization", [
                    'strategy' => 'excellent_80',
                    'optimization_model_id' => $forgedModel->id,
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');
        }

        $this->assertDatabaseCount('article_ai_optimization_runs', 0);
    }

    private function admin(string $username, string $role, ?Admin $provider = null): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'password',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill([
            'shared_ai_config_owner_id' => $provider?->id,
            'ai_config_access_version' => 1,
        ])->save();

        return $admin;
    }

    private function model(
        Admin $owner,
        string $name,
        ?string $type,
        string $scope = AiModel::ACCESS_SCOPE_USER_CONTENT,
        int $priority = 100,
    ): AiModel {
        $model = new AiModel;
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'name' => $name,
            'version' => 'v1',
            'api_key' => 'catalog-secret',
            'model_id' => str($name)->slug()->toString(),
            'model_type' => $type,
            'api_url' => 'https://provider.example/v1?token=catalog-secret',
            'failover_priority' => $priority,
            'access_scope' => $scope,
            'status' => 'active',
        ])->save();

        return $model;
    }

    /** @return array<string, int|string> */
    private function taskPayload(int $modelId): array
    {
        [$prompt, $titleLibrary] = $this->taskDependencies();

        return [
            'name' => 'Scoped API task '.$modelId,
            'title_library_id' => $titleLibrary->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $modelId,
            'status' => 'paused',
            'category_mode' => 'smart',
            'draft_limit' => 1,
            'article_limit' => 1,
        ];
    }

    /** @return array{Prompt, TitleLibrary} */
    private function taskDependencies(): array
    {
        $prompt = Prompt::query()->firstOrCreate(
            ['name' => 'Scoped Prompt'],
            ['type' => 'content', 'content' => 'Write an article.'],
        );
        $titleLibrary = TitleLibrary::query()->firstOrCreate(
            ['name' => 'Scoped Titles'],
            ['description' => '', 'title_count' => 0],
        );

        return [$prompt, $titleLibrary];
    }

    private function addReadyTitle(Task $task): void
    {
        Title::query()->create([
            'library_id' => (int) $task->title_library_id,
            'title' => 'Ready title for task '.$task->id,
            'keyword' => 'ready',
            'used_count' => 0,
        ]);
    }

    /** @param list<string> $scopes */
    private function actingWithToken(Admin $admin, array $scopes): static
    {
        $plainToken = $admin->createToken('entry-access', $scopes)->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plainToken);
    }
}
