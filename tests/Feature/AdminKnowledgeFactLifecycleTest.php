<?php

namespace Tests\Feature;

use App\Data\Ai\KnowledgeFactGenerationRecoveryDispatch;
use App\Jobs\FinalizeKnowledgeFactGenerationJob;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\Admin\SystemAiModelReferenceInspector;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationRecoveryDispatcher;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminKnowledgeFactLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_deletion_dependencies_follow_active_and_retryable_knowledge_fact_execution_identity(): void
    {
        $creator = $this->admin('knowledge-fact-creator');
        $executionAdmin = $this->admin('knowledge-fact-executor');
        $otherAdmin = $this->admin('knowledge-fact-other');
        $library = $this->library();

        $this->generationRun($library, $creator, KnowledgeFactGenerationRun::STATUS_RUNNING, [
            'model_access_admin_id' => $executionAdmin->id,
        ]);
        $this->generationRun($library, $creator, KnowledgeFactGenerationRun::STATUS_FAILED, [
            'model_access_admin_id' => $executionAdmin->id,
            'retryable_failure' => true,
        ]);
        $this->generationRun($library, $creator, KnowledgeFactGenerationRun::STATUS_FAILED, [
            'model_access_admin_id' => $executionAdmin->id,
            'retryable_failure' => false,
        ]);
        $this->generationRun($library, $creator, KnowledgeFactGenerationRun::STATUS_COMPLETED, [
            'model_access_admin_id' => $executionAdmin->id,
        ]);
        $this->generationRun($library, $creator, KnowledgeFactGenerationRun::STATUS_RUNNING, [
            'model_access_admin_id' => $otherAdmin->id,
        ]);

        $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($executionAdmin);

        $this->assertSame(2, $dependencies->pendingTaskCounts['knowledge_fact_generation_runs']);
        $this->assertSame(2, $dependencies->executionKnowledgeFactGenerationRunCount);
        $this->assertTrue($dependencies->blocksDeletion());
        $this->assertSame(2, $dependencies->counts()['execution_knowledge_fact_generation_run_count']);
        $this->assertSame(2, $dependencies->counts()['pending_task_count']);
        $this->assertSame(
            0,
            app(AdminAiDependencyInspector::class)
                ->pendingTaskCounts($creator)['knowledge_fact_generation_runs'],
        );
    }

    public function test_legacy_knowledge_fact_runs_fall_back_to_the_creator_identity(): void
    {
        $admin = $this->admin('knowledge-fact-legacy-executor');
        $temporaryTable = 'knowledge_fact_generation_runs_with_execution_identity';
        Schema::rename('knowledge_fact_generation_runs', $temporaryTable);
        Schema::create('knowledge_fact_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('status');
            $table->boolean('retryable_failure')->default(true);
        });
        DB::table('knowledge_fact_generation_runs')->insert([
            [
                'created_by_admin_id' => $admin->id,
                'status' => KnowledgeFactGenerationRun::STATUS_RUNNING,
                'retryable_failure' => true,
            ],
            [
                'created_by_admin_id' => $admin->id,
                'status' => KnowledgeFactGenerationRun::STATUS_FAILED,
                'retryable_failure' => true,
            ],
            [
                'created_by_admin_id' => $admin->id,
                'status' => KnowledgeFactGenerationRun::STATUS_COMPLETED,
                'retryable_failure' => false,
            ],
        ]);

        try {
            $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($admin);
            $this->assertSame(2, $dependencies->pendingTaskCounts['knowledge_fact_generation_runs']);
            $this->assertSame(2, $dependencies->executionKnowledgeFactGenerationRunCount);
        } finally {
            Schema::dropIfExists('knowledge_fact_generation_runs');
            Schema::rename($temporaryTable, 'knowledge_fact_generation_runs');
        }
    }

    public function test_terminal_nonretryable_knowledge_fact_history_allows_admin_hard_deletion(): void
    {
        $superAdmin = $this->admin('knowledge-fact-delete-super', ['role' => 'super_admin']);
        $executionAdmin = $this->admin('knowledge-fact-delete-executor');
        $model = $this->model($superAdmin, 'knowledge-fact-delete-model');
        $run = $this->generationRun(
            $this->library(),
            $executionAdmin,
            KnowledgeFactGenerationRun::STATUS_COMPLETED,
            [
                'model_access_admin_id' => $executionAdmin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'retryable_failure' => false,
            ],
        );

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $executionAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertModelMissing($executionAdmin);
        $this->assertNull($run->refresh()->model_access_admin_id);
        $this->assertNull($run->created_by_admin_id);
    }

    public function test_knowledge_fact_execution_admin_foreign_key_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_09_03_000000_set_null_on_knowledge_fact_execution_admin_delete.php',
        );

        $this->assertSame('set null', $this->knowledgeFactExecutionAdminForeignKeyDeleteRule());

        try {
            $migration->down();

            $this->assertSame('restrict', $this->knowledgeFactExecutionAdminForeignKeyDeleteRule());
        } finally {
            $migration->up();
        }

        $this->assertSame('set null', $this->knowledgeFactExecutionAdminForeignKeyDeleteRule());
    }

    public function test_active_knowledge_fact_model_snapshots_block_model_update_and_delete(): void
    {
        $admin = $this->admin('knowledge-fact-model-owner');
        $library = $this->library();
        $references = [
            'ai_model_id',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'batch_claims_json',
        ];

        foreach ($references as $reference) {
            $model = $this->model($admin, 'knowledge-fact-'.$reference);
            $attributes = $reference === 'batch_claims_json'
                ? ['batch_claims_json' => ['1' => [
                    'status' => 'running',
                    'resolved_ai_model_id' => $model->id,
                ]]]
                : [$reference => $model->id];
            $run = $this->generationRun(
                $library,
                $admin,
                KnowledgeFactGenerationRun::STATUS_RUNNING,
                $attributes,
            );

            $updated = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                $this->modelAttributes($model),
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            $deleted = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);

            $this->assertFalse($updated->succeeded(), $reference.' must block update.');
            $this->assertSame('task', $updated->error);
            $this->assertFalse($deleted->succeeded(), $reference.' must block deletion.');
            $this->assertSame('task', $deleted->error);
            $this->assertSame(1, $deleted->dependencyCount);

            $run->forceFill([
                'status' => KnowledgeFactGenerationRun::STATUS_COMPLETED,
                'retryable_failure' => false,
            ])->save();
            $this->assertTrue(
                app(AdminAiModelMutationService::class)
                    ->delete($admin, (int) $model->id)
                    ->succeeded(),
                $reference.' terminal history must not block deletion.',
            );
        }

        $retryableModel = $this->model($admin, 'knowledge-fact-retryable-snapshots');
        $retryableRuns = collect([
            $this->generationRun(
                $library,
                $admin,
                KnowledgeFactGenerationRun::STATUS_FAILED,
                [
                    'retryable_failure' => true,
                    'ai_model_id' => $retryableModel->id,
                    'requested_ai_model_id' => $retryableModel->id,
                    'resolved_ai_model_id' => $retryableModel->id,
                ],
            ),
            $this->generationRun(
                $library,
                $admin,
                'partial',
                [
                    'retryable_failure' => true,
                    'batch_claims_json' => ['1' => [
                        'status' => 'failed',
                        'resolved_ai_model_id' => $retryableModel->id,
                    ]],
                ],
            ),
        ]);

        $retryableUpdate = app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $retryableModel->id,
            $this->modelAttributes($retryableModel),
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        );
        $retryableDelete = app(AdminAiModelMutationService::class)->delete(
            $admin,
            (int) $retryableModel->id,
        );
        $this->assertSame('task', $retryableUpdate->error);
        $this->assertSame('task', $retryableDelete->error);
        $this->assertSame(2, $retryableDelete->dependencyCount);

        $retryableRuns->each(fn (KnowledgeFactGenerationRun $run) => $run->forceFill([
            'retryable_failure' => false,
        ])->save());
        $this->assertTrue(
            app(AdminAiModelMutationService::class)
                ->delete($admin, (int) $retryableModel->id)
                ->succeeded(),
        );
    }

    public function test_model_reference_preflight_classifies_active_and_historical_knowledge_fact_snapshots(): void
    {
        $legacyOwner = $this->admin('knowledge-fact-legacy-owner', ['role' => 'super_admin']);
        $creator = $this->admin('knowledge-fact-reference-creator');
        $library = $this->library();

        foreach (['ai_model_id', 'requested_ai_model_id', 'resolved_ai_model_id'] as $reference) {
            $model = $this->model($legacyOwner, 'reference-'.$reference);
            $model->forceFill(['owner_admin_id' => null])->save();
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => 'knowledge_chunking_model_id'],
                ['setting_value' => (string) $model->id],
            );
            $run = $this->generationRun(
                $library,
                $creator,
                KnowledgeFactGenerationRun::STATUS_RUNNING,
                [$reference => $model->id],
            );

            $active = app(SystemAiModelReferenceInspector::class)->inspect((int) $legacyOwner->id);
            $this->assertContains($model->id, $active['conflict_model_ids']);

            $run->forceFill([
                'status' => KnowledgeFactGenerationRun::STATUS_COMPLETED,
                'retryable_failure' => false,
            ])->save();
            $historical = app(SystemAiModelReferenceInspector::class)->inspect((int) $legacyOwner->id);
            $this->assertNotContains($model->id, $historical['conflict_model_ids']);
        }

        $unknownStateModel = $this->model($legacyOwner, 'reference-unknown-state');
        $unknownStateModel->forceFill(['owner_admin_id' => null])->save();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'knowledge_chunking_model_id'],
            ['setting_value' => (string) $unknownStateModel->id],
        );
        $this->generationRun(
            $library,
            $creator,
            'future_executable_state',
            ['requested_ai_model_id' => $unknownStateModel->id],
        );
        $unknownState = app(SystemAiModelReferenceInspector::class)->inspect(
            (int) $legacyOwner->id,
        );
        $this->assertContains($unknownStateModel->id, $unknownState['conflict_model_ids']);

        $batchModel = $this->model($legacyOwner, 'reference-batch-claim');
        $batchModel->forceFill(['owner_admin_id' => null])->save();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'knowledge_chunking_model_id'],
            ['setting_value' => (string) $batchModel->id],
        );
        $batchRun = $this->generationRun(
            $library,
            $creator,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            ['batch_claims_json' => ['1' => [
                'status' => 'running',
                'resolved_ai_model_id' => $batchModel->id,
            ]]],
        );

        $activeBatch = app(SystemAiModelReferenceInspector::class)->inspect((int) $legacyOwner->id);
        $this->assertContains($batchModel->id, $activeBatch['conflict_model_ids']);
        $this->assertSame(0, $activeBatch['historical_structured_reference_count']);

        $batchRun->forceFill([
            'status' => KnowledgeFactGenerationRun::STATUS_COMPLETED,
            'retryable_failure' => false,
        ])->save();
        $historicalBatch = app(SystemAiModelReferenceInspector::class)->inspect((int) $legacyOwner->id);
        $this->assertNotContains($batchModel->id, $historicalBatch['conflict_model_ids']);
        $this->assertSame(1, $historicalBatch['historical_structured_reference_count']);
    }

    public function test_batch_middleware_rate_limit_is_a_stable_admin_guard_and_rejects_forged_claims(): void
    {
        $admin = $this->admin('knowledge-fact-rate-admin');
        $model = $this->model($admin, 'knowledge-fact-rate-model');
        $run = $this->generationRun(
            $this->library(),
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'execution_attempt' => 4,
                'batch_claims_json' => ['1' => [
                    'input_hash' => 'registered-input-hash',
                    'execution_attempt' => 4,
                    'dispatch_token' => 'registered-claim-token',
                    'status' => 'queued',
                    'resolved_ai_model_id' => $model->id,
                ]],
            ],
        );
        $limiter = RateLimiter::limiter('knowledge-fact-generation');
        $registeredJob = new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            'registered-input-hash',
            [],
            4,
            'registered-claim-token',
        );

        $this->assertSame(
            'knowledge-fact-generation:admin:'.$admin->id,
            $limiter($registeredJob)->key,
        );

        $claims = (array) $run->batch_claims_json;
        unset($claims['1']['resolved_ai_model_id']);
        $run->forceFill(['batch_claims_json' => $claims])->save();
        $this->assertSame(
            'knowledge-fact-generation:admin:'.$admin->id,
            $limiter($registeredJob)->key,
        );

        $forgedJob = new GenerateKnowledgeFactBatchJob(
            (int) $run->id,
            1,
            'registered-input-hash',
            [],
            4,
            'forged-claim-token',
        );
        $this->assertSame(
            'knowledge-fact-generation:invalid',
            $limiter($forgedJob)->key,
        );
    }

    public function test_recovery_rotates_an_expired_batch_attempt_and_dispatches_once(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-recovery-admin');
        $model = $this->model($admin, 'knowledge-fact-recovery-model');
        $library = $this->library();
        $chunk = $library->knowledgeBase->chunks()->firstOrFail();
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'job_batch_id' => (string) Str::uuid(),
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'running',
                    'dispatch_token' => 'old-dispatch-token',
                    'execution_attempt' => 1,
                    'attempt_count' => 1,
                    'lease_token' => 'old-batch-lease',
                    'lease_expires_at' => now()->subMinute()->toIso8601String(),
                    'resolved_ai_model_id' => $model->id,
                ]],
                'finalizer_lease_token' => (string) Str::uuid7(),
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations', ['--limit' => 10])
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $newClaim = (array) data_get($run->batch_claims_json, '1');
        $this->assertSame(2, $run->execution_attempt);
        $this->assertSame(1, $newClaim['attempt_count']);
        $this->assertSame('queued', $newClaim['status']);
        $this->assertNotSame('old-dispatch-token', $newClaim['dispatch_token']);
        $this->assertNull($newClaim['lease_token']);
        $this->assertNotNull($run->job_batch_id);
        Bus::assertBatched(function (PendingBatch $batch) use ($newClaim): bool {
            $job = $batch->jobs->first();

            return $job instanceof GenerateKnowledgeFactBatchJob
                && $job->executionAttempt === 2
                && $job->claimToken === $newClaim['dispatch_token']
                && $job->queue === 'knowledge';
        });

        $this->artisan('geoflow:recover-knowledge-fact-generations', ['--limit' => 10])
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();
        Bus::assertBatchCount(1);
    }

    public function test_recovery_requeues_a_retryable_failed_batch_and_clears_stale_model_attribution(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-failed-batch-admin');
        $model = $this->model($admin, 'knowledge-fact-failed-batch-model');
        $library = $this->library();
        $chunk = $library->knowledgeBase->chunks()->firstOrFail();
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_FAILED,
            [
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'retryable_failure' => true,
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'failed',
                    'dispatch_token' => null,
                    'execution_attempt' => 1,
                    'attempt_count' => 1,
                    'resolved_ai_model_id' => $model->id,
                    'resolved_model_source' => 'personal',
                    'model_resolved_at' => now()->toIso8601String(),
                ]],
                'result_json' => [
                    'candidates' => [],
                    'conflicts' => [],
                    'batches' => ['1' => [
                        'input_hash' => $inputHash,
                        'status' => 'failed',
                    ]],
                ],
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $claim = (array) data_get($run->batch_claims_json, '1');
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->execution_attempt);
        $this->assertSame('knowledge-fact-library:'.$library->id, $run->active_key);
        $this->assertSame('queued', $claim['status']);
        $this->assertSame(1, $claim['attempt_count']);
        $this->assertArrayNotHasKey('resolved_ai_model_id', $claim);
        $this->assertArrayNotHasKey('resolved_model_source', $claim);
        $this->assertArrayNotHasKey('model_resolved_at', $claim);
        $this->assertNotNull($run->job_batch_id);
        Bus::assertBatchCount(1);
    }

    public function test_recovery_redispatches_a_lost_finalizer_for_a_retryable_failed_run(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        config()->set('geoflow.knowledge_fact_generation_max_recovery_attempts', 1);
        $admin = $this->admin('knowledge-fact-failed-finalizer-admin');
        $model = $this->model($admin, 'knowledge-fact-failed-finalizer-model');
        $library = $this->library();
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_FAILED,
            [
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'retryable_failure' => true,
                'batch_claims_json' => ['1' => [
                    'input_hash' => 'completed-input',
                    'status' => 'completed',
                    'dispatch_token' => null,
                    'execution_attempt' => 1,
                    'attempt_count' => 1,
                    'resolved_ai_model_id' => $model->id,
                ]],
                'error_code' => 'knowledge_fact_generation_finalize_failed',
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(1, $run->execution_attempt);
        $this->assertSame('knowledge-fact-library:'.$library->id, $run->active_key);
        $firstFinalizerToken = $run->finalizer_lease_token;
        $this->assertTrue($run->finalizer_lease_expires_at?->isFuture() === true);
        Bus::assertBatchCount(0);
        Bus::assertDispatched(FinalizeKnowledgeFactGenerationJob::class, function (FinalizeKnowledgeFactGenerationJob $job) use ($run): bool {
            return $job->runId === (int) $run->id
                && $job->executionAttempt === 1
                && $job->leaseToken === $run->finalizer_lease_token;
        });

        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();
        $run->refresh();
        $this->assertSame(1, $run->execution_attempt);
        $this->assertSame($firstFinalizerToken, $run->finalizer_lease_token);
        Bus::assertDispatchedTimes(FinalizeKnowledgeFactGenerationJob::class, 1);

        DB::table('knowledge_fact_generation_runs')->where('id', $run->id)->update([
            'finalizer_lease_expires_at' => now()->subSecond(),
            'updated_at' => now()->subMinutes(10),
        ]);
        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();
        $run->refresh();
        $this->assertSame(1, $run->execution_attempt);
        $this->assertSame($firstFinalizerToken, $run->finalizer_lease_token);
        Bus::assertDispatchedTimes(FinalizeKnowledgeFactGenerationJob::class, 2);
    }

    public function test_recovery_closes_retryable_partial_history_without_dispatching_duplicate_work(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-partial-history-admin');
        $model = $this->model($admin, 'knowledge-fact-partial-history-model');
        $library = $this->library();
        $run = $this->generationRun(
            $library,
            $admin,
            'partial',
            [
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'retryable_failure' => true,
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertFalse($run->retryable_failure);
        $this->assertNull($run->active_key);
        Bus::assertNothingDispatched();
    }

    public function test_recovery_reclaims_stale_running_claims_with_missing_or_invalid_lease_metadata(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-malformed-lease-admin');
        $model = $this->model($admin, 'knowledge-fact-malformed-lease-model');
        $runIds = [];

        foreach ([null, 'invalid-lease-date'] as $leaseExpiresAt) {
            $library = $this->library();
            $chunk = $library->knowledgeBase->chunks()->firstOrFail();
            $evidence = $this->evidenceDescriptors($chunk);
            $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $claim = [
                'input_hash' => $inputHash,
                'status' => 'running',
                'dispatch_token' => (string) Str::uuid7(),
                'execution_attempt' => 1,
                'attempt_count' => 1,
                'lease_token' => (string) Str::uuid7(),
            ];
            if ($leaseExpiresAt !== null) {
                $claim['lease_expires_at'] = $leaseExpiresAt;
            }
            $run = $this->generationRun(
                $library,
                $admin,
                KnowledgeFactGenerationRun::STATUS_RUNNING,
                [
                    'active_key' => 'knowledge-fact-library:'.$library->id,
                    'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                    'base_working_version' => (int) $library->working_version,
                    'ai_model_id' => $model->id,
                    'model_access_admin_id' => $admin->id,
                    'model_access_admin_role' => 'admin',
                    'ai_config_access_version' => 1,
                    'requested_ai_model_id' => $model->id,
                    'resolver_policy_version' => 1,
                    'execution_attempt' => 1,
                    'batch_claims_json' => ['1' => $claim],
                    'finalizer_lease_token' => (string) Str::uuid7(),
                ],
            );
            $runIds[] = (int) $run->id;
        }
        DB::table('knowledge_fact_generation_runs')
            ->whereIn('id', $runIds)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 2; dispatch failures: 0')
            ->assertSuccessful();

        foreach ($runIds as $runId) {
            $run = KnowledgeFactGenerationRun::query()->findOrFail($runId);
            $this->assertSame(2, $run->execution_attempt);
            $this->assertSame('queued', data_get($run->batch_claims_json, '1.status'));
            $this->assertNull(data_get($run->batch_claims_json, '1.lease_token'));
            $this->assertNotNull($run->job_batch_id);
        }
        Bus::assertBatchCount(2);
    }

    public function test_recovery_redispatches_the_same_finalizer_when_all_claims_are_terminal(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-finalizer-admin');
        $model = $this->model($admin, 'knowledge-fact-finalizer-model');
        $library = $this->library();
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'batch_claims_json' => ['1' => [
                    'input_hash' => 'completed-input',
                    'status' => 'completed',
                    'dispatch_token' => 'old-completed-token',
                    'execution_attempt' => 1,
                    'attempt_count' => 1,
                    'resolved_ai_model_id' => $model->id,
                ]],
                'finalizer_lease_token' => (string) Str::uuid7(),
            ],
        );
        $finalizerToken = (string) $run->finalizer_lease_token;
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(1, $run->execution_attempt);
        $this->assertSame('old-completed-token', data_get($run->batch_claims_json, '1.dispatch_token'));
        $this->assertNull(data_get($run->batch_claims_json, '1.lease_token'));
        $this->assertSame($finalizerToken, $run->finalizer_lease_token);
        Bus::assertBatchCount(0);
        Bus::assertDispatched(FinalizeKnowledgeFactGenerationJob::class, function (FinalizeKnowledgeFactGenerationJob $job) use ($run): bool {
            return $job->runId === (int) $run->id
                && $job->executionAttempt === 1
                && $job->leaseToken === $run->finalizer_lease_token
                && $job->queue === 'knowledge'
                && $job->afterCommit === true;
        });
    }

    public function test_recovery_fails_closed_for_missing_identity_and_attempt_exhaustion(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        config()->set('geoflow.knowledge_fact_generation_max_recovery_attempts', 2);
        $admin = $this->admin('knowledge-fact-fail-closed-admin');
        $model = $this->model($admin, 'knowledge-fact-fail-closed-model');

        $missingIdentityLibrary = $this->library();
        $missingIdentity = $this->generationRun(
            $missingIdentityLibrary,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$missingIdentityLibrary->id,
                'source_hash' => $missingIdentityLibrary->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $missingIdentityLibrary->working_version,
                'batch_claims_json' => [],
            ],
        );
        $exhaustedLibrary = $this->library();
        $exhausted = $this->generationRun(
            $exhaustedLibrary,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$exhaustedLibrary->id,
                'source_hash' => $exhaustedLibrary->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $exhaustedLibrary->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 3,
                'batch_claims_json' => [],
            ],
        );
        $permanentLibrary = $this->library();
        $permanent = $this->generationRun(
            $permanentLibrary,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$permanentLibrary->id,
                'source_hash' => $permanentLibrary->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $permanentLibrary->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'retryable_failure' => false,
                'batch_claims_json' => [],
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->whereIn('id', [$missingIdentity->id, $exhausted->id, $permanent->id])
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();

        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $missingIdentity->refresh()->status);
        $this->assertSame('ai_config_access_revoked', $missingIdentity->error_code);
        $this->assertFalse($missingIdentity->retryable_failure);
        $this->assertNull($missingIdentity->active_key);
        $this->assertSame('failed', $missingIdentityLibrary->refresh()->workflow_status);
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $exhausted->refresh()->status);
        $this->assertSame('knowledge_fact_generation_recovery_attempts_exhausted', $exhausted->error_code);
        $this->assertFalse($exhausted->retryable_failure);
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $permanent->refresh()->status);
        $this->assertSame('knowledge_fact_generation_permanent_failure', $permanent->error_code);
        $this->assertFalse($permanent->retryable_failure);
        $this->assertNull($permanent->active_key);
        Bus::assertNothingDispatched();
    }

    public function test_recovery_dispatch_failure_is_sanitized_and_remains_retryable(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-dispatch-failure-admin');
        $model = $this->model($admin, 'knowledge-fact-dispatch-failure-model');
        $library = $this->library();
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'batch_claims_json' => [],
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $this->app->instance(
            KnowledgeFactGenerationRecoveryDispatcher::class,
            new class extends KnowledgeFactGenerationRecoveryDispatcher
            {
                public function dispatch(KnowledgeFactGenerationRecoveryDispatch $dispatch): ?string
                {
                    throw new RuntimeException(
                        'provider https://private.example/v1 Authorization: Bearer secret-key',
                    );
                }
            },
        );

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 1')
            ->assertFailed();

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_RUNNING, $run->status);
        $this->assertSame('knowledge_fact_generation_recovery_dispatch_failed', $run->error_code);
        $this->assertSame('knowledge_fact_generation_recovery_dispatch_failed', $run->error_message);
        $this->assertTrue($run->retryable_failure);
        $this->assertStringNotContainsString('private.example', json_encode($run->batch_claims_json, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-key', (string) $run->error_message);
    }

    public function test_scheduler_registers_bounded_knowledge_fact_recovery(): void
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($consoleRoutes);
        $this->assertStringContainsString(
            "Schedule::command('geoflow:recover-knowledge-fact-generations')",
            $consoleRoutes,
        );
        $this->assertStringContainsString('->everyFiveMinutes()', $consoleRoutes);
        $this->assertStringContainsString('->onOneServer()', $consoleRoutes);
        $this->assertStringContainsString('->withoutOverlapping(10)', $consoleRoutes);
    }

    public function test_initial_batch_dispatch_is_registered_before_after_commit_queueing(): void
    {
        Bus::fake();
        config()->set('ai-workspace.require_verified_model', false);
        $admin = $this->admin('knowledge-fact-initial-dispatch-admin');
        $model = $this->model($admin, 'knowledge-fact-initial-dispatch-model');
        $library = $this->library();

        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            $library,
            $model,
            $admin,
            'initial',
            1,
        );

        $this->assertNotNull($run->job_batch_id);
        $this->assertNotEmpty($run->batch_claims_json);
        Bus::assertBatched(function (PendingBatch $batch): bool {
            $job = $batch->jobs->first();

            return $job instanceof GenerateKnowledgeFactBatchJob
                && $job->queue === 'knowledge'
                && $job->afterCommit === true;
        });
    }

    public function test_recovery_does_not_duplicate_a_live_pending_batch_during_queue_backlog(): void
    {
        Bus::fake();
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-backlog-admin');
        $model = $this->model($admin, 'knowledge-fact-backlog-model');
        $run = app(KnowledgeFactGenerationCoordinator::class)->start(
            $this->library(),
            $model,
            $admin,
            'initial',
            1,
        );
        $this->assertNotNull($run->job_batch_id);
        $this->assertNotNull(Bus::findBatch((string) $run->job_batch_id));
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(1, $run->execution_attempt);
        $this->assertNotNull($run->job_batch_id);
        Bus::assertBatchCount(1);
    }

    public function test_recovery_replaces_a_stale_database_batch_with_pending_jobs(): void
    {
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        config()->set('geoflow.knowledge_fact_generation_pending_batch_max_age_seconds', 60);
        $admin = $this->admin('knowledge-fact-stale-batch-admin');
        $model = $this->model($admin, 'knowledge-fact-stale-batch-model');
        $library = $this->library();
        $chunk = $library->knowledgeBase->chunks()->firstOrFail();
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $oldBatchId = (string) Str::uuid();
        DB::table('job_batches')->insert([
            'id' => $oldBatchId,
            'name' => 'knowledge-facts:stale-database-batch',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'cancelled_at' => null,
            'created_at' => now()->subHour()->timestamp,
            'finished_at' => null,
        ]);
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'job_batch_id' => $oldBatchId,
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'queued',
                    'dispatch_token' => 'stale-batch-token',
                    'execution_attempt' => 1,
                    'attempt_count' => 0,
                ]],
                'finalizer_lease_token' => (string) Str::uuid7(),
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $dispatcher = new class extends KnowledgeFactGenerationRecoveryDispatcher
        {
            public int $dispatchCount = 0;

            public function dispatch(KnowledgeFactGenerationRecoveryDispatch $dispatch): ?string
            {
                $this->dispatchCount++;

                return 'replacement-batch-id';
            }
        };
        $this->app->instance(KnowledgeFactGenerationRecoveryDispatcher::class, $dispatcher);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(1, $dispatcher->dispatchCount);
        $this->assertSame(2, $run->execution_attempt);
        $this->assertSame('replacement-batch-id', $run->job_batch_id);
        $this->assertNotSame('stale-batch-token', data_get($run->batch_claims_json, '1.dispatch_token'));
        $this->assertNotNull(DB::table('job_batches')->where('id', $oldBatchId)->value('cancelled_at'));
    }

    public function test_recovery_closes_a_missing_job_batch_dispatch_window(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-dispatch-gap-admin');
        $model = $this->model($admin, 'knowledge-fact-dispatch-gap-model');
        $library = $this->library();
        $chunk = $library->knowledgeBase->chunks()->firstOrFail();
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'job_batch_id' => null,
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'queued',
                    'dispatch_token' => 'lost-dispatch-token',
                    'execution_attempt' => 1,
                    'attempt_count' => 0,
                ]],
                'finalizer_lease_token' => (string) Str::uuid7(),
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 1; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(2, $run->execution_attempt);
        $this->assertNotNull($run->job_batch_id);
        $this->assertNotSame('lost-dispatch-token', data_get($run->batch_claims_json, '1.dispatch_token'));
        Bus::assertBatchCount(1);
    }

    public function test_recovery_does_not_reset_an_exhausted_batch_attempt_budget(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        config()->set('geoflow.knowledge_fact_generation_max_batch_attempts', 3);
        $admin = $this->admin('knowledge-fact-batch-budget-admin');
        $model = $this->model($admin, 'knowledge-fact-batch-budget-model');
        $library = $this->library();
        $chunk = $library->knowledgeBase->chunks()->firstOrFail();
        $evidence = $this->evidenceDescriptors($chunk);
        $inputHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'batch_claims_json' => ['1' => [
                    'input_hash' => $inputHash,
                    'status' => 'queued',
                    'dispatch_token' => 'exhausted-dispatch-token',
                    'execution_attempt' => 1,
                    'attempt_count' => 3,
                ]],
                'finalizer_lease_token' => (string) Str::uuid7(),
            ],
        );
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')
            ->expectsOutput('Recovered knowledge fact generation runs: 0; dispatch failures: 0')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('knowledge_fact_generation_batch_attempts_exhausted', $run->error_code);
        $this->assertFalse($run->retryable_failure);
        $this->assertNull($run->active_key);
        Bus::assertNothingDispatched();
    }

    public function test_recovery_stops_after_the_execution_admin_is_deactivated(): void
    {
        Bus::fake();
        config()->set('geoflow.knowledge_fact_generation_recovery_stale_seconds', 1);
        $admin = $this->admin('knowledge-fact-inactive-admin');
        $model = $this->model($admin, 'knowledge-fact-inactive-model');
        $library = $this->library();
        $run = $this->generationRun(
            $library,
            $admin,
            KnowledgeFactGenerationRun::STATUS_RUNNING,
            [
                'active_key' => 'knowledge-fact-library:'.$library->id,
                'source_hash' => $library->knowledgeBase->servingChunkSourceHash(),
                'base_working_version' => (int) $library->working_version,
                'ai_model_id' => $model->id,
                'model_access_admin_id' => $admin->id,
                'model_access_admin_role' => 'admin',
                'ai_config_access_version' => 1,
                'requested_ai_model_id' => $model->id,
                'resolver_policy_version' => 1,
                'execution_attempt' => 1,
                'batch_claims_json' => [],
            ],
        );
        $admin->forceFill([
            'status' => 'inactive',
            'ai_config_access_version' => 2,
        ])->save();
        DB::table('knowledge_fact_generation_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('geoflow:recover-knowledge-fact-generations')->assertSuccessful();

        $run->refresh();
        $this->assertSame(KnowledgeFactGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_execution_admin_inactive', $run->error_code);
        $this->assertFalse($run->retryable_failure);
        Bus::assertNothingDispatched();
    }

    /** @param array<string, mixed> $attributes */
    private function admin(string $username, array $attributes = []): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'safe-password-123',
            'email' => $username.'@example.test',
            'display_name' => Str::headline($username),
            'role' => 'admin',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function library(): KnowledgeFactLibrary
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Knowledge Fact Lifecycle',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => hash('sha256', 'Knowledge Fact Lifecycle'),
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '公司成立于 2020 年。',
            'content_hash' => hash('sha256', 'Knowledge Fact Lifecycle content'),
            'source_hash' => $knowledgeBase->chunk_source_hash,
        ]);

        return KnowledgeFactLibrary::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);
    }

    private function knowledgeFactExecutionAdminForeignKeyDeleteRule(): string
    {
        $foreignKey = collect(Schema::getForeignKeys('knowledge_fact_generation_runs'))
            ->firstOrFail(static fn (array $key): bool => $key['columns'] === ['model_access_admin_id']
                && $key['foreign_table'] === 'admins');

        return strtolower((string) $foreignKey['on_delete']);
    }

    /** @return list<array<string, string>> */
    private function evidenceDescriptors(KnowledgeChunk $chunk): array
    {
        return [[
            'evidence_key' => 'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
            'chunk_id' => (string) $chunk->id,
            'content_hash' => (string) $chunk->content_hash,
        ]];
    }

    private function model(Admin $owner, string $modelId): AiModel
    {
        $model = new AiModel([
            'name' => Str::headline($modelId),
            'version' => 'test',
            'api_key' => 'sensitive-key',
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    /** @return array<string, mixed> */
    private function modelAttributes(AiModel $model): array
    {
        return [
            'name' => $model->name,
            'version' => $model->version,
            'model_id' => $model->model_id,
            'model_type' => $model->model_type,
            'api_url' => $model->api_url,
            'status' => $model->status,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function generationRun(
        KnowledgeFactLibrary $library,
        Admin $creator,
        string $status,
        array $attributes = [],
    ): KnowledgeFactGenerationRun {
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id,
            'mode' => 'supplement',
            'target_count' => 10,
            'source_hash' => hash('sha256', (string) Str::uuid()),
            'base_working_version' => 1,
            'status' => $status,
            'created_by_admin_id' => $creator->id,
            'request_key' => (string) Str::uuid(),
        ]);
        $run->forceFill($attributes)->save();

        return $run->refresh();
    }
}
