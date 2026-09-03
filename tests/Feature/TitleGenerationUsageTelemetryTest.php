<?php

namespace Tests\Feature;

use App\Ai\Agents\TitleGeneratorAgent;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use App\Support\GeoFlow\ApiKeyCrypto;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TitleGenerationUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_personal_title_batch_records_usage_only_after_titles_are_persisted(): void
    {
        TitleGeneratorAgent::fake(['个人模型标题'])->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-personal');
        $model = $this->model($admin, 'personal-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);
        $requestId = (string) $run->dispatch_token;

        app(TitleGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            0,
            $requestId,
        );

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $event->model_source);
        $this->assertSame((int) $admin->id, (int) $event->execution_admin_id);
        $this->assertSame((int) $admin->id, (int) $event->config_owner_admin_id);
        $this->assertSame((int) $model->id, (int) $event->ai_model_id);
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame('b0.a1.c1.p1', $event->call_key);
        $this->assertSame('title_generation.generate', $event->operation);
        $this->assertSame(TitleGenerationRun::class, $event->source_type);
        $this->assertSame((string) $run->id, (string) $event->source_id);
        $this->assertSame(TitleGenerationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertDatabaseHas('titles', ['library_id' => $titleLibrary->id, 'title' => '个人模型标题']);
    }

    public function test_transient_personal_failure_records_a_failed_attempt_before_shared_success(): void
    {
        $providerCalls = 0;
        TitleGeneratorAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;
            if ($providerCalls === 1) {
                throw new ConnectionException('temporary title provider outage');
            }

            return '共享模型标题';
        })->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-failover');
        $provider = $this->admin('title-ledger-provider', 'super_admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'personal-title-model', ['failover_priority' => 1]);
        $shared = $this->model($provider, 'shared-title-model', ['failover_priority' => 1]);
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $personal);

        app(TitleGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            0,
            (string) $run->dispatch_token,
        );

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame(2, $providerCalls);
        $this->assertSame([$personal->id, $shared->id], $events->pluck('ai_model_id')->all());
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        $this->assertSame(
            [AiModelUsageEvent::MODEL_SOURCE_PERSONAL, AiModelUsageEvent::MODEL_SOURCE_SHARED],
            $events->pluck('model_source')->all(),
        );
        $this->assertCount(1, $events->pluck('request_id')->unique());
        $this->assertSame(['b0.a1.c1.p1', 'b0.a1.c2.p1'], $events->pluck('call_key')->all());
        $this->assertTrue($events->every(
            static fn (AiModelUsageEvent $event): bool => strlen((string) $event->call_key) <= 100,
        ));
        $this->assertSame((int) $provider->id, (int) $events->last()->config_owner_admin_id);
        $this->assertSame((int) $admin->id, (int) $events->last()->execution_admin_id);
    }

    public function test_default_flags_fail_closed_for_a_pending_run_without_frozen_identity(): void
    {
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);
        TitleGeneratorAgent::fake(['不得调用'])->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-missing-identity');
        $model = $this->model($admin, 'legacy-title-model');
        $dispatchToken = (string) Str::uuid7();
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
            'keyword_snapshot' => ['keywords' => ['GEO 标题'], 'cursor_id' => 0, 'available_count' => 1],
            'available_at' => now(),
            'dispatch_token' => $dispatchToken,
        ]);

        app(TitleGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            0,
            $dispatchToken,
        );

        TitleGeneratorAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertSame(TitleGenerationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('ai_config_access_revoked', $run->fresh()->error_code);
        $this->assertFalse((bool) $run->fresh()->retryable_failure);
    }

    public function test_permanent_provider_failure_records_one_failed_attempt_without_shared_fallback(): void
    {
        $providerCalls = 0;
        TitleGeneratorAgent::fake(function () use (&$providerCalls): never {
            $providerCalls++;

            throw new RequestException(new ClientResponse(new PsrResponse(401, [], '{}')));
        })->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-permanent');
        $provider = $this->admin('title-ledger-permanent-provider', 'super_admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->model($admin, 'personal-permanent-title-model');
        $this->model($provider, 'shared-unused-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $personal);

        try {
            app(TitleGenerationCoordinator::class)->processBatch(
                (int) $run->id,
                0,
                (string) $run->dispatch_token,
            );
            $this->fail('Expected a permanent provider failure.');
        } catch (\Throwable) {
            $this->assertSame(1, $providerCalls);
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $event->status);
        $this->assertSame((int) $personal->id, (int) $event->ai_model_id);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_access_revoked_after_provider_return_marks_usage_revoked_and_does_not_persist_titles(): void
    {
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-revoked');
        $model = $this->model($admin, 'revoked-title-model');
        TitleGeneratorAgent::fake(function () use ($admin): string {
            Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');

            return '撤权后不可保存的标题';
        })->preventStrayPrompts();
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);

        try {
            app(TitleGenerationCoordinator::class)->processBatch(
                (int) $run->id,
                0,
                (string) $run->dispatch_token,
            );
            $this->fail('Expected access revocation to stop the batch.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertDatabaseCount('titles', 0);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_cancelled_claim_after_provider_return_discards_usage_and_preserves_cancelled_run(): void
    {
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-cancelled');
        $model = $this->model($admin, 'cancelled-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);
        TitleGeneratorAgent::fake(function () use ($admin, $run): string {
            app(TitleGenerationCoordinator::class)->cancel($run->fresh(), $admin);

            return '取消后不可保存的标题';
        })->preventStrayPrompts();

        app(TitleGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            0,
            (string) $run->dispatch_token,
        );

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame(TitleGenerationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertDatabaseCount('titles', 0);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_worker_redelivery_uses_the_persisted_batch_attempt_ordinal_without_duplicate_events(): void
    {
        $providerCalls = 0;
        TitleGeneratorAgent::fake(function () use (&$providerCalls): string {
            $providerCalls++;
            if ($providerCalls === 1) {
                throw new ConnectionException('temporary retryable title outage');
            }

            return '重投后成功标题';
        })->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-redelivery');
        $model = $this->model($admin, 'redelivery-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);
        $leaseToken = (string) $run->dispatch_token;

        try {
            app(TitleGenerationCoordinator::class)->processBatch((int) $run->id, 0, $leaseToken);
            $this->fail('Expected the first provider call to fail.');
        } catch (\RuntimeException) {
            $this->assertSame(TitleGenerationRun::STATUS_RUNNING, $run->fresh()->status);
        }
        app(TitleGenerationCoordinator::class)->processBatch((int) $run->id, 0, $leaseToken);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame(2, $providerCalls);
        $this->assertSame(['b0.a1.c1.p1', 'b0.a2.c1.p1'], $events->pluck('call_key')->all());
        $this->assertSame([$leaseToken], $events->pluck('request_id')->unique()->values()->all());
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        $this->assertDatabaseHas('titles', ['title' => '重投后成功标题']);
    }

    public function test_persist_batch_rollback_discards_the_returned_provider_result(): void
    {
        TitleGeneratorAgent::fake(['事务回滚标题'])->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-rollback');
        $model = $this->model($admin, 'rollback-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);
        DB::listen(static function ($query): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'insert') && str_contains($sql, 'titles')) {
                throw new \RuntimeException('forced_title_batch_rollback');
            }
        });

        try {
            app(TitleGenerationCoordinator::class)->processBatch(
                (int) $run->id,
                0,
                (string) $run->dispatch_token,
            );
            $this->fail('Expected title persistence to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced_title_batch_rollback', $exception->getMessage());
        }

        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, AiModelUsageEvent::query()->sole()->status);
        $this->assertDatabaseCount('titles', 0);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_usage_telemetry_failure_does_not_change_the_persisted_title_result(): void
    {
        TitleGeneratorAgent::fake(['账本故障仍保存标题'])->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-best-effort');
        $model = $this->model($admin, 'best-effort-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);
        Schema::drop('ai_model_usage_events');

        try {
            app(TitleGenerationCoordinator::class)->processBatch(
                (int) $run->id,
                0,
                (string) $run->dispatch_token,
            );

            $this->assertDatabaseHas('titles', ['title' => '账本故障仍保存标题']);
            $this->assertSame(TitleGenerationRun::STATUS_COMPLETED, $run->fresh()->status);
        } finally {
            $migration = require database_path('migrations/2026_09_01_223149_create_ai_model_usage_events_table.php');
            $migration->up();
        }
    }

    public function test_usage_event_and_queued_job_contain_no_model_credentials_or_generated_text(): void
    {
        TitleGeneratorAgent::fake(['不可进入账本的标题正文'])->preventStrayPrompts();
        [$admin, $keywordLibrary, $titleLibrary] = $this->fixtures('title-ledger-safe');
        $model = $this->model($admin, 'safe-title-model');
        $run = $this->startRun($admin, $keywordLibrary, $titleLibrary, $model);

        app(TitleGenerationCoordinator::class)->processBatch(
            (int) $run->id,
            0,
            (string) $run->dispatch_token,
        );

        $serialized = AiModelUsageEvent::query()->sole()->toJson().serialize($run->fresh());
        $this->assertStringNotContainsString('title-ledger-secret', $serialized);
        $this->assertStringNotContainsString('https://title.test', $serialized);
        $this->assertStringNotContainsString('不可进入账本的标题正文', AiModelUsageEvent::query()->sole()->toJson());
        $this->assertStringNotContainsString('GEO 标题', AiModelUsageEvent::query()->sole()->toJson());
    }

    /** @return array{Admin,KeywordLibrary,TitleLibrary} */
    private function fixtures(string $prefix): array
    {
        $admin = $this->admin($prefix.'-admin');
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => $prefix.' keywords',
            'description' => '',
            'keyword_count' => 1,
        ]);
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'GEO 标题',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => $prefix.' titles',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => false,
        ]);

        return [$admin, $keywordLibrary, $titleLibrary];
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

    private function model(Admin $owner, string $modelId, array $overrides = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('title-ledger-secret'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://title.test/v1',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function startRun(
        Admin $admin,
        KeywordLibrary $keywordLibrary,
        TitleLibrary $titleLibrary,
        AiModel $model,
    ): TitleGenerationRun {
        return app(TitleGenerationCoordinator::class)->start(
            $titleLibrary,
            [
                'keyword_library_id' => (int) $keywordLibrary->id,
                'ai_model_id' => (int) $model->id,
                'title_count' => 1,
                'title_style' => 'professional',
                'custom_prompt' => '',
                'confirmed_keyword_reuse' => true,
            ],
            (int) $admin->id,
            'zh_CN',
        );
    }
}
