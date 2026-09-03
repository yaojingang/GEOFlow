<?php

namespace Tests\Feature;

use App\Contracts\Admin\AiModelWriteLock;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Services\Admin\AdminAiSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_can_use_personal_chat_and_shared_embedding_models(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personalChat = $this->model($consumer, 'chat');
        $sharedEmbedding = $this->model($provider, 'embedding');

        $settings = app(AdminAiSettingsService::class)->setDefaults(
            $consumer,
            $personalChat,
            $sharedEmbedding,
            $provider,
        );

        $this->assertTrue($settings->admin->is($consumer));
        $this->assertTrue($settings->defaultChatModel->is($personalChat));
        $this->assertTrue($settings->defaultEmbeddingModel->is($sharedEmbedding));
        $this->assertTrue($settings->updatedByAdmin->is($provider));
        $this->assertTrue($consumer->aiSettings->is($settings));
    }

    public function test_default_model_must_be_accessible_and_match_the_requested_capability(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin');
        $unrelated = $this->admin('unrelated', 'admin');
        $unrelatedChat = $this->model($unrelated, 'chat');
        $chatUsedAsEmbedding = $this->model($consumer, 'chat');
        $service = app(AdminAiSettingsService::class);

        try {
            $service->setDefaults($consumer, $unrelatedChat, null, $provider);
            $this->fail('Expected inaccessible default model to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE, $exception->getErrorCode());
        }

        try {
            $service->setDefaults($consumer, null, $chatUsedAsEmbedding, $provider);
            $this->fail('Expected incompatible embedding model to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_EMBEDDING_INCOMPATIBLE, $exception->getErrorCode());
        }

        $this->assertDatabaseCount('admin_ai_settings', 0);
    }

    public function test_selected_models_are_locked_once_in_stable_id_order_before_validation(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $embedding = $this->model($consumer, 'embedding');
        $chat = $this->model($consumer, 'chat');
        $lock = Mockery::mock(AiModelWriteLock::class);
        $lock->shouldReceive('lockByIds')
            ->once()
            ->with([$embedding->id, $chat->id])
            ->andReturn(new Collection([$embedding->fresh(), $chat->fresh()]));
        $this->app->instance(AiModelWriteLock::class, $lock);

        $settings = app(AdminAiSettingsService::class)->setDefaults(
            $consumer,
            $chat,
            $embedding,
            $consumer,
        );

        $this->assertSame($chat->id, $settings->default_chat_model_id);
        $this->assertSame($embedding->id, $settings->default_embedding_model_id);
    }

    public function test_access_is_rechecked_against_the_locked_model_state(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $chat = $this->model($consumer, 'chat');
        $lock = Mockery::mock(AiModelWriteLock::class);
        $lock->shouldReceive('lockByIds')
            ->once()
            ->with([$chat->id])
            ->andReturnUsing(function () use ($chat): Collection {
                $chat->forceFill(['archived_at' => now()])->save();

                return new Collection([$chat->fresh()]);
            });
        $this->app->instance(AiModelWriteLock::class, $lock);

        try {
            app(AdminAiSettingsService::class)->setDefaults($consumer, $chat, null, $consumer);
            $this->fail('Expected a model archived before lock acquisition to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_MODEL_UNAVAILABLE, $exception->getErrorCode());
        }

        $this->assertDatabaseCount('admin_ai_settings', 0);
    }

    public function test_shared_owner_defaults_can_be_cleared_without_removing_personal_defaults(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personalChat = $this->model($consumer, 'chat');
        $sharedEmbedding = $this->model($provider, 'embedding');
        $service = app(AdminAiSettingsService::class);
        $service->setDefaults($consumer, $personalChat, $sharedEmbedding, $provider);

        $settings = $service->clearDefaultsFromOwner($consumer, $provider, $provider);

        $this->assertTrue($settings->defaultChatModel->is($personalChat));
        $this->assertNull($settings->defaultEmbeddingModel);
        $this->assertTrue($settings->updatedByAdmin->is($provider));
    }

    public function test_setting_identity_fields_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        new AdminAiSetting([
            'admin_id' => 10,
            'default_chat_model_id' => 20,
            'default_embedding_model_id' => 30,
            'updated_by_admin_id' => 40,
        ]);
    }

    public function test_only_the_subject_or_a_super_admin_can_update_personal_defaults(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $otherAdmin = $this->admin('other-admin', 'admin');
        $chat = $this->model($consumer, 'chat');

        $this->expectException(AuthorizationException::class);

        app(AdminAiSettingsService::class)->setDefaults($consumer, $chat, null, $otherAdmin);
    }

    public function test_legacy_empty_model_type_is_treated_as_chat(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $legacyChat = $this->model($consumer, null);

        $settings = app(AdminAiSettingsService::class)->setDefaults(
            $consumer,
            $legacyChat,
            null,
            $consumer,
        );

        $this->assertTrue($settings->defaultChatModel->is($legacyChat));
    }

    public function test_setting_foreign_keys_follow_configuration_lifecycle(): void
    {
        $updater = $this->admin('updater', 'super_admin');
        $consumer = $this->admin('consumer', 'admin');
        $chat = $this->model($consumer, 'chat');
        $setting = app(AdminAiSettingsService::class)->setDefaults(
            $consumer,
            $chat,
            null,
            $updater,
        );

        $chat->delete();
        $this->assertNull($setting->fresh()->default_chat_model_id);

        $updater->delete();
        $this->assertNull($setting->fresh()->updated_by_admin_id);

        $consumer->delete();
        $this->assertModelMissing($setting);
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

    private function model(Admin $owner, ?string $type): AiModel
    {
        $model = new AiModel([
            'name' => $owner->username.' '.($type ?? 'legacy-chat'),
            'version' => 'test',
            'api_key' => 'secret-key',
            'model_id' => $owner->username.'-'.($type ?? 'legacy-chat'),
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
}
