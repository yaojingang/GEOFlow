<?php

namespace Tests\Feature;

use App\Ai\Agents\KnowledgeFactGeneratorAgent;
use App\Exceptions\AiModelAccessException;
use App\Jobs\FinalizeKnowledgeFactGenerationJob;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationAiExecutionGuard;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use App\Support\GeoFlow\ApiKeyCrypto;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Tests\TestCase;
use Throwable;

class AdminKnowledgeFactAiExecutionIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_fact_generation_run_has_execution_identity_and_batch_lease_columns(): void
    {
        foreach ([
            'model_access_admin_id',
            'model_access_admin_role',
            'ai_config_access_version',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'resolved_model_source',
            'model_resolved_at',
            'resolver_policy_version',
            'error_code',
            'retryable_failure',
            'execution_attempt',
            'batch_claims_json',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('knowledge_fact_generation_runs', $column),
                $column.' should exist',
            );
        }
    }

    public function test_generation_run_json_hides_execution_identity_model_resolution_and_leases(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge-json-admin',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Knowledge JSON model',
            'model_id' => 'knowledge-json-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $base = KnowledgeBase::query()->create(['name' => 'Knowledge JSON']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id,
            'mode' => 'initial',
            'target_count' => 1,
            'source_hash' => str_repeat('a', 64),
            'base_working_version' => 1,
            'status' => KnowledgeFactGenerationRun::STATUS_RUNNING,
            'request_key' => fake()->uuid(),
            'result_json' => [],
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 1,
            'requested_ai_model_id' => $model->id,
            'resolved_ai_model_id' => $model->id,
            'resolved_model_source' => 'personal',
            'resolver_policy_version' => 1,
            'retryable_failure' => false,
            'execution_attempt' => 2,
            'batch_claims_json' => ['1' => ['lease_token' => 'secret-lease']],
            'finalizer_lease_token' => fake()->uuid(),
            'finalizer_lease_expires_at' => now()->addMinute(),
        ])->save();

        $this->assertTrue($run->fresh()->modelAccessAdmin()->is($admin));
        $this->assertTrue($run->fresh()->requestedAiModel()->is($model));
        $this->assertTrue($run->fresh()->resolvedAiModel()->is($model));
        $this->assertFalse((bool) $run->fresh()->retryable_failure);
        $this->assertSame(2, (int) $run->fresh()->execution_attempt);

        $json = $run->fresh()->toArray();
        foreach ([
            'model_access_admin_id',
            'model_access_admin_role',
            'ai_config_access_version',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'resolved_model_source',
            'model_resolved_at',
            'resolver_policy_version',
            'execution_attempt',
            'batch_claims_json',
            'finalizer_lease_token',
            'finalizer_lease_expires_at',
        ] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $json);
        }
    }

    public function test_regular_admin_can_start_generation_with_a_personal_requested_model_and_freezes_identity(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);
        Bus::fake();
        $admin = $this->admin('knowledge-personal');
        $model = $this->model($admin, 'knowledge-personal-model');
        [$base] = $this->knowledgeFixtures('Knowledge personal');

        $response = $this->actingAs($admin, 'admin')->postJson(
            route('admin.knowledge-bases.fact-generation.store', $base->id),
            [
                'mode' => 'initial',
                'target_count' => 1,
                'ai_model_id' => $model->id,
                'request_key' => fake()->uuid(),
            ],
        );

        $response->assertAccepted();
        $run = KnowledgeFactGenerationRun::query()->sole();
        $this->assertSame($admin->id, $run->model_access_admin_id);
        $this->assertSame('admin', $run->model_access_admin_role);
        $this->assertSame(1, $run->ai_config_access_version);
        $this->assertSame($model->id, $run->requested_ai_model_id);
        $this->assertSame(1, $run->resolver_policy_version);
        $this->assertSame(1, $run->execution_attempt);
    }

    public function test_regular_admin_can_start_generation_with_an_explicit_shared_model(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $owner = $this->admin('knowledge-shared-owner', ['role' => 'super_admin']);
        $admin = $this->admin('knowledge-shared-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $owner->id])->save();
        $model = $this->model($owner, 'knowledge-shared-model');
        [$base] = $this->knowledgeFixtures('Knowledge shared');

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.knowledge-bases.fact-generation.store', $base->id),
            [
                'mode' => 'initial',
                'target_count' => 1,
                'ai_model_id' => $model->id,
                'request_key' => fake()->uuid(),
            ],
        )->assertAccepted();

        $run = KnowledgeFactGenerationRun::query()->sole();
        $this->assertSame($admin->id, $run->model_access_admin_id);
        $this->assertSame($model->id, $run->requested_ai_model_id);
        $this->assertNull($run->resolved_ai_model_id);
    }

    public function test_generation_start_hides_peer_system_and_regular_admin_private_model_ids(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-isolated-consumer');
        $peer = $this->admin('knowledge-isolated-peer');
        $super = $this->admin('knowledge-isolated-super', ['role' => 'super_admin']);
        $peerModel = $this->model($peer, 'knowledge-peer-model');
        $systemModel = $this->model($super, 'knowledge-system-model', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $superPrivate = $this->model($super, 'knowledge-super-private-model');
        $super->forceFill(['shared_ai_config_owner_id' => null])->save();

        foreach ([$peerModel, $systemModel, $superPrivate] as $index => $model) {
            [$base] = $this->knowledgeFixtures('Knowledge isolated '.$index);
            $response = $this->actingAs($admin, 'admin')->postJson(
                route('admin.knowledge-bases.fact-generation.store', $base->id),
                [
                    'mode' => 'initial',
                    'target_count' => 1,
                    'ai_model_id' => $model->id,
                    'request_key' => fake()->uuid(),
                ],
            );

            $response->assertNotFound()->assertJsonPath('error.code', 'ai_model_not_accessible');
        }

        [$base] = $this->knowledgeFixtures('Knowledge super admin isolated');
        $this->actingAs($super, 'admin')->postJson(
            route('admin.knowledge-bases.fact-generation.store', $base->id),
            [
                'mode' => 'initial',
                'target_count' => 1,
                'ai_model_id' => $peerModel->id,
                'request_key' => fake()->uuid(),
            ],
        )->assertNotFound()->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseCount('knowledge_fact_generation_runs', 0);
    }

    public function test_dispatched_batches_carry_database_registered_attempt_tokens(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-dispatch-token');
        $model = $this->model($admin, 'knowledge-dispatch-token-model');
        [$base] = $this->knowledgeFixtures('Knowledge dispatch token');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);

        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $model, $admin, 'initial', 1);

        $claims = (array) $run->fresh()->batch_claims_json;
        $this->assertSame('queued', data_get($claims, '1.status'));
        $this->assertNotEmpty(data_get($claims, '1.dispatch_token'));
        Bus::assertBatched(function (PendingBatch $batch) use ($run, $claims): bool {
            $job = $batch->jobs->first();

            return $job instanceof GenerateKnowledgeFactBatchJob
                && $job->executionAttempt === (int) $run->execution_attempt
                && hash_equals((string) data_get($claims, '1.dispatch_token'), $job->claimToken);
        });
    }

    public function test_batch_uses_the_explicit_requested_model_and_records_safe_resolution_metadata(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-requested-primary');
        $fallback = $this->model($admin, 'knowledge-requested-fallback', ['failover_priority' => 1]);
        $requested = $this->model($admin, 'knowledge-requested-primary-model', ['failover_priority' => 50]);
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge requested primary');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();

        app(KnowledgeFactGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            1,
            $inputHash,
            $evidence,
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        );

        $run->refresh();
        $this->assertSame('completed', data_get($run->batch_claims_json, '1.status'));
        $this->assertSame($requested->id, data_get($run->batch_claims_json, '1.resolved_ai_model_id'));
        $this->assertSame('personal', data_get($run->batch_claims_json, '1.resolved_model_source'));
        $this->assertArrayNotHasKey('dispatch_token', (array) data_get($run->result_json, 'batches.1'));
        $this->assertSame($requested->id, $run->resolved_ai_model_id);
        $this->assertSame('personal', $run->resolved_model_source);
        KnowledgeFactGeneratorAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $requested->model_id,
        );
        KnowledgeFactGeneratorAgent::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $fallback->model_id,
        );
    }

    public function test_transient_personal_failure_falls_back_to_shared_model(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $owner = $this->admin('knowledge-fallback-owner', ['role' => 'super_admin']);
        $admin = $this->admin('knowledge-fallback-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $owner->id])->save();
        $requested = $this->model($admin, 'knowledge-fallback-personal', ['failover_priority' => 50]);
        $shared = $this->model($owner, 'knowledge-fallback-shared', ['failover_priority' => 1]);
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge transient fallback');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $evidence = $this->evidenceDescriptors($chunk);
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls, $chunk): array {
            $calls++;
            if ($calls === 1) {
                throw $this->requestException(429, 'temporary provider failure');
            }

            return ['facts' => [$this->validFact($chunk)]];
        })->preventStrayPrompts();

        app(KnowledgeFactGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $evidence,
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        );

        $run->refresh();
        $this->assertSame(2, $calls);
        $this->assertSame($shared->id, data_get($run->batch_claims_json, '1.resolved_ai_model_id'));
        $this->assertSame('shared', data_get($run->batch_claims_json, '1.resolved_model_source'));
        $this->assertSame($shared->id, $run->resolved_ai_model_id);
        KnowledgeFactGeneratorAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $requested->model_id,
        );
        KnowledgeFactGeneratorAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $shared->model_id,
        );
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $events[0]->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $events[0]->model_source);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[1]->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $events[1]->model_source);
        $this->assertSame($owner->id, $events[1]->config_owner_admin_id);
        $this->assertSame($admin->id, $events[1]->execution_admin_id);
    }

    public function test_runtime_model_rate_limit_is_shared_across_runs_and_releases_before_fallback(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.knowledge_fact_generation_rate_per_minute', 1);
        Bus::fake();
        $admin = $this->admin('knowledge-runtime-rate-shared');
        $requested = $this->model($admin, 'knowledge-runtime-rate-model', ['failover_priority' => 1]);
        $fallback = $this->model($admin, 'knowledge-runtime-rate-fallback', ['failover_priority' => 2]);
        [$firstBase, $firstChunk] = $this->knowledgeFixtures('Knowledge runtime rate first');
        [$secondBase, $secondChunk] = $this->knowledgeFixtures('Knowledge runtime rate second');
        $firstRun = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $firstBase->id]),
            $requested,
            $admin,
            'initial',
            1,
        );
        $secondRun = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $secondBase->id]),
            $requested,
            $admin,
            'initial',
            1,
        );
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls, $firstChunk): array {
            $calls++;

            return ['facts' => [$this->validFact($firstChunk)]];
        })->preventStrayPrompts();
        $firstClaim = data_get($firstRun->fresh()->batch_claims_json, '1');
        app(KnowledgeFactGenerationCoordinator::class)->processBatch(
            (int) $firstRun->id,
            1,
            (string) data_get($firstClaim, 'input_hash'),
            $this->evidenceDescriptors($firstChunk),
            (int) $firstRun->execution_attempt,
            (string) data_get($firstClaim, 'dispatch_token'),
        );

        $secondClaim = data_get($secondRun->fresh()->batch_claims_json, '1');
        $secondJob = (new GenerateKnowledgeFactBatchJob(
            (int) $secondRun->id,
            1,
            (string) data_get($secondClaim, 'input_hash'),
            $this->evidenceDescriptors($secondChunk),
            (int) $secondRun->execution_attempt,
            (string) data_get($secondClaim, 'dispatch_token'),
        ))->withFakeQueueInteractions();
        $secondJob->handle(app(KnowledgeFactGenerationCoordinator::class));

        $secondJob->assertReleased();
        $this->assertSame(1, $calls);
        $this->assertSame(
            1,
            RateLimiter::attempts('knowledge-fact-generation:model:'.$requested->id),
        );
        $secondRun->refresh();
        $this->assertSame('queued', data_get($secondRun->batch_claims_json, '1.status'));
        $this->assertNull(data_get($secondRun->batch_claims_json, '1.resolved_ai_model_id'));
        $this->assertNull(data_get($secondRun->batch_claims_json, '1.resolved_model_source'));
        KnowledgeFactGeneratorAgent::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $fallback->model_id,
        );
    }

    public function test_shared_fallback_model_rate_limit_releases_without_invoking_later_candidates(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.knowledge_fact_generation_rate_per_minute', 1);
        Bus::fake();
        $owner = $this->admin('knowledge-runtime-shared-owner', ['role' => 'super_admin']);
        $admin = $this->admin('knowledge-runtime-shared-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $owner->id])->save();
        $requested = $this->model($admin, 'knowledge-runtime-personal', ['failover_priority' => 1]);
        $shared = $this->model($owner, 'knowledge-runtime-shared', ['failover_priority' => 1]);
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge runtime shared fallback');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]),
            $requested,
            $admin,
            'initial',
            1,
        );
        RateLimiter::hit('knowledge-fact-generation:model:'.$shared->id, 60);
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls): never {
            $calls++;

            throw $this->requestException(429, 'temporary primary failure');
        })->preventStrayPrompts();
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $job = (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->withFakeQueueInteractions();

        $job->handle(app(KnowledgeFactGenerationCoordinator::class));

        $job->assertReleased();
        $this->assertSame(1, $calls);
        $run->refresh();
        $this->assertSame('queued', data_get($run->batch_claims_json, '1.status'));
        $this->assertNull(data_get($run->batch_claims_json, '1.resolved_ai_model_id'));
        KnowledgeFactGeneratorAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $requested->model_id,
        );
        KnowledgeFactGeneratorAgent::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $shared->model_id,
        );
    }

    public function test_repeated_local_model_rate_limits_do_not_consume_batch_attempts(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.knowledge_fact_generation_rate_per_minute', 1);
        config()->set('geoflow.knowledge_fact_generation_max_batch_attempts', 3);
        Bus::fake();
        $admin = $this->admin('knowledge-runtime-rate-refund');
        $model = $this->model($admin, 'knowledge-runtime-rate-refund-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge runtime rate refund');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]),
            $model,
            $admin,
            'initial',
            1,
        );
        RateLimiter::hit('knowledge-fact-generation:model:'.$model->id, 60);
        KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $claim = data_get($run->fresh()->batch_claims_json, '1');
            $job = (new GenerateKnowledgeFactBatchJob(
                (int) $run->id,
                1,
                (string) data_get($claim, 'input_hash'),
                $this->evidenceDescriptors($chunk),
                (int) $run->execution_attempt,
                (string) data_get($claim, 'dispatch_token'),
            ))->withFakeQueueInteractions();

            $job->handle(app(KnowledgeFactGenerationCoordinator::class));

            $job->assertReleased();
            $run->refresh();
            $this->assertSame('queued', data_get($run->batch_claims_json, '1.status'));
            $this->assertSame(0, data_get($run->batch_claims_json, '1.attempt_count'));
        }

        KnowledgeFactGeneratorAgent::assertNotPrompted(static fn (): bool => true);
    }

    public function test_refunded_attempt_keeps_the_previous_worker_fenced_by_its_lease(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-refund-lease-fence');
        $model = $this->model($admin, 'knowledge-refund-lease-fence-model');
        [$base] = $this->knowledgeFixtures('Knowledge refund lease fence');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]),
            $model,
            $admin,
            'initial',
            1,
        );
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $oldContext = $coordinator->claimBatch(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
            'old-refunded-lease',
        );
        $this->assertNotNull($oldContext);
        $coordinator->releaseBatchForRetry($oldContext, refundAttempt: true);
        $currentContext = $coordinator->claimBatch(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
            'current-refunded-lease',
        );
        $this->assertNotNull($currentContext);

        try {
            $coordinator->releaseBatchForRetry($oldContext, refundAttempt: true);
            $this->fail('The old refunded worker lease must remain fenced.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $this->assertSame('running', data_get($claim, 'status'));
        $this->assertSame(1, data_get($claim, 'attempt_count'));
        $this->assertSame('current-refunded-lease', data_get($claim, 'lease_token'));
    }

    public function test_provider_is_not_called_when_shared_access_changes_after_key_decryption(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $owner = $this->admin('knowledge-pre-prompt-owner', ['role' => 'super_admin']);
        $admin = $this->admin('knowledge-pre-prompt-admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $owner->id])->save();
        $model = $this->model($owner, 'knowledge-pre-prompt-shared-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge pre prompt revoke');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]),
            $model,
            $admin,
            'initial',
            1,
        );
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $this->app->instance(ApiKeyCrypto::class, new class($admin) extends ApiKeyCrypto
        {
            private bool $revoked = false;

            public function __construct(private readonly Admin $admin) {}

            public function decrypt(string $storedApiKey): string
            {
                $key = parent::decrypt($storedApiKey);
                if (! $this->revoked) {
                    $this->revoked = true;
                    $this->admin->forceFill([
                        'shared_ai_config_owner_id' => null,
                        'ai_config_access_version' => (int) $this->admin->ai_config_access_version + 1,
                    ])->save();
                }

                return $key;
            }
        });
        KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle(app(KnowledgeFactGenerationCoordinator::class));

        KnowledgeFactGeneratorAgent::assertNotPrompted(static fn (): bool => true);
        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse($run->retryable_failure);
    }

    public function test_provider_response_is_discarded_after_key_rotation_and_invocation_lock_is_held(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-key-rotation-admin');
        $model = $this->model($admin, 'knowledge-key-rotation-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge key rotation');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]),
            $model,
            $admin,
            'initial',
            1,
        );
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $contenderAcquired = null;
        $rotatedKey = app(ApiKeyCrypto::class)->encrypt('rotated-secret');
        KnowledgeFactGeneratorAgent::fake(function () use (&$contenderAcquired, $model, $chunk, $rotatedKey): array {
            $locks = app(AiModelInvocationLock::class);
            $contender = $locks->acquireForMutation((int) $model->id);
            $contenderAcquired = $contender !== null;
            $locks->release($contender);
            AiModel::query()->whereKey($model->id)->update(['api_key' => $rotatedKey]);

            return ['facts' => [$this->validFact($chunk)]];
        })->preventStrayPrompts();

        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle(app(KnowledgeFactGenerationCoordinator::class));

        $this->assertFalse($contenderAcquired);
        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertSame([], data_get($run->result_json, 'candidates'));
        $this->assertDatabaseCount('knowledge_facts', 0);
    }

    public function test_insufficient_provider_credits_never_fall_back_to_a_shared_model(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $owner = $this->admin('knowledge-credits-owner', ['role' => 'super_admin']);
        $admin = $this->admin('knowledge-credits-consumer');
        $admin->forceFill(['shared_ai_config_owner_id' => $owner->id])->save();
        $requested = $this->model($admin, 'knowledge-credits-primary');
        $shared = $this->model($owner, 'knowledge-credits-shared');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge credits permanent');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls): never {
            $calls++;

            throw InsufficientCreditsException::forProvider('secret-credit-provider');
        })->preventStrayPrompts();

        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle(app(KnowledgeFactGenerationCoordinator::class));

        $run->refresh();
        $this->assertSame(1, $calls);
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertFalse((bool) $run->retryable_failure);
        KnowledgeFactGeneratorAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $requested->model_id,
        );
        KnowledgeFactGeneratorAgent::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $shared->model_id,
        );
    }

    public function test_authentication_and_authorization_failures_do_not_fall_back_or_retry(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        foreach ([401, 403] as $status) {
            $admin = $this->admin('knowledge-permanent-'.$status);
            $requested = $this->model($admin, 'knowledge-permanent-primary-'.$status, ['failover_priority' => 1]);
            $this->model($admin, 'knowledge-permanent-fallback-'.$status, ['failover_priority' => 2]);
            [$base, $chunk] = $this->knowledgeFixtures('Knowledge permanent '.$status);
            $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
            $run = app(KnowledgeFactGenerationCoordinator::class)
                ->start($library, $requested, $admin, 'initial', 1);
            $claim = data_get($run->fresh()->batch_claims_json, '1');
            $calls = 0;
            KnowledgeFactGeneratorAgent::fake(function () use (&$calls, $status): array {
                $calls++;
                throw $this->requestException($status, 'Bearer secret-provider-token');
            })->preventStrayPrompts();

            (new GenerateKnowledgeFactBatchJob(
                (int) $run->id,
                1,
                (string) data_get($claim, 'input_hash'),
                $this->evidenceDescriptors($chunk),
                (int) $run->execution_attempt,
                (string) data_get($claim, 'dispatch_token'),
            ))->handle(app(KnowledgeFactGenerationCoordinator::class));

            $run->refresh();
            $this->assertSame(1, $calls, 'HTTP '.$status.' must not fall back.');
            $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
            $this->assertSame('ai_provider_request_rejected', $run->error_code);
            $this->assertFalse((bool) $run->retryable_failure);
        }
        $this->assertDatabaseCount('knowledge_facts', 0);
    }

    public function test_configuration_and_capability_failures_do_not_fall_back(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        foreach (['configuration', 'capability'] as $case) {
            $admin = $this->admin('knowledge-'.$case.'-failure');
            $attributes = $case === 'capability'
                ? ['ai_workspace_readiness_profile' => ['knowledge_fact_structured_output' => ['status' => 'unsupported']]]
                : [];
            $requested = $this->model($admin, 'knowledge-'.$case.'-primary', $attributes);
            if ($case === 'configuration') {
                $requested->forceFill(['api_key' => ''])->save();
            }
            $this->model($admin, 'knowledge-'.$case.'-fallback', ['failover_priority' => 2]);
            [$base, $chunk] = $this->knowledgeFixtures('Knowledge '.$case.' failure');
            $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
            $run = app(KnowledgeFactGenerationCoordinator::class)
                ->start($library, $requested, $admin, 'initial', 1);
            $claim = data_get($run->fresh()->batch_claims_json, '1');
            KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

            (new GenerateKnowledgeFactBatchJob(
                (int) $run->id,
                1,
                (string) data_get($claim, 'input_hash'),
                $this->evidenceDescriptors($chunk),
                (int) $run->execution_attempt,
                (string) data_get($claim, 'dispatch_token'),
            ))->handle(app(KnowledgeFactGenerationCoordinator::class));

            $run->refresh();
            $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
            $this->assertFalse((bool) $run->retryable_failure);
            KnowledgeFactGeneratorAgent::assertNeverPrompted();
        }
    }

    public function test_queued_batch_stops_before_provider_call_after_access_version_changes(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-queued-revoked');
        $requested = $this->model($admin, 'knowledge-queued-revoked-primary');
        $this->model($admin, 'knowledge-queued-revoked-fallback');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge queued revoked');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $admin->forceFill(['ai_config_access_version' => 2])->save();
        KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle(app(KnowledgeFactGenerationCoordinator::class));

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
        KnowledgeFactGeneratorAgent::assertNeverPrompted();
    }

    public function test_provider_result_is_discarded_when_access_is_revoked_during_the_call(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-mid-call-revoked');
        $requested = $this->model($admin, 'knowledge-mid-call-revoked-primary');
        $this->model($admin, 'knowledge-mid-call-revoked-fallback');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge mid call revoked');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls, $admin, $chunk): array {
            $calls++;
            $admin->forceFill(['ai_config_access_version' => 2])->save();

            return ['facts' => [$this->validFact($chunk)]];
        })->preventStrayPrompts();

        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle(app(KnowledgeFactGenerationCoordinator::class));

        $run->refresh();
        $this->assertSame(1, $calls);
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
        $this->assertSame([], (array) data_get($run->result_json, 'candidates'));
        $this->assertSame(0, (int) $requested->fresh()->total_used);
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $usageEvent->status);
        $this->assertSame('ai_config_access_revoked', $usageEvent->error_code);
    }

    public function test_finalize_discards_candidates_when_access_is_revoked_before_materialization(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-finalize-revoked');
        $requested = $this->model($admin, 'knowledge-finalize-revoked-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge finalize revoked');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $requested, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();
        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle($coordinator);
        $this->assertCount(1, (array) data_get($run->fresh()->result_json, 'candidates'));
        $admin->forceFill(['ai_config_access_version' => 2])->save();

        (new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            (int) $run->execution_attempt,
            (string) $run->fresh()->finalizer_lease_token,
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) $run->retryable_failure);
        $this->assertDatabaseCount('knowledge_facts', 0);
        $this->assertDatabaseCount('knowledge_fact_evidences', 0);
    }

    public function test_historical_active_runs_with_missing_or_malformed_identity_fail_closed(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        foreach (['missing', 'malformed'] as $case) {
            $admin = $this->admin('knowledge-historical-'.$case);
            $model = $this->model($admin, 'knowledge-historical-model-'.$case);
            [$base, $chunk] = $this->knowledgeFixtures('Knowledge historical '.$case);
            $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
            $evidence = $this->evidenceDescriptors($chunk);
            $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $token = fake()->uuid();
            $run = KnowledgeFactGenerationRun::query()->create([
                'library_id' => $library->id,
                'mode' => 'initial',
                'target_count' => 1,
                'source_hash' => $base->servingChunkSourceHash(),
                'base_working_version' => $library->working_version,
                'status' => KnowledgeFactGenerationRun::STATUS_RUNNING,
                'ai_model_id' => $model->id,
                'created_by_admin_id' => $admin->id,
                'request_key' => fake()->uuid(),
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'result_json' => ['candidates' => [], 'conflicts' => [], 'batches' => []],
            ]);
            $identity = $case === 'malformed' ? [
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'invalid-role',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
            ] : [];
            $run->forceFill([
                ...$identity,
                'execution_attempt' => 1,
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'queued',
                    'dispatch_token' => $token,
                    'execution_attempt' => 1,
                    'attempt_count' => 0,
                ]],
            ])->save();
            KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

            (new GenerateKnowledgeFactBatchJob(
                (int) $run->id,
                1,
                $inputHash,
                $evidence,
                1,
                $token,
            ))->handle(app(KnowledgeFactGenerationCoordinator::class));

            $run->refresh();
            $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
            $this->assertSame('ai_config_access_revoked', $run->error_code);
            $this->assertFalse((bool) $run->retryable_failure);
            KnowledgeFactGeneratorAgent::assertNeverPrompted();
        }
    }

    public function test_stale_batch_job_and_failed_callback_cannot_overwrite_a_new_attempt(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-stale-batch');
        $model = $this->model($admin, 'knowledge-stale-batch-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge stale batch');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $model, $admin, 'initial', 1);
        $oldClaim = data_get($run->fresh()->batch_claims_json, '1');
        $oldFinalizerToken = (string) $run->fresh()->finalizer_lease_token;
        $oldJob = new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($oldClaim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            1,
            (string) data_get($oldClaim, 'dispatch_token'),
        );
        $newToken = fake()->uuid();
        $newFinalizerToken = fake()->uuid();
        $run->forceFill([
            'execution_attempt' => 2,
            'batch_claims_json' => ['1' => [
                ...$oldClaim,
                'status' => 'queued',
                'dispatch_token' => $newToken,
                'execution_attempt' => 2,
                'attempt_count' => 0,
            ]],
            'finalizer_lease_token' => $newFinalizerToken,
        ])->save();
        KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

        $oldJob->handle(app(KnowledgeFactGenerationCoordinator::class));
        $oldJob->failed(new \RuntimeException(
            'https://provider.test/v1 api_key=secret-key prompt=secret-prompt evidence=secret-evidence',
        ));
        $oldFinalizer = new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            1,
            $oldFinalizerToken,
        );
        $oldFinalizer->handle(app(KnowledgeFactGenerationCoordinator::class));
        $oldFinalizer->failed(new \RuntimeException('stale finalizer'));

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->execution_attempt);
        $this->assertSame('queued', data_get($run->batch_claims_json, '1.status'));
        $this->assertSame($newToken, data_get($run->batch_claims_json, '1.dispatch_token'));
        $this->assertSame($newFinalizerToken, $run->finalizer_lease_token);
        $this->assertNull($run->error_code);
        $this->assertSame([], (array) data_get($run->result_json, 'candidates'));
        KnowledgeFactGeneratorAgent::assertNeverPrompted();
    }

    public function test_live_old_worker_and_its_failed_callback_are_fenced_after_batch_reclaim(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-live-stale-worker');
        $model = $this->model($admin, 'knowledge-live-stale-worker-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge live stale worker');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $model, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $newLeaseToken = fake()->uuid();
        $reclaimed = null;
        $calls = 0;
        KnowledgeFactGeneratorAgent::fake(function () use (&$calls, &$reclaimed, $run, $claim, $newLeaseToken, $chunk): array {
            $calls++;
            $claims = (array) $run->fresh()->batch_claims_json;
            $claims['1']['lease_expires_at'] = now()->subSecond()->toIso8601String();
            $run->forceFill(['batch_claims_json' => $claims])->save();
            $reclaimed = app(KnowledgeFactGenerationAiExecutionGuard::class)->claimBatch(
                (int) $run->id,
                1,
                (string) data_get($claim, 'input_hash'),
                (int) $run->execution_attempt,
                (string) data_get($claim, 'dispatch_token'),
                $newLeaseToken,
            );

            return ['facts' => [$this->validFact($chunk)]];
        })->preventStrayPrompts();
        $oldJob = new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        );

        $oldJob->handle(app(KnowledgeFactGenerationCoordinator::class));
        $oldJob->failed(new \RuntimeException('old worker failure'));

        $run->refresh();
        $this->assertSame(1, $calls);
        $this->assertNotNull($reclaimed);
        $this->assertSame(2, $reclaimed->batchAttempt);
        $this->assertSame($newLeaseToken, $reclaimed->leaseToken());
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, data_get($run->batch_claims_json, '1.attempt_count'));
        $this->assertSame($newLeaseToken, data_get($run->batch_claims_json, '1.lease_token'));
        $this->assertSame([], (array) data_get($run->result_json, 'candidates'));
        $this->assertNull($run->error_code);
    }

    public function test_old_worker_cannot_mark_a_new_attempt_obsolete_for_stale_chunk_evidence(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-stale-evidence-worker');
        $model = $this->model($admin, 'knowledge-stale-evidence-worker-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge stale evidence worker');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $model, $admin, 'initial', 1);
        $oldClaim = data_get($run->fresh()->batch_claims_json, '1');
        $oldEvidence = $this->evidenceDescriptors($chunk);
        $guard = app(KnowledgeFactGenerationAiExecutionGuard::class);
        $oldContext = $guard->claimBatch(
            (int) $run->id,
            1,
            (string) data_get($oldClaim, 'input_hash'),
            1,
            (string) data_get($oldClaim, 'dispatch_token'),
            fake()->uuid(),
        );
        $this->assertNotNull($oldContext);

        $chunk->forceFill([
            'content' => '公司成立于 2021 年。',
            'content_hash' => hash('sha256', 'new chunk content'),
        ])->save();
        $newEvidence = $this->evidenceDescriptors($chunk->fresh());
        $newInputHash = hash('sha256', json_encode($newEvidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $newDispatchToken = fake()->uuid();
        $newLeaseToken = fake()->uuid();
        $run->forceFill([
            'execution_attempt' => 2,
            'batch_claims_json' => ['1' => [
                'input_hash' => $newInputHash,
                'status' => 'queued',
                'dispatch_token' => $newDispatchToken,
                'execution_attempt' => 2,
                'attempt_count' => 0,
            ]],
        ])->save();
        $newContext = $guard->claimBatch(
            (int) $run->id,
            1,
            $newInputHash,
            2,
            $newDispatchToken,
            $newLeaseToken,
        );
        $this->assertNotNull($newContext);
        KnowledgeFactGeneratorAgent::fake()->preventStrayPrompts();

        app(KnowledgeFactGenerationCoordinator::class)->processClaimedBatch(
            $oldContext,
            $oldEvidence,
        );

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->execution_attempt);
        $this->assertSame('running', data_get($run->batch_claims_json, '1.status'));
        $this->assertSame(1, data_get($run->batch_claims_json, '1.attempt_count'));
        $this->assertSame($newLeaseToken, data_get($run->batch_claims_json, '1.lease_token'));
        $this->assertSame([], (array) data_get($run->result_json, 'candidates'));
        $this->assertNull($run->error_code);
        $this->assertTrue($guard->assertCurrent($newContext)->is($admin));
        KnowledgeFactGeneratorAgent::assertNeverPrompted();
    }

    public function test_current_finalize_lease_materializes_facts_and_evidence(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-finalize-success');
        $model = $this->model($admin, 'knowledge-finalize-success-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge finalize success');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $model, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();
        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle($coordinator);

        (new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            (int) $run->execution_attempt,
            (string) $run->fresh()->finalizer_lease_token,
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertFalse($run->retryable_failure);
        $this->assertNull($run->finalizer_lease_token);
        $this->assertDatabaseHas('knowledge_facts', [
            'library_id' => $library->id,
            'stable_key' => 'company.founded_at',
            'created_by_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('knowledge_fact_evidences', [
            'knowledge_chunk_id' => $chunk->id,
            'source_hash' => $chunk->source_hash,
        ]);
    }

    public function test_repeated_expired_finalizer_dispatches_keep_identity_and_materialize_once(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        config()->set('geoflow.knowledge_fact_generation_max_recovery_attempts', 1);
        config()->set('geoflow.knowledge_fact_generation_finalizer_pending_seconds', 60);
        Bus::fake();
        $admin = $this->admin('knowledge-finalizer-redispatch');
        $model = $this->model($admin, 'knowledge-finalizer-redispatch-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge finalizer redispatch');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $model, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();
        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle($coordinator);

        $run->refresh();
        $originalAttempt = (int) $run->execution_attempt;
        $originalToken = (string) $run->finalizer_lease_token;

        for ($redispatch = 1; $redispatch <= 4; $redispatch++) {
            KnowledgeFactGenerationRun::query()->whereKey($run->id)->update([
                'finalizer_lease_expires_at' => now()->subSecond(),
                'updated_at' => now()->subMinutes(10),
            ]);

            $this->artisan('geoflow:recover-knowledge-fact-generations')
                ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
                ->assertSuccessful();

            $run->refresh();
            $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
            $this->assertSame($originalAttempt, $run->execution_attempt);
            $this->assertSame($originalToken, $run->finalizer_lease_token);
            $this->assertNotSame(
                'knowledge_fact_generation_recovery_attempts_exhausted',
                $run->error_code,
            );
        }

        Bus::assertDispatchedTimes(FinalizeKnowledgeFactGenerationJob::class, 4);
        $queuedFinalizer = new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            $originalAttempt,
            $originalToken,
        );
        $queuedFinalizer->handle($coordinator);
        $queuedFinalizer->handle($coordinator);

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertFalse($run->retryable_failure);
        $this->assertDatabaseCount('knowledge_facts', 1);
        $this->assertDatabaseCount('knowledge_fact_values', 1);
        $this->assertDatabaseCount('knowledge_fact_evidences', 1);
    }

    public function test_partial_finalization_releases_retry_dependencies_after_materializing_results(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-finalize-partial');
        $model = $this->model($admin, 'knowledge-finalize-partial-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge finalize partial');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $model, $admin, 'initial', 2);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();
        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle($coordinator);

        (new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            (int) $run->execution_attempt,
            (string) $run->fresh()->finalizer_lease_token,
        ))->handle($coordinator);

        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertFalse($run->retryable_failure);
        $this->assertNull($run->active_key);
        $this->assertDatabaseHas('knowledge_facts', [
            'library_id' => $library->id,
            'origin_generation_run_id' => $run->id,
        ]);
    }

    public function test_finalize_database_failure_escapes_only_as_a_stable_sanitized_exception(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-finalize-database-failure');
        $model = $this->model($admin, 'knowledge-finalize-database-failure-model');
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge finalize database failure');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $model, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        KnowledgeFactGeneratorAgent::fake([
            ['facts' => [$this->validFact($chunk)]],
        ])->preventStrayPrompts();
        (new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        ))->handle($coordinator);
        $sensitive = 'endpoint=https://secret-finalize.test/v1 api_key=secret-finalize-key prompt=secret-prompt excerpt='.$chunk->content;
        KnowledgeFact::creating(static fn (): never => throw new \RuntimeException($sensitive));
        $job = new FinalizeKnowledgeFactGenerationJob(
            (int) $run->id,
            (int) $run->execution_attempt,
            (string) $run->fresh()->finalizer_lease_token,
        );
        $escaped = null;

        try {
            $job->handle($coordinator);
            $this->fail('The finalizer should fail when fact persistence fails.');
        } catch (Throwable $exception) {
            $escaped = $exception;
        } finally {
            KnowledgeFact::flushEventListeners();
        }

        $this->assertNotNull($escaped);
        $this->assertSame('knowledge_fact_generation_finalize_failed', $escaped->getMessage());
        $this->assertNull($escaped->getPrevious());
        $this->assertStringNotContainsString('secret-finalize', (string) $escaped);
        $job->failed($escaped);
        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('knowledge_fact_generation_finalize_failed', $run->error_code);
        $this->assertSame('knowledge_fact_generation_finalize_failed', $run->error_message);
        $this->assertSame($job->leaseToken, $run->finalizer_lease_token);
        $this->assertDatabaseCount('knowledge_facts', 0);
        $this->assertDatabaseCount('knowledge_fact_evidences', 0);
    }

    public function test_queue_snapshot_public_json_and_failure_error_exclude_credentials_endpoint_prompt_and_evidence(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = $this->admin('knowledge-secret-safety');
        $model = $this->model($admin, 'knowledge-secret-safety-model', [
            'api_key' => app(ApiKeyCrypto::class)->encrypt('knowledge-super-secret-key'),
            'api_url' => 'https://knowledge-secret-endpoint.test/v1',
        ]);
        [$base, $chunk] = $this->knowledgeFixtures('Knowledge secret safety');
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = app(KnowledgeFactGenerationCoordinator::class)
            ->start($library, $model, $admin, 'initial', 1);
        $claim = data_get($run->fresh()->batch_claims_json, '1');
        $job = new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            $this->evidenceDescriptors($chunk),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
        );
        $serialized = serialize($job);
        $this->assertStringNotContainsString('knowledge-super-secret-key', $serialized);
        $this->assertStringNotContainsString('knowledge-secret-endpoint.test', $serialized);
        $this->assertStringNotContainsString((string) $chunk->content, $serialized);
        $this->assertStringNotContainsString('最多提取', $serialized);
        $context = app(KnowledgeFactGenerationAiExecutionGuard::class)->claimBatch(
            (int) $run->id,
            1,
            (string) data_get($claim, 'input_hash'),
            (int) $run->execution_attempt,
            (string) data_get($claim, 'dispatch_token'),
            fake()->uuid(),
        );
        $this->assertNotNull($context);
        app(KnowledgeFactGenerationAiExecutionGuard::class)->releaseBatchForRetry($context);

        $job->failed(new \RuntimeException(
            'endpoint=https://knowledge-secret-endpoint.test/v1 api_key=knowledge-super-secret-key prompt=hidden evidence='.$chunk->content,
        ));

        $run->refresh();
        $this->assertSame('knowledge_fact_generation_batch_failed', $run->error_message);
        $publicJson = $this->actingAs($admin, 'admin')->getJson(
            route('admin.knowledge-bases.fact-generation.show', [$base->id, $run->id]),
        )->assertOk()->getContent();
        $modelJson = $run->toJson();
        foreach ([
            'knowledge-super-secret-key',
            'knowledge-secret-endpoint.test',
            (string) $chunk->content,
            'model_access_admin_id',
            'batch_claims_json',
            'finalizer_lease_token',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $publicJson);
            $this->assertStringNotContainsString($secret, $modelJson);
        }
    }

    public function test_execution_identity_migration_rolls_back_and_reapplies_with_indexes(): void
    {
        $migration = require database_path(
            'migrations/2026_09_02_233936_add_admin_ai_execution_identity_to_knowledge_fact_generation_runs_table.php',
        );
        $columns = [
            'model_access_admin_id',
            'model_access_admin_role',
            'ai_config_access_version',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'resolved_model_source',
            'model_resolved_at',
            'resolver_policy_version',
            'retryable_failure',
            'execution_attempt',
            'batch_claims_json',
            'finalizer_lease_token',
            'finalizer_lease_expires_at',
        ];

        $migration->down();
        foreach ($columns as $column) {
            $this->assertFalse(Schema::hasColumn('knowledge_fact_generation_runs', $column));
        }

        $migration->up();
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn('knowledge_fact_generation_runs', $column));
        }
        $indexNames = collect(Schema::getIndexes('knowledge_fact_generation_runs'))->pluck('name');
        $this->assertContains('knowledge_fact_runs_admin_status_index', $indexNames);
        $this->assertContains('knowledge_fact_runs_status_retryable_index', $indexNames);
        $this->assertContains('knowledge_fact_runs_status_attempt_index', $indexNames);
    }

    private function admin(string $username, array $attributes = []): Admin
    {
        return Admin::query()->create(array_merge([
            'username' => $username,
            'password' => 'password',
            'email' => $username.'@example.com',
            'role' => 'admin',
            'status' => 'active',
        ], $attributes));
    }

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

    private function knowledgeFixtures(string $name): array
    {
        $sourceHash = hash('sha256', $name);
        $contentHash = hash('sha256', $name.' content');
        $base = KnowledgeBase::query()->create([
            'name' => $name,
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $sourceHash,
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $base->id,
            'chunk_index' => 0,
            'content' => '公司成立于 2020 年。',
            'content_hash' => $contentHash,
            'source_hash' => $sourceHash,
        ]);

        return [$base, $chunk];
    }

    private function evidenceDescriptors(KnowledgeChunk $chunk): array
    {
        return [[
            'evidence_key' => 'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
            'chunk_id' => (string) $chunk->id,
            'content_hash' => (string) $chunk->content_hash,
        ]];
    }

    private function validFact(KnowledgeChunk $chunk): array
    {
        return [
            'stable_key' => 'company.founded_at',
            'label' => '成立时间',
            'subject' => '公司',
            'predicate' => '成立于',
            'value_type' => 'date',
            'canonical_value' => '2020',
            'canonical_answer' => '公司成立于 2020 年。',
            'unit' => '年',
            'evidence_keys' => [
                'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
            ],
        ];
    }

    private function requestException(int $status, string $message): RequestException
    {
        return new RequestException(new ClientResponse(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR),
        )));
    }
}
