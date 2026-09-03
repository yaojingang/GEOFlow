<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiModelTestBoundaryHook;
use App\Services\Admin\AdminAiModelTestPreparationService;
use App\Services\Admin\AdminAiSettingsService;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAiModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_administrator_creates_an_owned_user_content_model(): void
    {
        $admin = $this->admin('ordinary', 'admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertSessionHasErrors('access_scope');

        $this->assertDatabaseCount('ai_models', 0);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload())
            ->assertRedirect(route('admin.ai-models.index'));

        $model = AiModel::query()->sole();
        $this->assertSame($admin->id, $model->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $model->access_scope);
    }

    public function test_super_administrator_can_create_an_owned_system_model_without_a_personal_default(): void
    {
        $admin = $this->admin('system-owner', 'super_admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'name' => 'System embedding',
                'model_id' => 'system-embedding',
                'model_type' => 'embedding',
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $model = AiModel::query()->sole();
        $this->assertSame($admin->id, $model->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $model->access_scope);
        $this->assertDatabaseMissing('admin_ai_settings', [
            'admin_id' => $admin->id,
            'default_embedding_model_id' => $model->id,
        ]);
        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_system_scope_creation_rechecks_the_locked_current_actor_role(): void
    {
        $admin = $this->admin('role-revoked-during-create', 'super_admin');
        $this->app->instance(ApiKeyCrypto::class, new class($admin) extends ApiKeyCrypto
        {
            public function __construct(private readonly Admin $admin) {}

            public function encrypt(string $apiKey): string
            {
                $this->admin->forceFill(['role' => 'admin'])->save();

                return parent::encrypt($apiKey);
            }
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertSessionHasErrors('access_scope');

        $this->assertDatabaseCount('ai_models', 0);
    }

    public function test_system_scope_update_rechecks_the_locked_current_actor_role_atomically(): void
    {
        $admin = $this->admin('role-revoked-during-update', 'super_admin');
        $model = $this->model($admin, [
            'name' => 'Original name',
            'version' => 'original-version',
            'model_id' => 'original-model-id',
            'api_url' => 'https://original.example.test',
            'failover_priority' => 40,
            'daily_limit' => 120,
        ]);
        $original = $model->getRawOriginal();
        $this->app->instance(ApiKeyCrypto::class, new class($admin) extends ApiKeyCrypto
        {
            public function __construct(private readonly Admin $admin) {}

            public function encrypt(string $apiKey): string
            {
                $this->admin->forceFill(['role' => 'admin'])->save();

                return parent::encrypt($apiKey);
            }
        });

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => 'Forged updated name',
                'version' => 'forged-version',
                'api_key' => 'rotated-secret-key',
                'model_id' => 'forged-model-id',
                'api_url' => 'https://forged.example.test',
                'failover_priority' => 1,
                'daily_limit' => 1,
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertSessionHasErrors('access_scope');

        $current = $model->fresh();
        foreach ([
            'owner_admin_id',
            'access_scope',
            'name',
            'version',
            'api_key',
            'model_id',
            'model_type',
            'api_url',
            'failover_priority',
            'daily_limit',
            'status',
        ] as $attribute) {
            $this->assertSame($original[$attribute], $current->getRawOriginal($attribute), $attribute);
        }
    }

    public function test_role_downgrade_during_encryption_can_update_an_owned_user_content_model(): void
    {
        $admin = $this->admin('role-downgraded-user-content', 'super_admin');
        $model = $this->model($admin);
        $this->app->instance(ApiKeyCrypto::class, new class($admin) extends ApiKeyCrypto
        {
            public function __construct(private readonly Admin $admin) {}

            public function encrypt(string $apiKey): string
            {
                $this->admin->forceFill(['role' => 'admin'])->save();

                return parent::encrypt($apiKey);
            }
        });

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => 'Updated after role downgrade',
                'api_key' => 'rotated-user-content-key',
                'model_id' => $model->model_id,
                'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $current = $model->fresh();
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame($admin->id, $current->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $current->access_scope);
        $this->assertSame('Updated after role downgrade', $current->name);
        $this->assertSame(
            'rotated-user-content-key',
            app(ApiKeyCrypto::class)->decrypt((string) $current->getRawOriginal('api_key')),
        );
    }

    public function test_role_downgrade_during_update_does_not_clear_the_global_embedding_default(): void
    {
        $admin = $this->admin('stale-global-setting-role', 'super_admin');
        $model = $this->model($admin, [
            'model_type' => 'embedding',
            'model_id' => 'global-embedding-before-downgrade',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $model->id,
        ]);
        (new AdminAiSetting)->forceFill([
            'admin_id' => $admin->id,
            'default_embedding_model_id' => $model->id,
            'updated_by_admin_id' => $admin->id,
        ])->save();
        $this->app->instance(ApiKeyCrypto::class, new class((int) $admin->id) extends ApiKeyCrypto
        {
            public function __construct(private readonly int $adminId) {}

            public function encrypt(string $apiKey): string
            {
                Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']);

                return parent::encrypt($apiKey);
            }
        });

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => 'Personal chat after downgrade',
                'api_key' => 'rotated-after-downgrade',
                'model_id' => 'personal-chat-after-downgrade',
                'model_type' => 'chat',
                'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('chat', $model->fresh()->model_type);
        $this->assertNull(
            AdminAiSetting::query()->where('admin_id', $admin->id)->value('default_embedding_model_id'),
        );
        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_role_downgrade_during_connection_test_skips_the_super_admin_workspace_probe(): void
    {
        AdminHelpAssistant::fake(['workspace probe must not run'])->preventStrayPrompts();
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);
        $admin = $this->admin('probe-role-downgrade', 'super_admin');
        $model = $this->model($admin);
        $this->app->instance(ApiKeyCrypto::class, new class((int) $admin->id) extends ApiKeyCrypto
        {
            public function __construct(private readonly int $adminId) {}

            public function decrypt(string $encrypted): string
            {
                Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']);

                return parent::decrypt($encrypted);
            }
        });

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('meta.workspace_ready');

        $this->assertSame('admin', $admin->fresh()->role);
        Http::assertSentCount(1);
    }

    #[DataProvider('connectionRevocationCases')]
    public function test_connection_revalidation_rejects_stale_execution_snapshots_without_external_calls(string $mutation): void
    {
        Http::fake();
        $admin = $this->admin('revalidation-'.$mutation, 'admin');
        $other = $this->admin('revalidation-other-'.$mutation, 'admin');
        $model = $this->model($admin, [
            'api_key' => app(ApiKeyCrypto::class)->encrypt('snapshot-original-secret'),
        ]);
        $rotatedKey = app(ApiKeyCrypto::class)->encrypt('snapshot-rotated-secret');
        $this->app->instance(
            AdminAiModelTestBoundaryHook::class,
            new class($mutation, (int) $admin->id, (int) $other->id, (int) $model->id, $rotatedKey) extends AdminAiModelTestBoundaryHook
            {
                public function __construct(
                    private readonly string $mutation,
                    private readonly int $adminId,
                    private readonly int $otherAdminId,
                    private readonly int $modelId,
                    private readonly string $rotatedKey,
                ) {}

                public function beforeRevalidation(AdminAiModelTestSnapshot $snapshot): void
                {
                    match ($this->mutation) {
                        'admin_inactive' => Admin::query()->whereKey($this->adminId)->update(['status' => 'inactive']),
                        'access_version' => Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version'),
                        'owner' => AiModel::query()->whereKey($this->modelId)->update(['owner_admin_id' => $this->otherAdminId]),
                        'api_key' => AiModel::query()->whereKey($this->modelId)->update(['api_key' => $this->rotatedKey]),
                        'api_url' => AiModel::query()->whereKey($this->modelId)->update(['api_url' => 'https://rotated.example.test']),
                        'model_id' => AiModel::query()->whereKey($this->modelId)->update(['model_id' => 'rotated-provider-model']),
                        'status' => AiModel::query()->whereKey($this->modelId)->update(['status' => 'inactive']),
                        'archived' => AiModel::query()->whereKey($this->modelId)->update(['archived_at' => now()]),
                    };
                }
            },
        );

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $response
            ->assertDontSee('snapshot-original-secret', false)
            ->assertDontSee('snapshot-rotated-secret', false)
            ->assertDontSee('rotated.example.test', false)
            ->assertDontSee('rotated-provider-model', false);
    }

    /** @return array<string, array{string}> */
    public static function connectionRevocationCases(): array
    {
        return [
            'admin inactive' => ['admin_inactive'],
            'access version changed' => ['access_version'],
            'owner changed' => ['owner'],
            'credential rotated' => ['api_key'],
            'endpoint changed' => ['api_url'],
            'provider model changed' => ['model_id'],
            'status changed' => ['status'],
            'archived' => ['archived'],
        ];
    }

    #[DataProvider('postOutboundRevocationCases')]
    public function test_http_connection_result_is_discarded_when_access_changes_after_outbound(
        string $mutation,
        bool $requiresSuperAdmin,
    ): void {
        $providerResult = 'provider-result-must-be-discarded';
        Log::spy();
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3], 'private_result' => $providerResult],
                ],
            ]),
        ]);
        $admin = $this->admin('post-http-'.$mutation, $requiresSuperAdmin ? 'super_admin' : 'admin');
        $other = $this->admin('post-http-other-'.$mutation, 'admin');
        $originalKey = 'post-http-original-secret';
        $rotatedKey = app(ApiKeyCrypto::class)->encrypt('post-http-rotated-secret');
        $model = $this->model($admin, [
            'model_type' => 'embedding',
            'model_id' => 'post-http-embedding',
            'api_key' => app(ApiKeyCrypto::class)->encrypt($originalKey),
            'access_scope' => $requiresSuperAdmin
                ? AiModel::ACCESS_SCOPE_SYSTEM_ONLY
                : AiModel::ACCESS_SCOPE_USER_CONTENT,
        ]);
        $this->installPostOutboundRevocationHook(
            $mutation,
            $admin,
            $other,
            $model,
            $rotatedKey,
        );

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.diagnosis.code', 'ai_config_access_revoked');

        Http::assertSentCount(1);
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame('direct.p1', $event->call_key);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $response
            ->assertDontSee($providerResult, false)
            ->assertDontSee($originalKey, false)
            ->assertDontSee('post-http-rotated-secret', false)
            ->assertDontSee('post-outbound-rotated.example.test', false)
            ->assertDontSee('post-outbound-rotated-model', false);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static function (string $message, array $context) use ($providerResult, $originalKey): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'AI model connection test failed.'
                    && ! str_contains($serialized, $providerResult)
                    && ! str_contains($serialized, $originalKey)
                    && ! str_contains($serialized, 'post-http-rotated-secret');
            },
        );
    }

    #[DataProvider('postOutboundRevocationCases')]
    public function test_workspace_probe_result_is_not_persisted_when_access_changes_after_outbound(
        string $mutation,
        bool $requiresSuperAdmin,
    ): void {
        Log::spy();
        AdminHelpAssistant::fake([
            new TextResponse(
                'workspace-provider-result-must-be-discarded',
                new Usage(7, 3),
                new Meta('fake', 'post-workspace-model'),
            ),
        ])->preventStrayPrompts();
        $admin = $this->admin('post-workspace-'.$mutation, 'super_admin');
        $other = $this->admin('post-workspace-other-'.$mutation, 'admin');
        $originalKey = 'post-workspace-original-secret';
        $rotatedKey = app(ApiKeyCrypto::class)->encrypt('post-workspace-rotated-secret');
        $model = $this->model($admin, [
            'api_key' => app(ApiKeyCrypto::class)->encrypt($originalKey),
            'access_scope' => $requiresSuperAdmin && $mutation !== 'role'
                ? AiModel::ACCESS_SCOPE_SYSTEM_ONLY
                : AiModel::ACCESS_SCOPE_USER_CONTENT,
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_profile' => ['sentinel' => 'keep-existing-readiness'],
            'ai_workspace_readiness_failure_code' => 'existing_failure',
        ]);
        $this->installPostOutboundRevocationHook(
            $mutation,
            $admin,
            $other,
            $model,
            $rotatedKey,
        );

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.diagnosis.code', 'ai_config_access_revoked');

        $current = $model->fresh();
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame('stream.p1', $event->call_key);
        $this->assertSame(7, $event->input_tokens);
        $this->assertSame(3, $event->output_tokens);
        $this->assertSame(10, $event->total_tokens);
        $this->assertSame('failed', $current->ai_workspace_readiness_status);
        $this->assertSame('keep-existing-readiness', data_get($current->ai_workspace_readiness_profile, 'sentinel'));
        $this->assertSame('existing_failure', $current->ai_workspace_readiness_failure_code);
        $this->assertSame(1, (int) $current->used_today);
        $this->assertSame(1, (int) $current->total_used);
        $response
            ->assertDontSee('workspace-provider-result-must-be-discarded', false)
            ->assertDontSee($originalKey, false)
            ->assertDontSee('post-workspace-rotated-secret', false);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static function (string $message, array $context) use ($originalKey): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'AI model connection test failed.'
                    && ! str_contains($serialized, 'workspace-provider-result-must-be-discarded')
                    && ! str_contains($serialized, $originalKey)
                    && ! str_contains($serialized, 'post-workspace-rotated-secret');
            },
        );
    }

    public function test_workspace_probe_does_not_start_plain_text_fallback_after_post_outbound_revocation(): void
    {
        $providerCalls = 0;
        AdminHelpAssistant::fake(function () use (&$providerCalls): string {
            $providerCalls++;

            return $providerCalls === 1 ? '' : 'fallback-must-not-run';
        })->preventStrayPrompts();
        $admin = $this->admin('post-workspace-fallback-revoked', 'super_admin');
        $model = $this->model($admin, [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_profile' => ['sentinel' => 'keep-existing-readiness'],
        ]);
        $this->app->instance(
            AdminAiModelTestBoundaryHook::class,
            new class((int) $admin->id) extends AdminAiModelTestBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void
                {
                    Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');
                }
            },
        );

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('meta.diagnosis.code', 'ai_config_access_revoked');

        $this->assertSame(1, $providerCalls);
        $this->assertSame('failed', $model->fresh()->ai_workspace_readiness_status);
        $this->assertSame('keep-existing-readiness', data_get($model->fresh()->ai_workspace_readiness_profile, 'sentinel'));
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_workspace_probe_failure_does_not_replace_readiness_after_second_outbound_revocation(): void
    {
        $providerCalls = 0;
        $providerSecret = 'workspace-failure-provider-secret';
        AdminHelpAssistant::fake(function () use (&$providerCalls, $providerSecret): string {
            $providerCalls++;
            if ($providerCalls === 1) {
                return '';
            }

            throw new \RuntimeException('provider failed '.$providerSecret);
        })->preventStrayPrompts();
        $admin = $this->admin('post-workspace-failure-revoked', 'super_admin');
        $model = $this->model($admin, [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => ['sentinel' => 'keep-ready-profile'],
            'ai_workspace_readiness_failure_code' => null,
        ]);
        $this->app->instance(
            AdminAiModelTestBoundaryHook::class,
            new class((int) $admin->id) extends AdminAiModelTestBoundaryHook
            {
                private int $outboundCount = 0;

                public function __construct(private readonly int $adminId) {}

                public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void
                {
                    $this->outboundCount++;
                    if ($this->outboundCount === 2) {
                        Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');
                    }
                }
            },
        );

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('meta.diagnosis.code', 'ai_config_access_revoked');

        $current = $model->fresh();
        $this->assertSame(2, $providerCalls);
        $this->assertSame('ready', $current->ai_workspace_readiness_status);
        $this->assertSame('keep-ready-profile', data_get($current->ai_workspace_readiness_profile, 'sentinel'));
        $this->assertNull($current->ai_workspace_readiness_failure_code);
        $this->assertSame(1, (int) $current->used_today);
        $this->assertSame(1, (int) $current->total_used);
        $response->assertDontSee($providerSecret, false);
    }

    /** @return array<string, array{string, bool}> */
    public static function postOutboundRevocationCases(): array
    {
        return [
            'admin inactive' => ['admin_inactive', false],
            'role downgraded' => ['role', true],
            'access version changed' => ['access_version', false],
            'owner changed' => ['owner', false],
            'scope changed' => ['scope', false],
            'credential rotated' => ['api_key', false],
            'endpoint changed' => ['api_url', false],
            'provider model changed' => ['model_id', false],
            'status changed' => ['status', false],
            'archived' => ['archived', false],
        ];
    }

    public function test_connection_snapshot_cannot_serialize_or_expose_credentials_in_json_and_debug_output(): void
    {
        $admin = $this->admin('snapshot-redaction', 'admin');
        $model = $this->model($admin, [
            'api_key' => app(ApiKeyCrypto::class)->encrypt('snapshot-never-expose-secret'),
            'api_url' => 'https://snapshot-private.example.test',
            'model_id' => 'snapshot-private-model-id',
        ]);

        $snapshot = app(AdminAiModelTestPreparationService::class)->prepare($admin, (int) $model->id);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $debug = print_r($snapshot, true);

        foreach (['snapshot-never-expose-secret', 'snapshot-private.example.test', 'snapshot-private-model-id'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $json);
            $this->assertStringNotContainsString($sensitive, $debug);
        }

        try {
            serialize($snapshot);
            $this->fail('Snapshot serialization should fail.');
        } catch (\LogicException $exception) {
            $this->assertSame('AI model test snapshots cannot be serialized.', $exception->getMessage());
        }
    }

    public function test_role_downgrade_does_not_render_global_system_configuration_from_stale_session_data(): void
    {
        $admin = $this->admin('stale-system-panel-role', 'super_admin');
        $this->actingAs($admin, 'admin');
        Admin::query()->whereKey($admin->id)->update(['role' => 'admin']);

        $response = $this->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertDontSee('data-system-ai-configuration', false);

        $this->assertFalse((bool) $response->viewData('showSystemConfiguration'));
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_model_creation_rolls_back_when_the_initial_personal_default_cannot_be_saved(): void
    {
        $admin = $this->admin('atomic-model-owner', 'admin');
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_admin_ai_setting_insert
            BEFORE INSERT ON admin_ai_settings
            BEGIN
                SELECT RAISE(ABORT, 'forced personal default failure');
            END
        SQL);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload())
            ->assertServerError();

        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('admin_ai_settings', 0);
    }

    public function test_system_model_creation_rolls_back_when_global_default_initialization_fails(): void
    {
        $admin = $this->admin('atomic-system-create', 'super_admin');
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_default_embedding_insert
            BEFORE INSERT ON site_settings
            WHEN NEW.setting_key = 'default_embedding_model_id'
            BEGIN
                SELECT RAISE(ABORT, 'forced global default insert failure');
            END
        SQL);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'name' => 'Atomic system embedding',
                'model_id' => 'atomic-system-embedding',
                'model_type' => 'embedding',
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertServerError();

        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'default_embedding_model_id']);
    }

    public function test_system_model_update_rolls_back_when_global_default_cleanup_fails(): void
    {
        $admin = $this->admin('atomic-system-update', 'super_admin');
        $model = $this->model($admin, [
            'name' => 'Original system embedding',
            'model_type' => 'embedding',
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $original = $model->getRawOriginal();
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $model->id,
        ]);
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_default_embedding_update
            BEFORE UPDATE OF setting_value ON site_settings
            WHEN OLD.setting_key = 'default_embedding_model_id'
            BEGIN
                SELECT RAISE(ABORT, 'forced global default update failure');
            END
        SQL);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => 'Changed system chat',
                'api_key' => 'changed-system-key',
                'model_id' => 'changed-system-chat',
                'model_type' => 'chat',
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertServerError();

        $current = $model->fresh();
        foreach (['name', 'api_key', 'model_id', 'model_type', 'access_scope', 'status'] as $attribute) {
            $this->assertSame($original[$attribute], $current->getRawOriginal($attribute), $attribute);
        }
        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_system_model_delete_rolls_back_when_global_default_cleanup_fails(): void
    {
        $admin = $this->admin('atomic-system-delete', 'super_admin');
        $model = $this->model($admin, [
            'model_type' => 'embedding',
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $model->id,
        ]);
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_default_embedding_delete_cleanup
            BEFORE UPDATE OF setting_value ON site_settings
            WHEN OLD.setting_key = 'default_embedding_model_id'
            BEGIN
                SELECT RAISE(ABORT, 'forced global default delete cleanup failure');
            END
        SQL);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $model->id]))
            ->assertServerError();

        $this->assertModelExists($model);
        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_quality_invalidation_failure_keeps_the_committed_model_mutation(): void
    {
        $admin = $this->admin('quality-side-effect-failure', 'admin');
        $this->app->instance(ArticleAiQualityInvalidationService::class, new class extends ArticleAiQualityInvalidationService
        {
            public function invalidateModel(int $modelId, string $reason): int
            {
                throw new \RuntimeException('forced quality invalidation failure');
            }
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'name' => 'Committed despite quality side effect',
                'model_id' => 'committed-quality-side-effect',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertDatabaseHas('ai_models', [
            'owner_admin_id' => $admin->id,
            'name' => 'Committed despite quality side effect',
        ]);
    }

    public function test_model_configuration_routes_hide_models_owned_by_another_administrator(): void
    {
        Http::fake();
        $owner = $this->admin('owner', 'admin');
        $actor = $this->admin('actor', 'admin');
        $model = $this->model($owner);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.edit', ['modelId' => $model->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload())
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $model->id]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertModelExists($model);
    }

    public function test_ordinary_administrator_can_update_test_and_delete_a_personal_model(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);
        $actor = $this->admin('personal-owner', 'admin');
        $model = $this->model($actor);

        $this->actingAs($actor, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => 'Updated personal model',
                'model_id' => $model->model_id,
            ]))
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertSame('Updated personal model', $model->fresh()->name);

        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true);
        Http::assertSentCount(1);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $model->id]))
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertModelMissing($model);
    }

    public function test_ordinary_index_separates_personal_and_sanitized_shared_models_without_system_configuration(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $other = $this->admin('other', 'admin');
        $personal = $this->model($actor, ['name' => 'Personal model']);
        $shared = $this->model($provider, [
            'name' => 'Shared model',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('shared-secret-key'),
            'api_url' => 'https://private-shared.example.test',
            'model_id' => 'private-shared-model-id',
        ]);
        $this->model($provider, [
            'name' => 'System model',
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $this->model($other, ['name' => 'Other ordinary model']);

        $response = $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk();
        $viewData = $response->original->getData();
        $this->assertSame([$personal->id], collect($response->viewData('myModels'))->pluck('id')->all());
        $this->assertSame([$shared->id], collect($response->viewData('sharedModels'))->pluck('id')->all());
        $this->assertSame([], $response->viewData('governanceModels'));
        $this->assertFalse((bool) $response->viewData('showSystemConfiguration'));
        $this->assertFalse(array_key_exists('defaultEmbeddingModelId', $viewData));
        $this->assertFalse(array_key_exists('chunkingConfig', $viewData));
        $this->assertFalse(array_key_exists('pgvectorEnabled', $viewData));

        $sharedData = collect($response->viewData('sharedModels'))->sole();
        foreach (['api_key', 'api_url', 'model_id', 'custom_auth'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $sharedData);
        }
        $response
            ->assertSee(__('admin.ai_models.section_my_models'))
            ->assertSee(__('admin.ai_models.section_shared_models'))
            ->assertDontSee('data-system-ai-configuration', false)
            ->assertDontSee(__('admin.ai_models.chunking_title'))
            ->assertDontSee(__('admin.ai_models.vector_title'))
            ->assertDontSee('shared-secret-key', false)
            ->assertDontSee('private-shared.example.test', false)
            ->assertDontSee('private-shared-model-id', false)
            ->assertDontSee(route('admin.ai-models.edit', ['modelId' => $shared->id]), false)
            ->assertDontSee(route('admin.ai-models.test', ['modelId' => $shared->id]), false)
            ->assertDontSee(route('admin.ai-models.delete', ['modelId' => $shared->id]), false);

        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $shared->id]))
            ->assertNotFound();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_empty_shared_and_governance_sections_remain_visible_for_their_roles(): void
    {
        $ordinary = $this->admin('ordinary-empty', 'admin');

        $this->actingAs($ordinary, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee(__('admin.ai_models.section_shared_models'))
            ->assertSee(__('admin.ai_models.shared_models_empty'))
            ->assertDontSee(__('admin.ai_models.section_governance_models'));

        $super = $this->admin('super-empty', 'super_admin');

        $this->actingAs($super, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee(__('admin.ai_models.section_governance_models'))
            ->assertSee(__('admin.ai_models.governance_models_empty'))
            ->assertDontSee(__('admin.ai_models.section_shared_models'));
    }

    public function test_access_preview_reports_personal_first_order_and_inactive_shared_provider(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($actor, [
            'name' => 'Personal later priority',
            'failover_priority' => 900,
        ]);
        $shared = $this->model($provider, [
            'name' => 'Shared first priority',
            'failover_priority' => 1,
        ]);

        $response = $this->actingAs($actor, 'admin')->get(route('admin.ai-models.index'));
        $preview = $response->viewData('accessPreview');
        $candidateIds = collect($response->viewData('personalDefaultModelOptions'))->pluck('id')->all();

        $response->assertOk()->assertSee(__('admin.ai_models.preview_personal_first'));
        $this->assertSame('shared', $preview['mode']);
        $this->assertTrue($preview['provider_available']);
        $this->assertSame([$personal->id, $shared->id], $candidateIds);
        $this->assertFalse($preview['needs_repair']);

        $provider->forceFill(['status' => 'inactive'])->save();
        $response = $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee(__('admin.ai_models.preview_provider_inactive'));
        $preview = $response->viewData('accessPreview');
        $candidateIds = collect($response->viewData('personalDefaultModelOptions'))->pluck('id')->all();

        $this->assertFalse($preview['provider_available']);
        $this->assertSame([$personal->id], $candidateIds);
    }

    public function test_ordinary_index_does_not_query_global_site_settings(): void
    {
        $actor = $this->admin('ordinary-no-global-read', 'admin');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_strategy',
            'setting_value' => 'semantic_llm',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $siteSettingQueries = collect($queries)->filter(
            static fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'site_settings'),
        );
        $this->assertCount(0, $siteSettingQueries);
    }

    public function test_administrator_can_set_and_clear_compatible_personal_defaults_from_personal_and_shared_models(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personalChat = $this->model($actor, ['name' => 'Personal chat']);
        $sharedEmbedding = $this->model($provider, [
            'name' => 'Shared embedding',
            'model_type' => 'embedding',
            'model_id' => 'shared-embedding-v1',
        ]);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.personal-defaults'), [
                'default_chat_model_id' => $personalChat->id,
                'default_embedding_model_id' => $sharedEmbedding->id,
            ])
            ->assertRedirect(route('admin.ai-models.index'));

        $settings = AdminAiSetting::query()->where('admin_id', $actor->id)->sole();
        $this->assertSame($personalChat->id, $settings->default_chat_model_id);
        $this->assertSame($sharedEmbedding->id, $settings->default_embedding_model_id);

        $response = $this->actingAs($actor, 'admin')->get(route('admin.ai-models.index'));
        $this->assertSame(
            [$personalChat->id, $sharedEmbedding->id],
            collect($response->viewData('personalDefaultModelOptions'))->pluck('id')->all(),
        );
        $response
            ->assertSee(__('admin.ai_models.personal_defaults_title'))
            ->assertDontSee('shared-embedding-v1', false);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.personal-defaults'), [
                'default_chat_model_id' => 0,
                'default_embedding_model_id' => null,
            ])
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertNull($settings->fresh()->default_chat_model_id);
        $this->assertNull($settings->fresh()->default_embedding_model_id);
    }

    public function test_first_personal_models_become_personal_defaults_without_changing_global_settings(): void
    {
        $actor = $this->admin('actor', 'admin');
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => '8765',
        ]);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'name' => 'First chat',
                'model_id' => 'first-chat',
                'model_type' => 'chat',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));
        $chat = AiModel::query()->where('model_id', 'first-chat')->sole();

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.store'), $this->modelPayload([
                'name' => 'First embedding',
                'model_id' => 'first-embedding',
                'model_type' => 'embedding',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));
        $embedding = AiModel::query()->where('model_id', 'first-embedding')->sole();

        $settings = AdminAiSetting::query()->where('admin_id', $actor->id)->sole();
        $this->assertSame($chat->id, $settings->default_chat_model_id);
        $this->assertSame($embedding->id, $settings->default_embedding_model_id);
        $this->assertSame(
            '8765',
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_ordinary_model_updates_and_deletes_do_not_change_global_embedding_setting(): void
    {
        $actor = $this->admin('ordinary-global-boundary', 'admin');
        $model = $this->model($actor, [
            'name' => 'Ordinary embedding',
            'model_id' => 'ordinary-embedding',
            'model_type' => 'embedding',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $model->id,
        ]);

        $this->actingAs($actor, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->modelPayload([
                'name' => $model->name,
                'model_id' => $model->model_id,
                'model_type' => 'embedding',
                'status' => 'inactive',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $model->id]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame(
            (string) $model->id,
            SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'),
        );
    }

    public function test_disabling_or_deleting_a_model_clears_every_personal_default_that_references_it(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $chat = $this->model($provider, ['name' => 'Shared chat']);
        $embedding = $this->model($provider, [
            'name' => 'Shared embedding',
            'model_type' => 'embedding',
            'model_id' => 'shared-embedding',
        ]);
        app(AdminAiSettingsService::class)
            ->setDefaults($provider, $chat, $embedding, $provider);
        app(AdminAiSettingsService::class)
            ->setDefaults($consumer, $chat, $embedding, $consumer);

        $this->actingAs($provider, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $chat->id]), $this->modelPayload([
                'name' => $chat->name,
                'model_id' => $chat->model_id,
                'status' => 'inactive',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertDatabaseMissing('admin_ai_settings', [
            'default_chat_model_id' => $chat->id,
        ]);
        $this->assertSame(2, AdminAiSetting::query()->where('default_embedding_model_id', $embedding->id)->count());

        $this->actingAs($provider, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $embedding->id]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertDatabaseMissing('admin_ai_settings', [
            'default_embedding_model_id' => $embedding->id,
        ]);
    }

    public function test_access_scope_updates_are_super_admin_only_and_never_change_model_ownership(): void
    {
        $ordinary = $this->admin('ordinary', 'admin');
        $ordinaryModel = $this->model($ordinary);

        $this->actingAs($ordinary, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $ordinaryModel->id]), $this->modelPayload([
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
                'owner_admin_id' => 999,
            ]))
            ->assertSessionHasErrors(['access_scope', 'owner_admin_id']);

        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $ordinaryModel->fresh()->access_scope);
        $this->assertSame($ordinary->id, $ordinaryModel->fresh()->owner_admin_id);

        $super = $this->admin('super', 'super_admin');
        $superModel = $this->model($super);
        app(AdminAiSettingsService::class)
            ->setDefaults($super, $superModel, null, $super);

        $this->actingAs($super, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $superModel->id]), $this->modelPayload([
                'name' => $superModel->name,
                'model_id' => $superModel->model_id,
                'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $superModel->fresh()->access_scope);
        $this->assertSame($super->id, $superModel->fresh()->owner_admin_id);
        $this->assertNull($super->aiSettings->fresh()->default_chat_model_id);
    }

    public function test_super_admin_governance_index_is_sanitized_and_excludes_peer_super_admin_models(): void
    {
        Http::fake();
        $actor = $this->admin('actor-super', 'super_admin');
        $ordinary = $this->admin('ordinary', 'admin');
        $peer = $this->admin('peer-super', 'super_admin');
        $governed = $this->model($ordinary, [
            'name' => 'Governed ordinary model',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('governed-secret'),
            'api_url' => 'https://governed-private.example.test',
            'model_id' => 'governed-private-model-id',
        ]);
        $peerModel = $this->model($peer, ['name' => 'Peer model']);

        $response = $this->actingAs($actor, 'admin')->get(route('admin.ai-models.index'));

        $response->assertOk()->assertSee(__('admin.ai_models.section_governance_models'));
        $this->assertSame([$governed->id], collect($response->viewData('governanceModels'))->pluck('id')->all());
        $governanceData = collect($response->viewData('governanceModels'))->sole();
        $this->assertSame($ordinary->display_name, $governanceData['owner']['display_name']);
        foreach (['api_key', 'api_url', 'model_id', 'custom_auth'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $governanceData);
        }
        $response
            ->assertDontSee('governed-secret', false)
            ->assertDontSee('governed-private.example.test', false)
            ->assertDontSee('governed-private-model-id', false)
            ->assertDontSee($peerModel->name, false)
            ->assertDontSee(route('admin.ai-models.edit', ['modelId' => $governed->id]), false)
            ->assertDontSee(route('admin.ai-models.test', ['modelId' => $governed->id]), false)
            ->assertDontSee(route('admin.ai-models.delete', ['modelId' => $governed->id]), false);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.edit', ['modelId' => $governed->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $governed->id]), $this->modelPayload())
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $governed->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => $governed->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.edit', ['modelId' => $peerModel->id]))
            ->assertNotFound();
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $peerModel->id]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertModelExists($governed);
        $this->assertModelExists($peerModel);
    }

    public function test_personal_defaults_reject_unrelated_incompatible_and_system_only_models(): void
    {
        $actor = $this->admin('actor', 'admin');
        $other = $this->admin('other', 'admin');
        $unrelated = $this->model($other);
        $ownChat = $this->model($actor, ['model_id' => 'own-chat']);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.personal-defaults'), [
                'default_chat_model_id' => $unrelated->id,
                'default_embedding_model_id' => 0,
            ])
            ->assertSessionHasErrors('personal_defaults');
        $this->actingAs($actor, 'admin')
            ->post(route('admin.ai-models.personal-defaults'), [
                'default_chat_model_id' => 0,
                'default_embedding_model_id' => $ownChat->id,
            ])
            ->assertSessionHasErrors('personal_defaults');

        $super = $this->admin('super', 'super_admin');
        $systemModel = $this->model($super, [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ]);
        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.personal-defaults'), [
                'default_chat_model_id' => $systemModel->id,
                'default_embedding_model_id' => 0,
            ])
            ->assertSessionHasErrors('personal_defaults');

        $this->assertDatabaseCount('admin_ai_settings', 0);
    }

    public function test_ai_model_sharing_copy_is_available_in_all_admin_locales(): void
    {
        foreach (['zh_CN', 'en', 'pt_BR', 'es', 'ja', 'ru'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'section_my_models',
                'section_shared_models',
                'section_governance_models',
                'preview_personal_first',
                'personal_defaults_title',
                'field_access_scope',
                'error.system_scope_super_admin_only',
                'message.personal_defaults_updated',
            ] as $key) {
                $translationKey = 'admin.ai_models.'.$key;
                $this->assertNotSame($translationKey, __($translationKey), $locale.' '.$translationKey);
            }
        }
    }

    public function test_governance_index_query_count_stays_flat_as_ordinary_models_are_added(): void
    {
        $actor = $this->admin('actor-super', 'super_admin');
        $ordinary = $this->admin('ordinary-1', 'admin');
        $this->model($ordinary, ['name' => 'Initial governed model']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($actor, 'admin')->get(route('admin.ai-models.index'))->assertOk();
        $initialQueryCount = count(DB::getQueryLog());

        foreach (range(2, 12) as $index) {
            $owner = $this->admin('ordinary-'.$index, 'admin');
            $this->model($owner, ['name' => 'Governed model '.$index]);
        }

        DB::flushQueryLog();
        $this->actingAs($actor, 'admin')->get(route('admin.ai-models.index'))->assertOk();
        $expandedQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($initialQueryCount + 1, $expandedQueryCount);
    }

    public function test_governance_models_are_paginated_without_exposing_unsanitized_fields(): void
    {
        $actor = $this->admin('pagination-super', 'super_admin');
        $ordinary = $this->admin('pagination-ordinary', 'admin');
        foreach (range(1, 51) as $index) {
            $this->model($ordinary, ['name' => 'Governed model '.$index]);
        }

        $firstPage = $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee('governance_page=2', false);
        $this->assertCount(50, $firstPage->viewData('governanceModels'));

        $secondPage = $this->actingAs($actor, 'admin')
            ->get(route('admin.ai-models.index', ['governance_page' => 2]))
            ->assertOk();
        $this->assertCount(1, $secondPage->viewData('governanceModels'));
        foreach (['api_key', 'api_url', 'model_id', 'custom_auth'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $secondPage->viewData('governanceModels')[0]);
        }
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

    /** @param array<string, mixed> $overrides */
    private function modelPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Personal Chat',
            'version' => 'v1',
            'api_key' => 'test-api-key',
            'model_id' => 'personal-chat-v1',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'failover_priority' => 20,
            'daily_limit' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function model(Admin $owner, array $overrides = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => 'Owned Chat',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('owner-secret-key'),
            'model_id' => 'owned-chat-v1',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ], $overrides));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $overrides['access_scope'] ?? AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function installPostOutboundRevocationHook(
        string $mutation,
        Admin $admin,
        Admin $other,
        AiModel $model,
        string $rotatedKey,
    ): void {
        $this->app->instance(
            AdminAiModelTestBoundaryHook::class,
            new class($mutation, (int) $admin->id, (int) $other->id, (int) $model->id, $rotatedKey) extends AdminAiModelTestBoundaryHook
            {
                public function __construct(
                    private readonly string $mutation,
                    private readonly int $adminId,
                    private readonly int $otherAdminId,
                    private readonly int $modelId,
                    private readonly string $rotatedKey,
                ) {}

                public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void
                {
                    match ($this->mutation) {
                        'admin_inactive' => Admin::query()->whereKey($this->adminId)->update(['status' => 'inactive']),
                        'role' => Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']),
                        'access_version' => Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version'),
                        'owner' => AiModel::query()->whereKey($this->modelId)->update(['owner_admin_id' => $this->otherAdminId]),
                        'scope' => AiModel::query()->whereKey($this->modelId)->update(['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY]),
                        'api_key' => AiModel::query()->whereKey($this->modelId)->update(['api_key' => $this->rotatedKey]),
                        'api_url' => AiModel::query()->whereKey($this->modelId)->update(['api_url' => 'https://post-outbound-rotated.example.test']),
                        'model_id' => AiModel::query()->whereKey($this->modelId)->update(['model_id' => 'post-outbound-rotated-model']),
                        'status' => AiModel::query()->whereKey($this->modelId)->update(['status' => 'inactive']),
                        'archived' => AiModel::query()->whereKey($this->modelId)->update(['archived_at' => now()]),
                    };
                }
            },
        );
    }
}
