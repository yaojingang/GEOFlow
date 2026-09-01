<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Services\Admin\AdminAiSharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

class AdminAiSharingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_ordinary_admin_defaults_to_independent_ai_configuration(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), $this->createPayload('independent-admin'))
            ->assertRedirect(route('admin.admin-users.index'));

        $created = Admin::query()->where('username', 'independent-admin')->firstOrFail();

        $this->assertNull($created->shared_ai_config_owner_id);
        $this->assertSame(1, $created->ai_config_access_version);
    }

    public function test_super_admin_can_explicitly_share_their_configuration_when_creating_an_admin(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), [
                ...$this->createPayload('shared-admin'),
                'ai_config_mode' => 'shared_current_super',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $created = Admin::query()->where('username', 'shared-admin')->firstOrFail();

        $this->assertSame($superAdmin->id, $created->shared_ai_config_owner_id);
        $this->assertSame(1, $created->ai_config_access_version);
    }

    public function test_admin_creation_rejects_forged_ai_configuration_provider_identity(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $unrelatedSuperAdmin = $this->admin('other-root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.create'))
            ->post(route('admin.admin-users.store'), [
                ...$this->createPayload('forged-admin'),
                'ai_config_mode' => 'shared_current_super',
                'shared_ai_config_owner_id' => $unrelatedSuperAdmin->id,
            ])
            ->assertRedirect(route('admin.admin-users.create'))
            ->assertSessionHasErrors('shared_ai_config_owner_id');

        $this->assertDatabaseMissing('admins', ['username' => 'forged-admin']);
    }

    public function test_switching_from_shared_to_independent_increments_access_version_and_clears_only_shared_defaults(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $personalChat = $this->model($ordinaryAdmin, 'chat');
        $sharedEmbedding = $this->model($superAdmin, 'embedding');
        $settings = $this->settings($ordinaryAdmin, $personalChat, $sharedEmbedding, $superAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'independent'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $ordinaryAdmin->refresh();
        $settings->refresh();

        $this->assertNull($ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);
        $this->assertSame($personalChat->id, $settings->default_chat_model_id);
        $this->assertNull($settings->default_embedding_model_id);
        $this->assertSame($superAdmin->id, $settings->updated_by_admin_id);
    }

    public function test_switching_to_current_super_admin_and_repeating_the_same_mode_has_stable_versions(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $ordinaryAdmin->refresh();
        $this->assertSame($superAdmin->id, $ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame(2, $ordinaryAdmin->refresh()->ai_config_access_version);
    }

    public function test_stale_ai_configuration_access_version_does_not_overwrite_a_newer_change(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'ai_config_access_version' => 4,
        ]);

        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['expected_ai_config_access_version'] = 3;

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('ai_config_mode');

        $ordinaryAdmin->refresh();
        $this->assertNull($ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(4, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_deactivating_an_admin_increments_access_version_once_for_update_and_toggle_paths(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $updatedAdmin = $this->admin('updated-editor', 'admin');
        $toggledAdmin = $this->admin('toggled-editor', 'admin');

        $updatePayload = $this->updatePayload($updatedAdmin, 'independent');
        $updatePayload['status'] = 'inactive';

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $updatedAdmin->id]),
                $updatePayload,
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $updatedAdmin->refresh();
        $this->assertSame('inactive', $updatedAdmin->status);
        $this->assertSame(2, $updatedAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertRedirect(route('admin.admin-users.index'));

        $toggledAdmin->refresh();
        $this->assertSame('inactive', $toggledAdmin->status);
        $this->assertSame(2, $toggledAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame(2, $toggledAdmin->refresh()->ai_config_access_version);
    }

    public function test_admin_with_owned_active_or_archived_models_cannot_be_hard_deleted(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $activeOwner = $this->admin('active-model-owner', 'admin');
        $archivedOwner = $this->admin('archived-model-owner', 'admin');
        $activeModel = $this->model($activeOwner, 'chat');
        $archivedModel = $this->model($archivedOwner, 'chat');
        $archivedModel->forceFill(['archived_at' => now()])->save();

        $this->actingAs($superAdmin, 'admin');
        foreach ([[$activeOwner, $activeModel], [$archivedOwner, $archivedModel]] as [$owner, $model]) {
            $this->from(route('admin.admin-users.index'))
                ->post(route('admin.admin-users.delete', ['adminId' => $owner->id]))
                ->assertRedirect(route('admin.admin-users.index'))
                ->assertSessionHasErrors('admin');

            $this->assertModelExists($owner);
            $this->assertModelExists($model);
            $this->assertSame(
                __('admin.admin_users.error.delete_has_ai_dependencies', ['count' => 1]),
                session('errors')->first('admin'),
            );
        }
    }

    public function test_admin_ai_settings_follow_the_existing_admin_deletion_lifecycle(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('settings-owner', 'admin');
        $settings = $this->settings($ordinaryAdmin, null, null, $superAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertModelMissing($ordinaryAdmin);
        $this->assertModelMissing($settings);
    }

    public function test_switching_between_super_admin_providers_clears_the_previous_provider_defaults(): void
    {
        $previousProvider = $this->admin('previous-root', 'super_admin');
        $currentProvider = $this->admin('current-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $previousProvider->id,
        ]);
        $previousChat = $this->model($previousProvider, 'chat');
        $settings = $this->settings($ordinaryAdmin, $previousChat, null, $previousProvider);

        $this->actingAs($currentProvider, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($currentProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);
        $this->assertNull($settings->fresh()->default_chat_model_id);
    }

    public function test_sharing_update_rolls_back_defaults_and_access_identity_when_profile_save_fails(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $settings = $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);
        Exceptions::fake();
        Admin::updating(static function (Admin $admin): void {
            if ($admin->username === 'rollback-editor') {
                throw new RuntimeException('sensitive-rollback-detail');
            }
        });
        $payload = $this->updatePayload($ordinaryAdmin, 'independent');
        $payload['username'] = 'rollback-editor';

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors();

        $ordinaryAdmin->refresh();
        $settings->refresh();
        $this->assertSame('editor', $ordinaryAdmin->username);
        $this->assertSame($superAdmin->id, $ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);
        $this->assertSame($sharedChat->id, $settings->default_chat_model_id);
        $this->assertStringNotContainsString('sensitive-rollback-detail', implode(' ', session('errors')->all()));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_create_and_ordinary_edit_forms_render_accessible_ai_configuration_cards(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
            'ai_config_access_version' => 7,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);
        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['ai_config_mode' => 'Accessible AI mode error']),
        );

        $createHtml = $this->actingAs($superAdmin, 'admin')
            ->withSession(['errors' => $errors])
            ->get(route('admin.admin-users.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="ai_config_mode"', $createHtml);
        $this->assertMatchesRegularExpression('/value="independent"[^>]*checked/', $createHtml);
        $this->assertSame(2, substr_count($createHtml, 'name="ai_config_mode"'));
        $this->assertSame(1, substr_count($createHtml, 'id="admin-user-ai-config-mode-error"'));
        $this->assertSame(2, substr_count($createHtml, 'aria-describedby="admin-user-ai-config-mode-help admin-user-ai-config-mode-error"'));
        $this->assertStringContainsString(e($superAdmin->name), $createHtml);
        $this->assertStringContainsString(__('admin.admin_users.ai_config_shared_priority'), $createHtml);

        $editHtml = $this->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/value="shared_current_super"[^>]*checked/', $editHtml);
        $this->assertStringContainsString('name="expected_ai_config_access_version" value="7"', $editHtml);
        $this->assertStringContainsString(
            __('admin.admin_users.ai_config_independent_impact', ['defaults' => 1, 'tasks' => 0]),
            $editHtml,
        );
    }

    public function test_super_admin_self_edit_hides_and_rejects_ordinary_admin_ai_configuration_fields(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertOk()
            ->assertDontSee('name="ai_config_mode"', false)
            ->assertDontSee('name="expected_ai_config_access_version"', false);

        $this->from(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $superAdmin->id]), [
                'username' => $superAdmin->username,
                'display_name' => $superAdmin->display_name,
                'email' => $superAdmin->email,
                'status' => 'active',
                'password' => '',
                'confirm_password' => '',
                'ai_config_mode' => 'shared_current_super',
                'expected_ai_config_access_version' => 1,
            ])
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertSessionHasErrors(['ai_config_mode', 'expected_ai_config_access_version']);

        $this->assertNull($superAdmin->refresh()->shared_ai_config_owner_id);
    }

    public function test_admin_list_shows_ai_mode_and_shared_provider_without_exposing_credentials(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $model = $this->model($superAdmin, 'chat');

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.admin_users.column_ai_config'))
            ->assertSee(__('admin.admin_users.ai_config_super_self'))
            ->assertSee(__('admin.admin_users.ai_config_shared'))
            ->assertSee($superAdmin->name)
            ->assertDontSee($model->api_key, false)
            ->assertDontSee($model->api_url, false);

        $this->assertStringContainsString($ordinaryAdmin->username, $response->getContent());
    }

    public function test_ai_configuration_translation_keys_exist_in_every_supported_admin_locale(): void
    {
        $keys = [
            'column_ai_config',
            'ai_config_heading',
            'ai_config_independent',
            'ai_config_shared',
            'ai_config_shared_priority',
            'ai_config_independent_impact',
            'ai_config_super_self',
            'ai_config_provider_status',
            'error.ai_config_mode_invalid',
            'error.ai_config_access_conflict',
            'error.delete_has_ai_dependencies',
        ];

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            foreach ($keys as $key) {
                $translationKey = 'admin.admin_users.'.$key;
                $this->assertTrue(Lang::hasForLocale($translationKey, $locale), $locale.': '.$translationKey);
                $this->assertNotSame($translationKey, Lang::get($translationKey, locale: $locale));
            }
        }
    }

    public function test_sharing_service_returns_a_sanitized_structured_change_result(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);

        $result = app(AdminAiSharingService::class)->updateAdmin(
            $superAdmin,
            $ordinaryAdmin,
            $this->updatePayload($ordinaryAdmin, 'independent'),
            'independent',
            1,
        );
        $serialized = $result->toArray();

        $this->assertSame($superAdmin->id, $serialized['old_provider_admin_id']);
        $this->assertNull($serialized['new_provider_admin_id']);
        $this->assertSame(1, $serialized['old_access_version']);
        $this->assertSame(2, $serialized['new_access_version']);
        $this->assertSame([$sharedChat->id], $serialized['cleared_default_model_ids']);
        $this->assertSame(
            ['chat' => $sharedChat->id, 'embedding' => null],
            $serialized['cleared_default_ids'],
        );
        $this->assertSame(1, $serialized['cleared_default_count']);
        $this->assertSame(['queued' => 0, 'active' => 0, 'total' => 0], $serialized['pending_impact_counts']);
        $this->assertArrayNotHasKey('api_key', $serialized);
        $this->assertArrayNotHasKey('api_url', $serialized);
        $this->assertStringNotContainsString('sensitive-key', json_encode($serialized, JSON_THROW_ON_ERROR));
    }

    public function test_update_rejects_forged_provider_identity_and_ordinary_admins_cannot_use_management_endpoints(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $otherSuperAdmin = $this->admin('other-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');
        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['shared_ai_config_owner_id'] = $otherSuperAdmin->id;

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('shared_ai_config_owner_id');

        $this->assertNull($ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);

        $this->actingAs($ordinaryAdmin, 'admin')
            ->post(route('admin.admin-users.store'), $this->createPayload('blocked-admin'))
            ->assertForbidden();
        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $this->updatePayload($ordinaryAdmin, 'independent'),
        )->assertForbidden();
        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $ordinaryAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertForbidden();
        $this->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertForbidden();
    }

    public function test_array_shaped_old_ai_configuration_input_falls_back_to_the_persisted_mode_safely(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);

        $html = $this->actingAs($superAdmin, 'admin')
            ->withSession(['_old_input' => [
                'ai_config_mode' => ['unexpected'],
                'expected_ai_config_access_version' => ['unexpected'],
            ]])
            ->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/value="shared_current_super"[^>]*checked/', $html);
        $this->assertStringNotContainsString('value="Array"', $html);
        $this->assertStringContainsString('name="expected_ai_config_access_version" value="1"', $html);
    }

    public function test_update_validation_never_flashes_password_fields(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), [
                ...$this->updatePayload($ordinaryAdmin, 'independent'),
                'username' => '',
                'password' => 'password-must-stay-hidden',
                'confirm_password' => 'password-must-stay-hidden',
            ])
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('username');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('confirm_password', $oldInput);
        $this->assertSame('independent', $oldInput['ai_config_mode']);
    }

    /** @return array<string, mixed> */
    private function updatePayload(Admin $admin, string $mode): array
    {
        return [
            'username' => $admin->username,
            'display_name' => $admin->display_name,
            'email' => $admin->email,
            'status' => $admin->status,
            'password' => '',
            'confirm_password' => '',
            'ai_config_mode' => $mode,
            'expected_ai_config_access_version' => $admin->ai_config_access_version,
        ];
    }

    /** @return array<string, string> */
    private function createPayload(string $username): array
    {
        return [
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.test',
            'password' => 'safe-password-123',
            'confirm_password' => 'safe-password-123',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function admin(string $username, string $role, array $attributes = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill($attributes)->save();

        return $admin->refresh();
    }

    private function model(Admin $owner, string $type): AiModel
    {
        $model = new AiModel([
            'name' => $owner->username.' '.$type,
            'version' => 'test',
            'api_key' => 'sensitive-key',
            'model_id' => $owner->username.'-'.$type,
            'model_type' => $type,
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function settings(
        Admin $admin,
        ?AiModel $chat,
        ?AiModel $embedding,
        Admin $updatedBy,
    ): AdminAiSetting {
        $settings = new AdminAiSetting;
        $settings->forceFill([
            'admin_id' => $admin->id,
            'default_chat_model_id' => $chat?->id,
            'default_embedding_model_id' => $embedding?->id,
            'updated_by_admin_id' => $updatedBy->id,
        ])->save();

        return $settings;
    }
}
