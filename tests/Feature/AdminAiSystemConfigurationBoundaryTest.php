<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Data\Admin\AdminAiSourceProviderTestSnapshot;
use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiModelTestPreparationService;
use App\Services\Admin\AdminAiSourceProviderService;
use App\Services\Admin\AdminAiSystemConfigurationBoundaryHook;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Support\GeoFlow\ApiKeyCrypto;
use ArgumentCountError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use TypeError;

class AdminAiSystemConfigurationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_ai_source_provider_route_requires_super_admin_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.ai-source-providers.'));

        $this->assertCount(10, $routes);
        foreach ($routes as $route) {
            $this->assertContains('admin.super', $route->gatherMiddleware(), (string) $route->getName());
        }
    }

    #[Test]
    public function ordinary_admin_is_forbidden_from_the_entire_ai_source_provider_route_matrix(): void
    {
        $admin = $this->admin('admin');
        $provider = $this->provider();

        foreach ([
            ['get', route('admin.ai-source-providers.index'), []],
            ['get', route('admin.ai-source-providers.create'), []],
            ['post', route('admin.ai-source-providers.store'), []],
            ['get', route('admin.ai-source-providers.edit', ['providerId' => $provider->id]), []],
            ['put', route('admin.ai-source-providers.update', ['providerId' => $provider->id]), []],
            ['post', route('admin.ai-source-providers.test', ['providerId' => $provider->id]), []],
            ['post', route('admin.ai-source-providers.delete', ['providerId' => $provider->id]), []],
            ['post', route('admin.ai-source-providers.model-bindings'), []],
            ['post', route('admin.ai-source-providers.model-bindings.upsert-api'), []],
            ['post', route('admin.ai-source-providers.model-bindings.test'), []],
        ] as [$method, $url, $payload]) {
            $this->actingAs($admin, 'admin')->{$method}($url, $payload)->assertForbidden();
        }
    }

    #[Test]
    public function ordinary_admin_ai_pages_do_not_render_system_collection_navigation(): void
    {
        config(['geoflow.admin_ui_v3_enabled' => true]);
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai.configurator'))
            ->assertOk()
            ->assertDontSee(route('admin.ai-source-providers.index'), false)
            ->assertDontSee(__('admin.ai_configurator.search_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertDontSee(route('admin.ai-source-providers.index'), false)
            ->assertDontSee(__('admin.ai_configurator.search_title'));
    }

    #[Test]
    public function quick_model_configuration_creates_an_owned_system_only_model_and_binding_atomically(): void
    {
        $super = $this->admin('super_admin');

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'deepseek',
                'name' => 'System DeepSeek',
                'model_id' => 'deepseek-chat',
                'api_url' => 'https://api.deepseek.com',
                'api_key' => 'system-only-secret',
                'daily_limit' => 8,
            ])
            ->assertRedirect(route('admin.ai-source-providers.index'));

        $model = AiModel::query()->firstOrFail();
        $this->assertSame((int) $super->id, (int) $model->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $model->access_scope);
        $this->assertSame(
            (string) $model->id,
            (string) SiteSetting::query()->where('setting_key', AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY)->value('setting_value'),
        );
    }

    #[Test]
    public function system_binding_rejects_models_outside_the_current_super_admin_system_pool(): void
    {
        $super = $this->admin('super_admin');
        $peer = $this->admin('super_admin', 'peer');
        $ordinary = $this->admin('admin', 'ordinary');
        $candidates = [
            $this->model($super, ['access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT]),
            $this->model($peer),
            $this->model($ordinary),
            $this->model($super, ['owner_admin_id' => null]),
            $this->model($super, ['status' => 'inactive']),
            $this->model($super, ['archived_at' => now()]),
            $this->model($super, ['model_type' => 'embedding']),
            $this->model($super, ['model_id' => '']),
        ];

        foreach ($candidates as $model) {
            $this->actingAs($super, 'admin')
                ->from(route('admin.ai-source-providers.index'))
                ->post(route('admin.ai-source-providers.model-bindings'), [
                    'ark_model_id' => 0,
                    'deepseek_model_id' => (int) $model->id,
                ])
                ->assertRedirect(route('admin.ai-source-providers.index'))
                ->assertSessionHasErrors();

            $this->assertNull(SiteSetting::query()
                ->where('setting_key', AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY)
                ->first());
        }
    }

    #[Test]
    public function system_binding_test_rejects_forged_and_provider_incompatible_models_without_outbound_calls(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        AdminHelpAssistant::fake()->preventStrayPrompts();
        $super = $this->admin('super_admin');
        $peer = $this->admin('super_admin', 'peer');
        $ordinary = $this->admin('admin', 'ordinary');

        foreach ([
            $this->model($super, ['access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT]),
            $this->model($peer),
            $this->model($ordinary),
            $this->model($super, ['owner_admin_id' => null]),
            $this->model($super, ['status' => 'inactive']),
            $this->model($super, ['archived_at' => now()]),
            $this->model($super, ['model_type' => 'embedding']),
            $this->model($super, ['model_id' => '']),
        ] as $model) {
            $this->actingAs($super, 'admin')
                ->postJson(route('admin.ai-source-providers.model-bindings.test'), [
                    'binding_type' => 'deepseek',
                    'model_id' => (int) $model->id,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error_code', 'ai_model_unavailable');

            $this->assertSame(0, (int) $model->fresh()->used_today);
        }

        $arkModel = $this->model($super, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->actingAs($super, 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), [
                'binding_type' => 'deepseek',
                'model_id' => (int) $arkModel->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ai_model_unavailable');

        $this->assertSame(0, (int) $arkModel->fresh()->used_today);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        AdminHelpAssistant::assertNeverPrompted();
    }

    #[Test]
    public function visibility_configuration_rejects_invalid_owner_scope_and_lifecycle_state(): void
    {
        $super = $this->admin('super_admin');
        $ordinary = $this->admin('admin', 'ordinary');
        $resolver = app(AiVisibilityConfigurationResolver::class);
        $identity = SystemAiIdentity::forVisibilityCollection();

        foreach ([
            $this->model($ordinary),
            $this->model($super, ['access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT]),
            $this->model($super, ['status' => 'inactive']),
            $this->model($super, ['archived_at' => now()]),
            $this->model($super, ['model_id' => '']),
        ] as $model) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY],
                ['setting_value' => (string) $model->id],
            );

            $this->assertNull($resolver->deepSeekModel($identity));
            $this->assertSame('ai_model_not_accessible', $resolver->modelResolution($identity, 'deepseek')['reason']);
        }
    }

    #[Test]
    public function visibility_system_resolution_requires_an_explicit_typed_identity(): void
    {
        $resolver = app(AiVisibilityConfigurationResolver::class);
        $identityType = new ReflectionClass(SystemAiIdentity::class);

        $this->assertTrue($identityType->isFinal());
        $this->assertTrue($identityType->getConstructor()?->isPrivate());

        foreach (['searchProvider', 'arkModel', 'deepSeekModel', 'status'] as $method) {
            $this->assertGreaterThanOrEqual(
                1,
                (new ReflectionMethod($resolver, $method))->getNumberOfRequiredParameters(),
                $method,
            );
        }

        try {
            $resolver->searchProvider();
            $this->fail('System configuration lookup must require an explicit identity.');
        } catch (ArgumentCountError) {
            $this->addToAssertionCount(1);
        }

        try {
            $resolver->deepSeekModel($this->admin('admin', 'cannot-forge-system'));
            $this->fail('An administrator identity must not satisfy the system identity contract.');
        } catch (TypeError) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function source_provider_page_only_lists_compatible_system_models_owned_by_current_super_admin(): void
    {
        $super = $this->admin('super_admin');
        $peer = $this->admin('super_admin', 'peer');
        $ordinary = $this->admin('admin', 'ordinary');
        $deepSeek = $this->model($super, ['name' => 'Current DeepSeek System Model']);
        $ark = $this->model($super, [
            'name' => 'Current Ark System Model',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->model($super, [
            'name' => 'Incompatible System Model',
            'api_url' => 'https://api.openai.com/v1',
        ]);
        $this->model($super, [
            'name' => 'Missing Credential System Model',
            'api_key' => '',
        ]);
        $this->model($super, [
            'name' => 'Missing Provider Model ID',
            'model_id' => '',
        ]);
        $this->model($peer, ['name' => 'Peer Super Secret Model']);
        $this->model($ordinary, ['name' => 'Ordinary Secret Model']);
        $this->model($super, [
            'name' => 'Personal User Content Model',
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ]);

        $response = $this->actingAs($super, 'admin')
            ->get(route('admin.ai-source-providers.index'))
            ->assertOk()
            ->assertSee('Current DeepSeek System Model')
            ->assertSee('Current Ark System Model')
            ->assertDontSee('Incompatible System Model')
            ->assertDontSee('Missing Credential System Model')
            ->assertDontSee('Missing Provider Model ID')
            ->assertDontSee('Peer Super Secret Model')
            ->assertDontSee('Ordinary Secret Model')
            ->assertDontSee('Personal User Content Model');

        $this->assertSame([$ark->id], collect($response->viewData('arkModels'))->pluck('id')->all());
        $this->assertSame([$deepSeek->id], collect($response->viewData('deepSeekModels'))->pluck('id')->all());
    }

    #[Test]
    public function quick_model_update_cannot_take_over_an_existing_foreign_or_user_content_binding(): void
    {
        $super = $this->admin('super_admin');
        $peer = $this->admin('super_admin', 'peer');

        foreach ([
            $this->model($peer, ['name' => 'Peer Model']),
            $this->model($super, [
                'name' => 'Personal Model',
                'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ]),
        ] as $model) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY],
                ['setting_value' => (string) $model->id],
            );

            $this->actingAs($super, 'admin')
                ->from(route('admin.ai-source-providers.index'))
                ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                    'binding_type' => 'deepseek',
                    'name' => 'Attempted Takeover',
                    'model_id' => 'deepseek-chat',
                    'api_url' => 'https://api.deepseek.com',
                    'api_key' => 'takeover-secret',
                ])
                ->assertRedirect(route('admin.ai-source-providers.index'))
                ->assertSessionHasErrors();

            $this->assertSame((string) $model->name, (string) $model->fresh()->name);
        }
    }

    #[Test]
    public function rotating_bound_model_credentials_preserves_ownership_and_clears_readiness(): void
    {
        $super = $this->admin('super_admin');
        $model = $this->model($super, [
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => ['sentinel' => 'remove'],
            'ai_workspace_readiness_checked_at' => now(),
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);
        SiteSetting::query()->create([
            'setting_key' => AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY,
            'setting_value' => (string) $model->id,
        ]);

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'deepseek',
                'name' => 'Rotated System Model',
                'model_id' => 'deepseek-chat',
                'api_url' => 'https://api.deepseek.com',
                'api_key' => 'rotated-system-secret',
            ])
            ->assertRedirect(route('admin.ai-source-providers.index'));

        $model->refresh();
        $this->assertSame((int) $super->id, (int) $model->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $model->access_scope);
        $this->assertSame('stale', $model->ai_workspace_readiness_status);
        $this->assertNull($model->ai_workspace_readiness_profile);
        $this->assertNull($model->ai_workspace_readiness_checked_at);
        $this->assertNull($model->ai_workspace_readiness_expires_at);
    }

    #[Test]
    public function role_change_after_request_authorization_prevents_provider_creation(): void
    {
        $super = $this->admin('super_admin');
        $this->app->instance(
            AdminAiSystemConfigurationBoundaryHook::class,
            new class((int) $super->id) extends AdminAiSystemConfigurationBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function beforeMutation(Admin $actor): void
                {
                    Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']);
                }
            },
        );

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-source-providers.store'), [
                'name' => 'Must Not Persist',
                'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
                'api_key' => 'must-not-persist',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ai_source_providers', 0);
    }

    #[Test]
    public function provider_test_releases_reservation_when_configuration_changes_before_outbound(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $rotatedSecret = app(ApiKeyCrypto::class)->encrypt('rotated-provider-secret');
        $this->app->instance(
            AdminAiSystemConfigurationBoundaryHook::class,
            new class((int) $provider->id, $rotatedSecret) extends AdminAiSystemConfigurationBoundaryHook
            {
                public function __construct(private readonly int $providerId, private readonly string $secret) {}

                public function beforeProviderOutbound(AdminAiSourceProviderTestSnapshot $snapshot): void
                {
                    AiSourceProvider::query()->whereKey($this->providerId)->update(['api_key' => $this->secret]);
                }
            },
        );

        $response = $this->actingAs($super, 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => $provider->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ai_config_access_revoked');

        Http::assertNothingSent();
        $this->assertSame(0, (int) $provider->fresh()->used_today);
        $response
            ->assertDontSee('provider-secret', false)
            ->assertDontSee('rotated-provider-secret', false);
    }

    #[Test]
    public function provider_test_releases_reservation_when_super_admin_access_is_revoked_before_outbound(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $this->app->instance(
            AdminAiSystemConfigurationBoundaryHook::class,
            new class((int) $super->id) extends AdminAiSystemConfigurationBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function beforeProviderOutbound(AdminAiSourceProviderTestSnapshot $snapshot): void
                {
                    Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']);
                }
            },
        );

        $this->actingAs($super, 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => $provider->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ai_config_access_revoked');

        Http::assertNothingSent();
        $provider->refresh();
        $this->assertSame(0, (int) $provider->used_today);
        $this->assertSame(0, (int) $provider->total_used);
    }

    #[Test]
    public function provider_test_discards_result_and_counts_attempt_when_role_changes_after_outbound(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search?trace=sensitive' => Http::response([
                'LogId' => 'sensitive-provider-body',
                'Result' => ['WebResults' => []],
            ]),
        ]);
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $provider->forceFill(['endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search?trace=sensitive'])->save();
        $this->app->instance(
            AdminAiSystemConfigurationBoundaryHook::class,
            new class((int) $super->id) extends AdminAiSystemConfigurationBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function afterProviderOutbound(AdminAiSourceProviderTestSnapshot $snapshot): void
                {
                    Admin::query()->whereKey($this->adminId)->update(['role' => 'admin']);
                }
            },
        );

        $response = $this->actingAs($super, 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => $provider->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ai_config_access_revoked');

        $provider->refresh();
        $this->assertSame(1, (int) $provider->used_today);
        $this->assertSame(0, (int) $provider->total_used);
        $response
            ->assertDontSee('sensitive-provider-body', false)
            ->assertDontSee('trace=sensitive', false)
            ->assertDontSee('provider-secret', false);
    }

    #[Test]
    public function quick_model_configuration_rolls_back_model_and_binding_together(): void
    {
        $super = $this->admin('super_admin');
        $this->app->instance(
            AdminAiSystemConfigurationBoundaryHook::class,
            new class extends AdminAiSystemConfigurationBoundaryHook
            {
                public function afterModelMutationBeforeBinding(Admin $actor): void
                {
                    throw new \RuntimeException('simulated binding failure');
                }
            },
        );

        try {
            app(AdminAiSourceProviderService::class)->upsertModelApi($super, [
                'binding_type' => 'deepseek',
                'name' => 'Rollback Model',
                'model_id' => 'deepseek-chat',
                'api_url' => 'https://api.deepseek.com',
                'api_key' => 'rollback-secret',
            ]);
            $this->fail('The simulated failure must escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated binding failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('ai_models', 0);
        $this->assertNull(SiteSetting::query()
            ->where('setting_key', AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY)
            ->first());
    }

    #[Test]
    public function provider_test_snapshot_cannot_serialize_or_disclose_credentials(): void
    {
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $snapshot = app(AdminAiSourceProviderService::class)->prepareProviderTest($super, (int) $provider->id);

        $this->assertStringNotContainsString('provider-secret', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('provider-secret', print_r($snapshot, true));
        try {
            serialize($snapshot);
            $this->fail('Provider test snapshots must reject serialization.');
        } catch (\LogicException $exception) {
            $this->assertSame('AI source provider test snapshots cannot be serialized.', $exception->getMessage());
        }

        $this->assertNotNull($snapshot->reservation);
        app(AiUsageQuotaService::class)->releaseProvider($snapshot->reservation);
    }

    #[Test]
    public function provider_snapshot_survives_quota_accounting_and_revokes_on_request_configuration_change(): void
    {
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $provider->forceFill([
            'daily_limit' => 20,
            'metadata_json' => [
                'count' => 8,
                'search_type' => 'web',
                'need_summary' => true,
                'need_content' => true,
                'need_url' => true,
                'content_formats' => 'Markdown',
                'sites' => [],
                'block_hosts' => [],
            ],
        ])->save();
        $service = app(AdminAiSourceProviderService::class);
        $quota = app(AiUsageQuotaService::class);
        $snapshot = $service->prepareProviderTest($super, (int) $provider->id);

        $this->assertNotNull($snapshot->reservation);
        $quota->recordProviderSuccess($snapshot->reservation);
        $concurrentReservation = $quota->reserveProvider($provider->fresh());
        $this->assertNotNull($concurrentReservation);

        AiSourceProvider::query()->whereKey($provider->id)->update([
            'metadata_json' => json_encode([
                'count' => 8,
                'search_type' => 'web',
                'need_summary' => true,
                'need_content' => true,
                'need_url' => true,
                'content_formats' => 'Markdown',
                'sites' => [],
                'block_hosts' => [],
                'display_note' => 'does not affect requests',
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now()->addMinute(),
        ]);

        $service->revalidateProviderBeforeOutbound($snapshot);
        $service->revalidateProviderAfterOutbound($snapshot);
        $this->addToAssertionCount(2);

        $quota->releaseProvider($concurrentReservation);
        AiSourceProvider::query()->whereKey($provider->id)->update([
            'daily_limit' => 21,
            'updated_at' => now()->addMinutes(2),
        ]);

        $this->expectException(AiModelAccessException::class);
        $service->revalidateProviderBeforeOutbound($snapshot);
    }

    #[Test]
    public function provider_snapshot_revokes_for_every_connection_and_request_option_change(): void
    {
        $super = $this->admin('super_admin');
        $service = app(AdminAiSourceProviderService::class);
        $quota = app(AiUsageQuotaService::class);
        $mutations = [
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search?revision=2',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('rotated-provider-key'),
            'status' => 'inactive',
            'daily_limit' => 13,
            'metadata_json' => json_encode(['count' => 17], JSON_THROW_ON_ERROR),
        ];

        foreach ($mutations as $field => $value) {
            $provider = $this->provider();
            $snapshot = $service->prepareProviderTest($super, (int) $provider->id);
            $this->assertNotNull($snapshot->reservation);
            AiSourceProvider::query()->whereKey($provider->id)->update([$field => $value]);

            try {
                $service->revalidateProviderBeforeOutbound($snapshot);
                $this->fail($field.' must revoke the provider test snapshot.');
            } catch (AiModelAccessException $exception) {
                $this->assertSame('ai_config_access_revoked', $exception->getErrorCode(), $field);
            } finally {
                $quota->releaseProvider($snapshot->reservation);
            }
        }
    }

    #[Test]
    public function provider_test_rejects_a_corrupted_key_before_quota_or_http_on_every_attempt(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        $super = $this->admin('super_admin');
        $provider = $this->provider();
        $provider->setRawAttributes(array_replace($provider->getAttributes(), [
            'api_key' => 'enc:v1:corrupted-provider-key',
        ]));
        $provider->save();

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($super, 'admin')
                ->postJson(route('admin.ai-source-providers.test', ['providerId' => $provider->id]))
                ->assertUnprocessable()
                ->assertJsonPath('error_code', 'ai_model_unavailable')
                ->assertDontSee('corrupted-provider-key', false);
        }

        Http::assertNothingSent();
        $provider->refresh();
        $this->assertSame(0, (int) $provider->used_today);
        $this->assertSame(0, (int) $provider->total_used);
    }

    #[Test]
    public function ordinary_and_system_model_snapshots_ignore_usage_and_readiness_accounting(): void
    {
        $ordinary = $this->admin('admin', 'model-owner');
        $super = $this->admin('super_admin', 'system-owner');
        $ordinaryModel = $this->model($ordinary, [
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'daily_limit' => 20,
            'max_tokens' => 1024,
        ]);
        $systemModel = $this->model($super, [
            'daily_limit' => 20,
            'max_tokens' => 2048,
        ]);
        $preparation = app(AdminAiModelTestPreparationService::class);
        $quota = app(AiUsageQuotaService::class);

        $ordinarySnapshot = $preparation->prepare($ordinary, (int) $ordinaryModel->id);
        $this->assertNotNull($ordinarySnapshot->reservation);
        $quota->recordModelSuccess($ordinarySnapshot->reservation);
        $ordinaryConcurrent = $quota->reserveModelForTest($ordinaryModel->fresh());
        $this->assertNotNull($ordinaryConcurrent);
        AiModel::query()->whereKey($ordinaryModel->id)->update([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => json_encode(['plain_text' => ['status' => 'ready']], JSON_THROW_ON_ERROR),
            'ai_workspace_readiness_checked_at' => now(),
            'updated_at' => now()->addMinute(),
        ]);
        $this->assertFalse($preparation->revalidateImmediatelyBeforeOutbound($ordinarySnapshot));
        $this->assertFalse($preparation->revalidateAfterOutbound($ordinarySnapshot));
        $quota->releaseModel($ordinaryConcurrent);

        $systemSnapshot = $preparation->prepareSystemBinding($super, (int) $systemModel->id, 'deepseek');
        $this->assertNotNull($systemSnapshot->reservation);
        $quota->recordModelSuccess($systemSnapshot->reservation);
        $systemConcurrent = $quota->reserveModelForTest($systemModel->fresh());
        $this->assertNotNull($systemConcurrent);
        AiModel::query()->whereKey($systemModel->id)->update([
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_failure_code' => 'provider_timeout',
            'updated_at' => now()->addMinute(),
        ]);
        $this->assertTrue($preparation->revalidateImmediatelyBeforeOutbound($systemSnapshot));
        $preparation->revalidateWorkspaceAfterOutbound($systemSnapshot);
        $this->addToAssertionCount(1);
        $quota->releaseModel($systemConcurrent);

        AiModel::query()->whereKey($ordinaryModel->id)->update(['max_tokens' => 4096]);
        try {
            $preparation->revalidateImmediatelyBeforeOutbound($ordinarySnapshot);
            $this->fail('A request-affecting model change must revoke the snapshot.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        AiModel::query()->whereKey($systemModel->id)->update(['daily_limit' => 21]);
        try {
            $preparation->revalidateImmediatelyBeforeOutbound($systemSnapshot);
            $this->fail('A system model authorization change must revoke the snapshot.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }
    }

    #[Test]
    public function ai_configurator_statistics_are_isolated_by_current_model_access_and_ownership(): void
    {
        config(['geoflow.admin_ui_v3_enabled' => true]);
        $sharingSuper = $this->admin('super_admin', 'sharing-super');
        $ordinary = $this->admin('admin', 'stats-owner');
        $peerOrdinary = $this->admin('admin', 'stats-peer');
        $peerSuper = $this->admin('super_admin', 'stats-peer-super');
        $ordinary->forceFill([
            'shared_ai_config_owner_id' => $sharingSuper->id,
            'ai_config_access_version' => 7,
        ])->save();
        $provider = $this->provider();
        AiVisibilityRun::query()->create([
            'keyword' => 'isolated-statistics',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'ai_source_provider_id' => $provider->id,
            'status' => AiVisibilityRun::STATUS_FAILED,
        ]);

        $this->model($ordinary, [
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'name' => 'Ordinary Own Model',
            'used_today' => 2,
            'usage_date' => now()->toDateString(),
            'total_used' => 5,
        ]);
        $this->model($sharingSuper, [
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'name' => 'Shared Model',
            'used_today' => 30,
            'usage_date' => now()->toDateString(),
            'total_used' => 300,
        ]);
        $peerModel = $this->model($peerOrdinary, [
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'used_today' => 40,
            'usage_date' => now()->toDateString(),
            'total_used' => 400,
        ]);
        $this->model($peerSuper, [
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'used_today' => 50,
            'usage_date' => now()->toDateString(),
            'total_used' => 500,
        ]);

        $ordinaryResponse = $this->actingAs($ordinary, 'admin')
            ->get(route('admin.ai.configurator'))
            ->assertOk();
        $ordinaryStats = $ordinaryResponse->viewData('stats');
        $this->assertSame(1, $ordinaryStats['model_count']);
        $this->assertSame(5, $ordinaryStats['total_usage']);
        $this->assertSame(2, $ordinaryStats['today_usage']);
        $this->assertSame(0, $ordinaryStats['search_provider_count']);
        $this->assertSame(0, $ordinaryStats['visibility_failed_runs']);

        $peerModel->forceFill(['used_today' => 999, 'total_used' => 9999])->save();
        $ordinaryResponseAfterPeerChange = $this->actingAs($ordinary, 'admin')
            ->get(route('admin.ai.configurator'))
            ->assertOk();
        $ordinaryStatsAfterPeerChange = $ordinaryResponseAfterPeerChange->viewData('stats');
        $this->assertSame($ordinaryStats, $ordinaryStatsAfterPeerChange);
        $this->assertSame(
            $this->aiConfiguratorOverview((string) $ordinaryResponse->getContent()),
            $this->aiConfiguratorOverview((string) $ordinaryResponseAfterPeerChange->getContent()),
        );

        $superStats = $this->actingAs($sharingSuper, 'admin')
            ->get(route('admin.ai.configurator'))
            ->assertOk()
            ->viewData('stats');
        $this->assertSame(1, $superStats['model_count']);
        $this->assertSame(300, $superStats['total_usage']);
        $this->assertSame(30, $superStats['today_usage']);
        $this->assertSame(1, $superStats['search_provider_count']);
        $this->assertSame(1, $superStats['visibility_failed_runs']);
    }

    #[Test]
    public function provider_validation_does_not_flash_or_render_the_submitted_api_key(): void
    {
        $super = $this->admin('super_admin');
        $secret = 'validation-secret-must-stay-hidden';

        $response = $this->actingAs($super, 'admin')
            ->from(route('admin.ai-source-providers.create'))
            ->post(route('admin.ai-source-providers.store'), [
                'name' => '',
                'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
                'api_key' => $secret,
            ])
            ->assertRedirect(route('admin.ai-source-providers.create'))
            ->assertSessionHasErrors('name')
            ->assertDontSee($secret, false);

        $oldInput = session()->getOldInput();
        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('api_key', $oldInput);
    }

    private function admin(string $role, string $suffix = 'actor'): Admin
    {
        return Admin::query()->create([
            'username' => 'system_'.$role.'_'.$suffix,
            'password' => 'secret-123',
            'email' => 'system-'.$role.'-'.$suffix.'@example.com',
            'display_name' => 'System '.$role,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function aiConfiguratorOverview(string $html): string
    {
        $matched = preg_match(
            '/<section[^>]*data-ai-configurator-overview[^>]*>.*?<\/section>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched);

        return (string) ($matches[0] ?? '');
    }

    private function provider(): AiSourceProvider
    {
        return AiSourceProvider::query()->create([
            'name' => 'System Search',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('provider-secret'),
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function model(Admin $owner, array $overrides = []): AiModel
    {
        $model = new AiModel;
        $model->forceFill(array_merge([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'name' => 'System Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('model-secret'),
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
        $model->save();

        return $model;
    }
}
