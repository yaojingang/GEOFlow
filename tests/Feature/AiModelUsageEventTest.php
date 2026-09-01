<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Task;
use App\Services\Admin\AiModelUsageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AiModelUsageEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_persists_whitelisted_usage_metadata_without_sensitive_payloads(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $executor = $this->admin('executor', 'admin');
        $model = $this->model($owner);

        $event = app(AiModelUsageRecorder::class)->record([
            'request_id' => 'request-123',
            'call_key' => 'primary',
            'operation' => 'article.generate',
            'ai_model_id' => $model->id,
            'config_owner_admin_id' => $owner->id,
            'execution_admin_id' => $executor->id,
            'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            'model_source' => AiModelUsageEvent::MODEL_SOURCE_SHARED,
            'business_source' => 'article_generation',
            'source_type' => Task::class,
            'source_id' => 42,
            'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
            'error_code' => null,
            'input_tokens' => 10,
            'output_tokens' => 15,
            'total_tokens' => 25,
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
        $this->assertSame('0.00012500', $event->estimated_cost);
        foreach (['api_key', 'endpoint', 'prompt', 'content', 'raw_response'] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $event->getAttributes());
        }
    }

    public function test_request_and_call_key_make_recording_idempotent_and_detect_collisions(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $payload = $this->usagePayload($owner, $model);
        $recorder = app(AiModelUsageRecorder::class);

        $first = $recorder->record($payload);
        $second = $recorder->record($payload);
        $fallback = $recorder->record([...$payload, 'call_key' => 'shared-fallback']);

        $this->assertTrue($first->is($second));
        $this->assertFalse($first->is($fallback));
        $this->assertDatabaseCount('ai_model_usage_events', 2);

        try {
            $recorder->record([...$payload, 'total_tokens' => 999]);
            $this->fail('Expected a reused idempotency key with different usage metadata to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('call_key', $exception->errors());
            $this->assertDatabaseCount('ai_model_usage_events', 2);
        }
    }

    public function test_recorder_uses_conflict_safe_idempotency_inside_an_outer_transaction(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQL shape assertion targets the SQLite conflict-safe grammar.');
        }

        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $payload = $this->usagePayload($owner, $model);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        [$first, $second] = DB::transaction(function () use ($payload): array {
            $recorder = app(AiModelUsageRecorder::class);

            return [$recorder->record($payload), $recorder->record($payload)];
        });

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('ai_model_usage_events', 1);
        $this->assertTrue(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'insert or ignore into "ai_model_usage_events"'),
        ));
    }

    public function test_reused_call_key_with_different_fingerprint_returns_a_stable_conflict(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $payload = $this->usagePayload($owner, $model);
        $recorder = app(AiModelUsageRecorder::class);
        $recorder->record($payload);

        try {
            DB::transaction(fn () => $recorder->record([...$payload, 'total_tokens' => 999]));
            $this->fail('Expected a reused idempotency key with different usage metadata to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['ai_usage_event_idempotency_conflict'],
                $exception->errors()['call_key'] ?? [],
            );
        }

        $this->assertDatabaseCount('ai_model_usage_events', 1);
    }

    public function test_usage_values_are_restricted_to_known_states_and_non_negative_tokens(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $recorder = app(AiModelUsageRecorder::class);

        foreach ([
            ['execution_scope' => 'unknown'],
            ['model_source' => 'other_admin'],
            ['status' => 'mystery'],
            ['input_tokens' => -1],
            ['output_tokens' => -1],
            ['total_tokens' => -1],
            [
                'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                'model_source' => AiModelUsageEvent::MODEL_SOURCE_SHARED,
                'execution_admin_id' => null,
            ],
        ] as $invalid) {
            try {
                $recorder->record([...$this->usagePayload($owner, $model), ...$invalid]);
                $this->fail('Expected usage event validation to reject invalid metadata.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_model_usage_events', 0);
            }
        }
    }

    public function test_usage_events_are_immutable_after_recording(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $event = app(AiModelUsageRecorder::class)->record($this->usagePayload($owner, $model));

        try {
            $event->forceFill(['status' => AiModelUsageEvent::STATUS_FAILED])->save();
            $this->fail('Expected usage event updates to be blocked.');
        } catch (LogicException) {
            $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->fresh()->status);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_database_rejects_negative_usage_values_outside_the_recorder(): void
    {
        $this->expectException(QueryException::class);

        DB::table('ai_model_usage_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'request_id' => 'unsafe-request',
            'call_key' => 'unsafe-call',
            'payload_fingerprint' => str_repeat('a', 64),
            'operation' => 'chat',
            'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            'model_source' => AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            'business_source' => 'system_collection',
            'status' => AiModelUsageEvent::STATUS_SUCCEEDED,
            'input_tokens' => -1,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'created_at' => now(),
        ]);
    }

    public function test_usage_attribution_ids_survive_later_model_and_admin_deletion(): void
    {
        $owner = $this->admin('owner', 'super_admin');
        $model = $this->model($owner);
        $event = app(AiModelUsageRecorder::class)->record($this->usagePayload($owner, $model));

        $model->delete();
        $owner->delete();

        $retained = $event->fresh();
        $this->assertSame($model->id, $retained->ai_model_id);
        $this->assertSame($owner->id, $retained->config_owner_admin_id);
        $this->assertNull($retained->execution_admin_id);
    }

    /** @return array<string, mixed> */
    private function usagePayload(Admin $owner, AiModel $model): array
    {
        return [
            'request_id' => 'request-123',
            'call_key' => 'primary',
            'operation' => 'article.generate',
            'ai_model_id' => $model->id,
            'config_owner_admin_id' => $owner->id,
            'execution_admin_id' => null,
            'execution_scope' => AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            'model_source' => AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            'business_source' => 'system_collection',
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

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function model(Admin $owner): AiModel
    {
        $model = new AiModel([
            'name' => 'Usage model',
            'version' => 'test',
            'api_key' => 'secret-key',
            'model_id' => 'usage-model',
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
}
