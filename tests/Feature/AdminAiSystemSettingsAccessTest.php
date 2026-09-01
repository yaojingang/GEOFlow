<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiSystemSettingsService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminAiSystemSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_admin_cannot_write_system_ai_settings(): void
    {
        $ordinary = $this->admin('ordinary-system-forbidden', 'admin');

        $this->actingAs($ordinary, 'admin')
            ->post(route('admin.ai-models.default-embedding'), [
                'default_embedding_model_id' => 0,
            ])
            ->assertForbidden();
        $this->actingAs($ordinary, 'admin')
            ->post(route('admin.ai-models.chunking-config'), [
                'knowledge_chunk_strategy' => 'rule',
                'knowledge_chunking_model_id' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'default_embedding_model_id']);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'knowledge_chunk_strategy']);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'knowledge_chunking_model_id']);
    }

    public function test_super_admin_can_select_only_owned_system_models_for_global_settings(): void
    {
        $super = $this->admin('system-settings-owner', 'super_admin');
        $otherSuper = $this->admin('other-system-owner', 'super_admin');
        $ordinary = $this->admin('private-owner', 'admin');
        $embedding = $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $chat = $this->model($super, 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $wrongOwner = $this->model($otherSuper, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $private = $this->model($ordinary, 'embedding', AiModel::ACCESS_SCOPE_USER_CONTENT);
        $userContent = $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_USER_CONTENT);
        $inactiveEmbedding = $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['status' => 'inactive']);
        $archivedEmbedding = $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['archived_at' => now()]);
        $inactiveChat = $this->model($super, 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['status' => 'inactive']);
        $archivedChat = $this->model($super, 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['archived_at' => now()]);

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.default-embedding'), [
                'default_embedding_model_id' => $embedding->id,
            ])
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertSetting('default_embedding_model_id', (string) $embedding->id);

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.chunking-config'), [
                'knowledge_chunk_strategy' => 'semantic_llm',
                'knowledge_chunking_model_id' => $chat->id,
            ])
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertSetting('knowledge_chunk_strategy', 'semantic_llm');
        $this->assertSetting('knowledge_chunking_model_id', (string) $chat->id);

        foreach ([$wrongOwner, $private, $userContent, $chat, $inactiveEmbedding, $archivedEmbedding] as $forged) {
            $this->actingAs($super, 'admin')
                ->post(route('admin.ai-models.default-embedding'), [
                    'default_embedding_model_id' => $forged->id,
                ])
                ->assertSessionHasErrors();
            $this->assertSetting('default_embedding_model_id', (string) $embedding->id);
        }

        foreach ([$wrongOwner, $private, $userContent, $embedding, $inactiveChat, $archivedChat] as $forged) {
            $this->actingAs($super, 'admin')
                ->post(route('admin.ai-models.chunking-config'), [
                    'knowledge_chunk_strategy' => 'semantic_llm',
                    'knowledge_chunking_model_id' => $forged->id,
                ])
                ->assertSessionHasErrors();
            $this->assertSetting('knowledge_chunking_model_id', (string) $chat->id);
        }
    }

    public function test_super_admin_can_clear_system_ai_settings(): void
    {
        $super = $this->admin('system-settings-clear', 'super_admin');
        SiteSetting::query()->insert([
            ['setting_key' => 'default_embedding_model_id', 'setting_value' => '99'],
            ['setting_key' => 'knowledge_chunk_strategy', 'setting_value' => 'semantic_llm'],
            ['setting_key' => 'knowledge_chunking_model_id', 'setting_value' => '88'],
        ]);

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.default-embedding'), [
                'default_embedding_model_id' => 0,
            ])
            ->assertRedirect(route('admin.ai-models.index'));
        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.chunking-config'), [
                'knowledge_chunk_strategy' => 'rule',
                'knowledge_chunking_model_id' => 0,
            ])
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSetting('default_embedding_model_id', '0');
        $this->assertSetting('knowledge_chunk_strategy', 'rule');
        $this->assertSetting('knowledge_chunking_model_id', '0');
    }

    public function test_system_settings_service_rechecks_role_after_request_authorization(): void
    {
        $staleSuper = $this->admin('system-role-revoked', 'super_admin');
        $model = $this->model($staleSuper, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        Admin::query()->whereKey($staleSuper->id)->update(['role' => 'admin']);

        try {
            app(AdminAiSystemSettingsService::class)->updateDefaultEmbedding($staleSuper, (int) $model->id);
            $this->fail('A stale super administrator must not update system settings.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('site_settings', ['setting_key' => 'default_embedding_model_id']);
        }
    }

    public function test_chunking_settings_write_atomically(): void
    {
        $super = $this->admin('atomic-chunking-settings', 'super_admin');
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_chunking_model_setting
            BEFORE INSERT ON site_settings
            WHEN NEW.setting_key = 'knowledge_chunking_model_id'
            BEGIN
                SELECT RAISE(ABORT, 'forced chunking setting failure');
            END
        SQL);

        $this->actingAs($super, 'admin')
            ->post(route('admin.ai-models.chunking-config'), [
                'knowledge_chunk_strategy' => 'rule',
                'knowledge_chunking_model_id' => 0,
            ])
            ->assertServerError();

        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'knowledge_chunk_strategy']);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'knowledge_chunking_model_id']);
    }

    public function test_system_model_selects_are_owned_scoped_active_and_unarchived(): void
    {
        $super = $this->admin('system-select-owner', 'super_admin');
        $other = $this->admin('system-select-other', 'super_admin');
        $eligibleEmbedding = $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['name' => 'Eligible embedding']);
        $eligibleChat = $this->model($super, 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['name' => 'Eligible chat']);
        $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_USER_CONTENT, ['name' => 'User content embedding']);
        $this->model($other, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['name' => 'Peer embedding']);
        $this->model($super, 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['name' => 'Inactive chat', 'status' => 'inactive']);
        $this->model($super, 'embedding', AiModel::ACCESS_SCOPE_SYSTEM_ONLY, ['name' => 'Archived embedding', 'archived_at' => now()]);

        $response = $this->actingAs($super, 'admin')->get(route('admin.ai-models.index'))->assertOk();

        $this->assertSame([$eligibleEmbedding->id], collect($response->viewData('embeddingModels'))->pluck('id')->all());
        $this->assertSame([$eligibleChat->id], collect($response->viewData('chatModels'))->pluck('id')->all());
        $response
            ->assertSee('Eligible embedding')
            ->assertSee('Eligible chat');
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

    /** @param array<string, mixed> $overrides */
    private function model(Admin $owner, string $type, string $scope, array $overrides = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => $type.' model '.$owner->id,
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('system-test-secret'),
            'model_id' => $type.'-'.$owner->id,
            'model_type' => $type,
            'api_url' => 'https://system.example.test',
            'status' => 'active',
        ], $overrides));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $scope,
            'archived_at' => $overrides['archived_at'] ?? null,
        ])->save();

        return $model;
    }

    private function assertSetting(string $key, string $value): void
    {
        $this->assertSame($value, SiteSetting::query()->where('setting_key', $key)->value('setting_value'));
    }
}
