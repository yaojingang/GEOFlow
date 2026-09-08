<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Services\Admin\AdminAiModelTestBoundaryHook;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAiWorkspaceConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        config()->set('ai-workspace.require_verified_model', true);
        Http::preventStrayRequests();
    }

    public function test_homepage_offers_a_check_for_the_current_owned_model_without_calling_it(): void
    {
        $admin = $this->admin('home-check');
        $model = $this->model($admin);

        $this->actingAs($admin, 'admin')->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee('data-ai-connection-check', false)
            ->assertSee(route('admin.ai-models.test', ['modelId' => $model->id], false), false)
            ->assertSee($model->name)
            ->assertDontSee('homepage-test-secret')
            ->assertDontSee('https://ai.test');

        Http::assertNothingSent();
        self::assertNull($model->fresh()->ai_workspace_readiness_status);
    }

    #[DataProvider('roles')]
    public function test_homepage_check_enables_conversation_for_an_authorized_model_owner(string $role): void
    {
        $admin = $this->admin('check-'.$role, $role);
        $model = $this->model($admin);
        AdminHelpAssistant::fake(['连接可用。'])->preventStrayPrompts();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]), ['workspace_check' => true])
            ->assertOk()
            ->assertJsonPath('meta.workspace_ready', true);

        self::assertTrue(app(AiWorkspaceModelReadiness::class)->status($admin)['ready']);
        $this->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee('data-runtime-enabled="true"', false)
            ->assertDontSee('data-ai-connection-check', false);
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('legacyTypes')]
    public function test_legacy_chat_model_keeps_matching_readiness_after_homepage_check(?string $type): void
    {
        $admin = $this->admin('legacy');
        $model = $this->model($admin);
        AiModel::query()->whereKey($model->id)->update(['model_type' => $type]);
        AdminHelpAssistant::fake(['连接可用。'])->preventStrayPrompts();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]), ['workspace_check' => true])
            ->assertOk()
            ->assertJsonPath('meta.workspace_ready', true);
        self::assertTrue(app(AiWorkspaceModelReadiness::class)->status($admin)['ready']);
    }

    public static function legacyTypes(): array
    {
        return [[null], ['']];
    }

    public function test_default_model_change_during_check_returns_current_recovery_instead_of_false_success(): void
    {
        $admin = $this->admin('changed-default');
        $model = $this->model($admin);
        $nextModel = $this->model($admin);
        $nextModel->forceFill([
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_failure_code' => 'authentication_failed',
        ])->save();
        AdminAiSetting::query()->forceCreate(['admin_id' => $admin->id, 'default_chat_model_id' => $model->id]);
        AdminHelpAssistant::fake(['连接可用。'])->preventStrayPrompts();
        $this->app->instance(AdminAiModelTestBoundaryHook::class, new class($nextModel->id) extends AdminAiModelTestBoundaryHook
        {
            public function __construct(private readonly int $nextModelId) {}

            public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void
            {
                AdminAiSetting::query()->where('admin_id', $snapshot->adminId)
                    ->update(['default_chat_model_id' => $this->nextModelId]);
            }
        });

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]), ['workspace_check' => true])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.workspace_ready', false)
            ->assertJsonPath('meta.workspace_connection.test_url', route('admin.ai-models.test', ['modelId' => $nextModel->id], false));
        self::assertSame('ready', $model->fresh()->ai_workspace_readiness_status);
        self::assertFalse(app(AiWorkspaceModelReadiness::class)->status($admin)['ready']);
    }

    public function test_runtime_disabled_does_not_offer_a_model_check(): void
    {
        $admin = $this->admin('disabled');
        $this->model($admin);
        config()->set('ai-workspace.runtime_enabled', false);

        $this->actingAs($admin, 'admin')->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee(__('admin.ai_workspace.connection_runtime_disabled'))
            ->assertDontSee('data-ai-connection-check', false);
    }

    public function test_no_model_offers_configuration_instead_of_a_check(): void
    {
        $this->actingAs($this->admin('empty'), 'admin')->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee(__('admin.ai_workspace.connection_no_model'))
            ->assertSee(route('admin.ai-models.index', [], false), false)
            ->assertDontSee('data-ai-connection-check', false);
    }

    public function test_shared_model_requires_its_owner_to_check_and_rejects_direct_test(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $model = $this->model($provider);
        $admin = $this->admin('shared');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();

        $this->actingAs($admin, 'admin')->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee(__('admin.ai_workspace.connection_shared_model'))
            ->assertDontSee('data-ai-connection-check', false);
        $this->postJson(route('admin.ai-models.test', ['modelId' => $model->id]), ['workspace_check' => true])
            ->assertNotFound();

        Http::assertNothingSent();
        self::assertNull($model->fresh()->ai_workspace_readiness_status);
    }

    #[DataProvider('revocations')]
    public function test_personal_workspace_check_discards_results_after_access_or_configuration_changes(string $mutation): void
    {
        $admin = $this->admin('revoked');
        $model = $this->model($admin);
        AdminHelpAssistant::fake(['连接可用。'])->preventStrayPrompts();
        $this->app->instance(AdminAiModelTestBoundaryHook::class, new class($mutation) extends AdminAiModelTestBoundaryHook
        {
            public function __construct(private readonly string $mutation) {}

            public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void
            {
                match ($this->mutation) {
                    'role' => Admin::query()->whereKey($snapshot->adminId)->update(['role' => 'super_admin']),
                    'version' => Admin::query()->whereKey($snapshot->adminId)->increment('ai_config_access_version'),
                    'configuration' => AiModel::query()->whereKey($snapshot->modelId)->update(['model_id' => 'changed']),
                };
            }
        });

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]), ['workspace_check' => true])
            ->assertUnprocessable()
            ->assertJsonPath('meta.diagnosis.code', 'ai_config_access_revoked');
        self::assertNull($model->fresh()->ai_workspace_readiness_status);
    }

    public static function revocations(): array
    {
        return [['role'], ['version'], ['configuration']];
    }

    private function admin(string $username, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username, 'password' => 'secret-123',
            'email' => $username.'@example.com', 'role' => $role, 'status' => 'active',
        ]);
    }

    private function model(Admin $owner): AiModel
    {
        $model = new AiModel([
            'name' => 'Homepage Chat', 'version' => 'test', 'model_id' => 'homepage-chat',
            'model_type' => 'chat', 'api_url' => 'https://ai.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('homepage-test-secret'),
            'status' => 'active', 'daily_limit' => 0,
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }
}
