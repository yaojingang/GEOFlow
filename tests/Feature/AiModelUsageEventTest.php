<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageAttemptStart;
use App\Models\AiModelUsageEvent;
use App\Models\Task;
use App\Services\Admin\AiModelUsageAccessSnapshot;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\Admin\AiModelUsageLedgerSchema;
use App\Services\Admin\AiModelUsageRecorder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AiModelUsageEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_attempt_start_is_durable_and_stale_missing_outcomes_are_reconciled(): void
    {
        $owner = $this->admin('durable-start-owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $factory = app(AiModelUsageAttemptFactory::class);
        $requestId = $factory->requestId();
        $attempt = $factory->beginForSystem(
            model: $model,
            identity: SystemAiIdentity::knowledgeIndex(),
            requestId: $requestId,
            requestPayload: 'durable provider payload',
            callKey: 'durable.p1',
            operation: 'knowledge.semantic_chunking',
            businessSource: 'knowledge_index',
        );

        $start = AiModelUsageAttemptStart::query()->sole();
        $this->assertSame($requestId, $start->request_id);
        $this->assertSame(hash('sha256', 'durable provider payload'), $start->request_payload_digest);
        $this->assertDatabaseCount('ai_model_usage_events', 0);

        $attempt->succeeded(['prompt_tokens' => 2, 'completion_tokens' => 1]);
        $this->assertDatabaseHas('ai_model_usage_events', [
            'request_id' => $requestId,
            'call_key' => 'durable.p1',
            'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
        ]);

        $this->travelTo(now()->subMinutes(10));
        $staleRequestId = $factory->requestId();
        $factory->beginForSystem(
            model: $model,
            identity: SystemAiIdentity::knowledgeIndex(),
            requestId: $staleRequestId,
            requestPayload: 'stale provider payload',
            callKey: 'stale.p1',
            operation: 'knowledge.semantic_chunking',
            businessSource: 'knowledge_index',
        );
        $this->travelBack();

        $this->artisan('geoflow:reconcile-ai-usage-attempts', ['--older-than' => 300])
            ->expectsOutput('Stale AI usage attempts reconciled: 1')
            ->assertSuccessful();
        $this->assertDatabaseHas('ai_model_usage_events', [
            'request_id' => $staleRequestId,
            'call_key' => 'stale.p1',
            'status' => AiModelUsageEvent::STATUS_FAILED,
            'error_code' => 'ai_usage_outcome_missing',
        ]);
    }

    public function test_system_attempt_requires_knowledge_index_identity_and_records_system_attribution(): void
    {
        $owner = $this->admin('system-owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $factory = app(AiModelUsageAttemptFactory::class);
        $requestId = $factory->requestId();

        $attempt = $factory->beginForSystem(
            model: $model,
            identity: SystemAiIdentity::knowledgeIndex(),
            requestId: $requestId,
            requestPayload: 'sensitive knowledge input',
            callKey: 'semantic.execution-1.attempt-1.provider-1',
            operation: 'knowledge.semantic_chunking',
            businessSource: 'knowledge_index',
            sourceType: 'knowledge_base',
            sourceId: 17,
        );
        $attempt->succeeded(['prompt_tokens' => 4, 'completion_tokens' => 2]);

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame($owner->id, $event->config_owner_admin_id);
        $this->assertNull($event->execution_admin_id);
        $this->assertSame(0, $event->ai_config_access_version);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM, $event->execution_scope);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $event->model_source);
        $this->assertSame(hash('sha256', 'sensitive knowledge input'), $event->request_payload_digest);
    }

    public function test_system_attempt_rejects_a_non_knowledge_identity_before_creating_an_event(): void
    {
        $owner = $this->admin('wrong-purpose-owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $factory = app(AiModelUsageAttemptFactory::class);

        try {
            $factory->beginForSystem(
                model: $model,
                identity: SystemAiIdentity::forVisibilityCollection(),
                requestId: $factory->requestId(),
                requestPayload: 'payload',
                callKey: 'semantic.invalid-purpose',
                operation: 'knowledge.semantic_chunking',
                businessSource: 'knowledge_index',
            );
            $this->fail('Expected the unrelated system identity to be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseCount('ai_model_usage_events', 0);
        }
    }

    public function test_visibility_collection_attempt_requires_the_collection_identity(): void
    {
        $owner = $this->admin('visibility-system-owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $factory = app(AiModelUsageAttemptFactory::class);

        try {
            $factory->beginForVisibilityCollection(
                model: $model,
                identity: SystemAiIdentity::forVisibilityAnalytics(),
                requestId: $factory->requestId(),
                requestPayload: 'payload',
                callKey: 'ark.p1',
                operation: 'ai_visibility.collect.ark',
                businessSource: 'ai_visibility_collection',
            );
            $this->fail('Visibility analytics cannot record collection attempts.');
        } catch (LogicException) {
            $this->assertDatabaseCount('ai_model_usage_events', 0);
        }

        $attempt = $factory->beginForVisibilityCollection(
            model: $model,
            identity: SystemAiIdentity::visibilityCollection(),
            requestId: $factory->requestId(),
            requestPayload: 'payload',
            callKey: 'deepseek.p1',
            operation: 'ai_visibility.collect.deepseek',
            businessSource: 'ai_visibility_collection',
        );
        $attempt->succeeded();

        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, AiModelUsageEvent::query()->sole()->model_source);
    }

    public function test_generic_admin_factory_cannot_create_governance_system_attribution(): void
    {
        $owner = $this->admin('governance-factory-owner', 'super_admin', [
            'ai_config_access_version' => 3,
        ]);
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $factory = app(AiModelUsageAttemptFactory::class);

        $attempt = $factory->beginForAdmin(
            model: $model,
            executionAdminId: (int) $owner->id,
            accessVersion: 3,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            requestId: $factory->requestId(),
            requestPayload: 'governance probe',
            callKey: 'stream.p1',
            operation: 'governance.model_connection_test',
            businessSource: 'governance_model_test',
        );
        $attempt->succeeded();

        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_production_attempt_records_one_terminal_event_with_normalized_tokens_and_frozen_shared_attribution(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $executor = $this->admin('executor', 'admin', [
            'shared_ai_config_owner_id' => $owner->id,
            'ai_config_access_version' => 9,
        ]);
        $model = $this->model($owner);
        $factory = app(AiModelUsageAttemptFactory::class);
        $requestId = $factory->requestId();
        $attempt = $factory->beginForAdmin(
            model: $model,
            executionAdminId: $executor->id,
            accessVersion: 9,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_SHARED,
            requestId: $requestId,
            requestPayload: 'private prompt that must only be hashed',
            callKey: 'candidate-1',
            operation: 'article.generate',
            businessSource: 'worker_article_generation',
            sourceType: Task::class,
            sourceId: 42,
        );

        $executor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => 10,
        ])->save();
        $attempt->revoked('ai_config_access_revoked', [
            'promptTokens' => 11,
            'completionTokens' => 7,
        ]);
        $attempt->failed('must_not_create_a_second_event');

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame(hash('sha256', 'private prompt that must only be hashed'), $event->request_payload_digest);
        $this->assertSame($owner->id, $event->config_owner_admin_id);
        $this->assertSame($executor->id, $event->execution_admin_id);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $event->model_source);
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(11, $event->input_tokens);
        $this->assertSame(7, $event->output_tokens);
        $this->assertSame(18, $event->total_tokens);
        $this->assertStringNotContainsString('private prompt', json_encode($event->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_production_attempt_is_best_effort_when_attribution_cannot_be_captured(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $factory = app(AiModelUsageAttemptFactory::class);
        $attempt = $factory->beginForAdmin(
            model: $model,
            executionAdminId: 999999,
            accessVersion: 1,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
            requestId: $factory->requestId(),
            requestPayload: 'payload',
            callKey: 'primary',
            operation: 'article.generate',
            businessSource: 'article_editor',
        );

        $attempt->succeeded(['prompt_tokens' => 1, 'completion_tokens' => 2]);

        $this->assertTrue($attempt->isFinalized());
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_terminal_recording_failure_does_not_escape_into_the_business_call(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $snapshot = $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
        $attempt = new AiModelUsageAttempt(
            $snapshot,
            app(AiModelUsageRecorder::class),
            [
                'call_key' => 'primary',
                'operation' => 'system.test',
                'business_source' => 'telemetry_test',
                'source_type' => null,
                'source_id' => null,
            ],
        );

        Schema::drop('ai_model_usage_events');
        $attempt->succeeded(['prompt_tokens' => 1, 'completion_tokens' => 1]);

        $this->assertTrue($attempt->isFinalized());
    }

    public function test_recorder_persists_whitelisted_usage_metadata_without_sensitive_payloads(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $executor = $this->admin('executor', 'admin', [
            'shared_ai_config_owner_id' => $owner->id,
        ]);
        $model = $this->model($owner);
        $snapshot = $this->snapshot($model, $executor, AiModelUsageEvent::MODEL_SOURCE_SHARED);

        $event = app(AiModelUsageRecorder::class)->record($snapshot, [
            ...$this->usagePayload(),
            'source_type' => Task::class,
            'source_id' => 42,
            'estimated_cost' => '0.00012500',
            'api_key' => 'must-never-persist',
            'endpoint' => 'https://sensitive.example.test',
            'prompt' => 'private prompt',
            'content' => 'private article',
            'raw_response' => ['private' => true],
        ]);

        $this->assertTrue($event->model->is($model));
        $this->assertTrue($event->configOwnerAdmin->is($owner));
        $this->assertTrue($event->executionAdmin->is($executor));
        $this->assertSame($snapshot->aiConfigAccessVersion, $event->ai_config_access_version);
        $this->assertSame($snapshot->requestPayloadDigest, $event->request_payload_digest);
        $this->assertSame('0.00012500', $event->estimated_cost);
        foreach (['api_key', 'endpoint', 'prompt', 'content', 'raw_response'] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $event->getAttributes());
        }
    }

    public function test_personal_and_shared_snapshots_reject_cross_admin_attribution(): void
    {
        $owner = $this->admin('owner', 'admin');
        $peer = $this->admin('peer', 'admin');
        $model = $this->model($owner);

        foreach ([
            fn () => $this->snapshot($model, $peer, AiModelUsageEvent::MODEL_SOURCE_PERSONAL),
            fn () => $this->snapshot($model, $peer, AiModelUsageEvent::MODEL_SOURCE_SHARED),
        ] as $invalidSnapshot) {
            try {
                $invalidSnapshot();
                $this->fail('Expected cross-administrator attribution to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }
    }

    public function test_shared_snapshot_requires_active_super_owner_ordinary_executor_and_current_binding(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $executor = $this->admin('executor', 'admin');
        $peerSuper = $this->admin('peer-super', 'super_admin', [
            'shared_ai_config_owner_id' => $owner->id,
        ]);
        $model = $this->model($owner);

        foreach ([$executor, $peerSuper] as $invalidExecutor) {
            try {
                $this->snapshot($model, $invalidExecutor, AiModelUsageEvent::MODEL_SOURCE_SHARED);
                $this->fail('Expected invalid shared attribution to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }

        $executor->forceFill([
            'shared_ai_config_owner_id' => $owner->id,
            'ai_config_access_version' => 4,
        ])->save();
        try {
            $this->snapshot(
                $model,
                $executor,
                AiModelUsageEvent::MODEL_SOURCE_SHARED,
                accessVersion: 3,
            );
            $this->fail('Expected a stale shared access version to fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_model_usage_events', 0);
        }

        $owner->forceFill(['status' => 'disabled'])->save();
        $this->expectException(ValidationException::class);
        $this->snapshot($model, $executor, AiModelUsageEvent::MODEL_SOURCE_SHARED);
    }

    public function test_pre_call_snapshot_can_record_revocation_after_sharing_is_closed(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $executor = $this->admin('executor', 'admin', [
            'shared_ai_config_owner_id' => $owner->id,
            'ai_config_access_version' => 7,
        ]);
        $model = $this->model($owner);
        $snapshot = $this->snapshot($model, $executor, AiModelUsageEvent::MODEL_SOURCE_SHARED);

        $executor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => 8,
        ])->save();
        $event = app(AiModelUsageRecorder::class)->record($snapshot, [
            ...$this->usagePayload(),
            'status' => AiModelUsageEvent::STATUS_REVOKED,
            'error_code' => 'ai_config_access_revoked',
        ]);

        $this->assertSame(7, $event->ai_config_access_version);
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame($executor->id, $event->execution_admin_id);
        $this->assertSame($owner->id, $event->config_owner_admin_id);
    }

    public function test_system_snapshot_requires_system_only_model_owned_by_active_super_admin(): void
    {
        $super = $this->admin('super', 'super_admin');
        $ordinary = $this->admin('ordinary', 'admin');
        $userContentModel = $this->model($super);
        $ordinarySystemModel = $this->model($ordinary, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        foreach ([$userContentModel, $ordinarySystemModel] as $invalidModel) {
            try {
                $this->snapshot($invalidModel, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
                $this->fail('Expected invalid system attribution to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }

        $systemModel = $this->model($super, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $event = app(AiModelUsageRecorder::class)->record(
            $this->snapshot($systemModel, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM),
            $this->usagePayload(),
        );

        $this->assertNull($event->execution_admin_id);
        $this->assertSame(0, $event->ai_config_access_version);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $event->model_source);
    }

    public function test_request_and_call_key_make_recording_idempotent_and_detect_collisions(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $snapshot = $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
        $payload = $this->usagePayload();
        $recorder = app(AiModelUsageRecorder::class);

        $first = $recorder->record($snapshot, $payload);
        $second = $recorder->record($snapshot, $payload);
        $fallback = $recorder->record($snapshot, [...$payload, 'call_key' => 'shared-fallback']);

        $this->assertTrue($first->is($second));
        $this->assertFalse($first->is($fallback));
        $this->assertDatabaseCount('ai_model_usage_events', 2);

        try {
            $recorder->record($snapshot, [...$payload, 'total_tokens' => 999]);
            $this->fail('Expected a reused idempotency key with different usage metadata to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('call_key', $exception->errors());
            $this->assertDatabaseCount('ai_model_usage_events', 2);
        }
    }

    public function test_same_request_and_call_with_a_different_request_digest_is_a_stable_conflict(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $requestId = '00000000-0000-4000-8000-000000000001';
        $firstSnapshot = $this->snapshot(
            $model,
            null,
            AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            $requestId,
            hash('sha256', 'first request'),
        );
        $secondSnapshot = $this->snapshot(
            $model,
            null,
            AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            $requestId,
            hash('sha256', 'changed request'),
        );
        $recorder = app(AiModelUsageRecorder::class);
        $recorder->record($firstSnapshot, $this->usagePayload());

        try {
            $recorder->record($secondSnapshot, $this->usagePayload());
            $this->fail('Expected a changed request digest to conflict with the recorded call.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['ai_usage_event_idempotency_conflict'],
                $exception->errors()['call_key'] ?? [],
            );
        }

        $this->assertDatabaseCount('ai_model_usage_events', 1);
    }

    public function test_sensitive_or_malformed_identifiers_are_rejected(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        foreach ([
            ['request_id' => "secret-api-key\nrequest"],
            ['request_payload_digest' => strtoupper(hash('sha256', 'payload'))],
            ['request_payload_digest' => 'missing'],
        ] as $invalidSnapshot) {
            try {
                $this->snapshot(
                    $model,
                    null,
                    AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
                    $invalidSnapshot['request_id'] ?? '00000000-0000-4000-8000-000000000001',
                    $invalidSnapshot['request_payload_digest'] ?? hash('sha256', 'payload'),
                );
                $this->fail('Expected malformed snapshot identifiers to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }

        $snapshot = $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
        foreach ([
            ['call_key' => "primary\napi_key=secret"],
            ['operation' => '../article.generate'],
            ['source_type' => "App\\Models\\Task\nsecret"],
            ['source_id' => '42/private'],
            ['error_code' => "provider_error\nsecret"],
        ] as $invalidPayload) {
            try {
                app(AiModelUsageRecorder::class)->record(
                    $snapshot,
                    [...$this->usagePayload(), ...$invalidPayload],
                );
                $this->fail('Expected unsafe usage identifiers to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }
    }

    public function test_recorder_uses_conflict_safe_idempotency_inside_an_outer_transaction(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQL shape assertion targets the SQLite conflict-safe grammar.');
        }

        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $snapshot = $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
        $payload = $this->usagePayload();
        DB::enableQueryLog();

        [$first, $second] = DB::transaction(function () use ($snapshot, $payload): array {
            $recorder = app(AiModelUsageRecorder::class);

            return [$recorder->record($snapshot, $payload), $recorder->record($snapshot, $payload)];
        });
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('ai_model_usage_events', 1);
        $this->assertTrue(collect($queries)->contains(
            static fn (array $query): bool => str_contains(
                strtolower((string) $query['query']),
                'insert or ignore into "ai_model_usage_events"',
            ),
        ));
    }

    public function test_usage_values_are_restricted_to_known_states_and_non_negative_tokens(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $snapshot = $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM);
        $recorder = app(AiModelUsageRecorder::class);

        foreach ([
            ['status' => AiModelUsageEvent::STATUS_STARTED],
            ['status' => 'mystery'],
            ['input_tokens' => -1],
            ['output_tokens' => -1],
            ['total_tokens' => -1],
        ] as $invalid) {
            try {
                $recorder->record($snapshot, [...$this->usagePayload(), ...$invalid]);
                $this->fail('Expected usage event validation to reject invalid metadata.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }
    }

    public function test_usage_events_are_append_only_through_models_and_query_builder(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $event = app(AiModelUsageRecorder::class)->record(
            $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM),
            $this->usagePayload(),
        );

        try {
            $event->forceFill(['status' => AiModelUsageEvent::STATUS_FAILED])->save();
            $this->fail('Expected model updates to be blocked.');
        } catch (LogicException) {
            $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->fresh()->status);
        }

        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('ai_model_usage_events')->where('id', $event->id);
                $operation === 'update'
                    ? $query->update(['status' => AiModelUsageEvent::STATUS_FAILED])
                    : $query->delete();
                $this->fail('Expected database append-only enforcement to reject '.$operation.'.');
            } catch (QueryException) {
                $this->assertDatabaseHas('ai_model_usage_events', [
                    'id' => $event->id,
                    'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
                ]);
            }
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_database_rejects_missing_identity_invalid_attribution_and_negative_values(): void
    {
        $valid = $this->rawUsageRow();

        foreach ([
            ['ai_model_id' => null],
            ['config_owner_admin_id' => null],
            ['created_at' => null],
            [
                'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
                'execution_admin_id' => 10,
                'model_source' => AiModelUsageEvent::MODEL_SOURCE_SHARED,
            ],
            ['input_tokens' => -1],
        ] as $invalid) {
            try {
                DB::table('ai_model_usage_events')->insert([...$valid, ...$invalid]);
                $this->fail('Expected database usage invariants to reject the row.');
            } catch (QueryException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }
    }

    public function test_postgresql_ddl_defines_constraints_and_append_only_triggers(): void
    {
        $statements = implode("\n", AiModelUsageLedgerSchema::postgresInstallStatements());
        $query = DB::connection()->query()->from('ai_model_usage_events');
        $postgresInsert = (new PostgresGrammar(DB::connection()))->compileInsertOrIgnore(
            $query,
            [['request_id' => '00000000-0000-4000-8000-000000000001']],
        );

        $this->assertStringContainsString('CHECK', $statements);
        $this->assertStringContainsString('request_payload_digest', $statements);
        $this->assertStringContainsString('execution_admin_id IS NULL', $statements);
        $this->assertStringContainsString("model_source IN ('personal', 'shared', 'system')", $statements);
        $this->assertStringContainsString('BEFORE UPDATE OR DELETE', $statements);
        $this->assertStringContainsString('RAISE EXCEPTION', $statements);
        $this->assertStringContainsString('CREATE FUNCTION', $statements);
        $this->assertStringEndsWith('on conflict do nothing', $postgresInsert);

        $legacyStatements = implode("\n", AiModelUsageLedgerSchema::governanceAttributionDowngradeStatements());
        $this->assertStringNotContainsString("model_source IN ('personal', 'shared', 'system')", $legacyStatements);
    }

    public function test_usage_attribution_ids_survive_later_model_and_admin_deletion(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $event = app(AiModelUsageRecorder::class)->record(
            $this->snapshot($model, null, AiModelUsageEvent::MODEL_SOURCE_SYSTEM),
            $this->usagePayload(),
        );

        $model->delete();
        $owner->delete();

        $retained = $event->fresh();
        $this->assertSame($model->id, $retained->ai_model_id);
        $this->assertSame($owner->id, $retained->config_owner_admin_id);
        $this->assertNull($retained->execution_admin_id);
    }

    /** @return array<string, mixed> */
    private function usagePayload(): array
    {
        return [
            'call_key' => 'primary',
            'operation' => 'article.generate',
            'business_source' => 'article_generation',
            'source_type' => null,
            'source_id' => null,
            'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
            'error_code' => null,
            'input_tokens' => 10,
            'output_tokens' => 15,
            'total_tokens' => 25,
            'estimated_cost' => null,
        ];
    }

    private function snapshot(
        AiModel $model,
        ?Admin $executor,
        string $modelSource,
        string $requestId = '00000000-0000-4000-8000-000000000001',
        string $requestPayloadDigest = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ?int $accessVersion = null,
    ): AiModelUsageAccessSnapshot {
        $executionScope = $modelSource === AiModelUsageEvent::MODEL_SOURCE_SYSTEM
            ? AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM
            : AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN;

        return AiModelUsageAccessSnapshot::capture(
            model: $model,
            executionAdmin: $executor,
            executionScope: $executionScope,
            modelSource: $modelSource,
            aiConfigAccessVersion: $accessVersion ?? ($executor instanceof Admin
                ? (int) $executor->ai_config_access_version
                : 0),
            requestId: $requestId,
            requestPayloadDigest: $requestPayloadDigest,
        );
    }

    /** @return array<string, mixed> */
    private function rawUsageRow(): array
    {
        return [
            'event_uuid' => (string) Str::uuid(),
            'request_id' => '00000000-0000-4000-8000-000000000001',
            'request_payload_digest' => str_repeat('a', 64),
            'call_key' => 'primary',
            'payload_fingerprint' => str_repeat('b', 64),
            'operation' => 'article.generate',
            'ai_model_id' => 10,
            'config_owner_admin_id' => 20,
            'execution_admin_id' => null,
            'ai_config_access_version' => 0,
            'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            'model_source' => AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            'business_source' => 'system_collection',
            'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'created_at' => now(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function admin(string $username, string $role, array $overrides = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill($overrides)->save();

        return $admin->refresh();
    }

    private function model(Admin $owner, string $accessScope = AiModel::ACCESS_SCOPE_USER_CONTENT): AiModel
    {
        $model = new AiModel([
            'name' => 'Usage model '.$owner->username.' '.$accessScope.' '.Str::random(4),
            'version' => 'test',
            'api_key' => 'secret-key',
            'model_id' => 'usage-model-'.Str::uuid(),
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $accessScope,
        ])->save();

        return $model;
    }
}
