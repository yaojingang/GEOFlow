<?php

namespace Tests\Feature;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Policies\AiModelPolicy;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminAiModelAccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_configuration_ownership_defaults_and_relationships_are_persisted(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $model = $this->model($consumer);

        $this->assertSame(1, $consumer->ai_config_access_version);
        $this->assertSame($provider->id, $consumer->sharedAiConfigOwner->id);
        $this->assertSame($consumer->id, $provider->aiConfigDependents->sole()->id);
        $this->assertSame($consumer->id, $model->owner->id);
        $this->assertSame($model->id, $consumer->ownedAiModels->sole()->id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $model->access_scope);
        $this->assertSame(100, $model->failover_priority);
        $this->assertNull($model->archived_at);
    }

    public function test_personal_candidate_pool_is_exhausted_before_shared_pool_with_stable_pool_ordering(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personalLater = $this->model($consumer, ['model_id' => 'personal-later', 'failover_priority' => 900]);
        $sharedFirst = $this->model($provider, ['model_id' => 'shared-first', 'failover_priority' => 1]);
        $personalFirst = $this->model($consumer, ['model_id' => 'personal-first', 'failover_priority' => 10]);
        $sharedSecond = $this->model($provider, ['model_id' => 'shared-second', 'failover_priority' => 1]);

        $candidateIds = app(AdminAiModelAccessResolver::class)
            ->resolveCandidates($consumer, 'chat')
            ->modelKeys();

        $this->assertSame([
            $personalFirst->id,
            $personalLater->id,
            $sharedFirst->id,
            $sharedSecond->id,
        ], $candidateIds);
    }

    public function test_inactive_execution_admin_is_rejected_before_candidate_resolution(): void
    {
        $consumer = $this->admin('consumer', 'admin', ['status' => 'inactive']);
        $this->model($consumer);

        try {
            app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat');
            $this->fail('Expected the inactive execution administrator to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE, $exception->getErrorCode());
        }
    }

    public function test_no_usable_model_is_reported_with_a_stable_error_code(): void
    {
        $consumer = $this->admin('consumer', 'admin');

        try {
            app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat');
            $this->fail('Expected candidate resolution to report an unavailable model pool.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_MODEL_UNAVAILABLE, $exception->getErrorCode());
        }
    }

    public function test_explicit_model_from_an_unrelated_administrator_is_not_accessible(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $other = $this->admin('other', 'admin');
        $otherModel = $this->model($other);

        try {
            app(AdminAiModelAccessResolver::class)->assertUsable($consumer, $otherModel);
            $this->fail('Expected the unrelated model to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE, $exception->getErrorCode());
            $this->assertSame($otherModel->id, $exception->context()['model_id']);
        }
    }

    public function test_invalid_shared_provider_is_ignored_for_automatic_candidates_and_rejected_for_explicit_shared_model(): void
    {
        $provider = $this->admin('provider', 'super_admin', ['status' => 'inactive']);
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personalModel = $this->model($consumer);
        $sharedModel = $this->model($provider);
        $resolver = app(AdminAiModelAccessResolver::class);

        $this->assertSame([$personalModel->id], $resolver->resolveCandidates($consumer, 'chat')->modelKeys());

        try {
            $resolver->assertUsable($consumer, $sharedModel);
            $this->fail('Expected the model from an inactive sharing provider to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_OWNER_INACTIVE, $exception->getErrorCode());
            $this->assertSame([
                'error_code' => AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
                'admin_id' => $consumer->id,
                'config_owner_admin_id' => $provider->id,
            ], $exception->context());
        }
    }

    public function test_shared_provider_that_is_no_longer_a_super_administrator_is_excluded(): void
    {
        $provider = $this->admin('provider', 'admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personalModel = $this->model($consumer);
        $sharedModel = $this->model($provider);
        $resolver = app(AdminAiModelAccessResolver::class);

        $this->assertSame([$personalModel->id], $resolver->resolveCandidates($consumer, 'chat')->modelKeys());

        try {
            $resolver->assertUsable($consumer, $sharedModel);
            $this->fail('Expected the demoted sharing provider model to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_OWNER_INACTIVE, $exception->getErrorCode());
        }
    }

    public function test_invalid_shared_provider_is_reported_when_the_personal_candidate_pool_is_empty(): void
    {
        $provider = $this->admin('provider', 'super_admin', ['status' => 'inactive']);
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $this->model($provider);

        try {
            app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat');
            $this->fail('Expected the invalid sharing provider to be reported.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_OWNER_INACTIVE, $exception->getErrorCode());
            $this->assertSame([
                'error_code' => AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
                'admin_id' => $consumer->id,
                'config_owner_admin_id' => $provider->id,
            ], $exception->context());
        }
    }

    public function test_independent_mode_excludes_shared_system_only_and_other_administrator_models(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $provider = $this->admin('provider', 'super_admin');
        $other = $this->admin('other', 'admin');
        $personalModel = $this->model($consumer);
        $this->model($consumer, ['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY]);
        $this->model($consumer, ['status' => 'inactive']);
        $this->model($consumer, ['archived_at' => now()]);
        $this->model($provider);
        $this->model($other);

        $this->assertSame(
            [$personalModel->id],
            app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat')->modelKeys(),
        );
    }

    public function test_super_administrator_runtime_uses_only_its_own_user_content_models(): void
    {
        $superAdmin = $this->admin('provider', 'super_admin');
        $ordinaryAdmin = $this->admin('consumer', 'admin');
        $ownModel = $this->model($superAdmin);
        $this->model($superAdmin, ['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY]);
        $this->model($ordinaryAdmin);

        $this->assertSame(
            [$ownModel->id],
            app(AdminAiModelAccessResolver::class)->resolveCandidates($superAdmin, 'chat')->modelKeys(),
        );
    }

    public function test_visibility_usability_and_management_queries_keep_access_boundaries_separate(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $other = $this->admin('other', 'admin');
        $personalActive = $this->model($consumer);
        $personalDisabled = $this->model($consumer, ['status' => 'inactive']);
        $sharedActive = $this->model($provider);
        $sharedDisabled = $this->model($provider, ['status' => 'inactive']);
        $this->model($other);
        $resolver = app(AdminAiModelAccessResolver::class);

        $this->assertEqualsCanonicalizing(
            [$personalActive->id, $personalDisabled->id],
            $resolver->managementQuery($consumer)->get()->modelKeys(),
        );
        $this->assertEqualsCanonicalizing(
            [$personalActive->id, $personalDisabled->id, $sharedActive->id, $sharedDisabled->id],
            $resolver->visibleQuery($consumer)->get()->modelKeys(),
        );
        $this->assertSame(
            [$personalActive->id, $sharedActive->id],
            $resolver->usableQuery($consumer)->get()->modelKeys(),
        );

        $visibleShared = $resolver->visibleQuery($consumer)->findOrFail($sharedActive->id);
        $this->assertArrayNotHasKey('api_key', $visibleShared->getAttributes());
        $this->assertArrayNotHasKey('api_url', $visibleShared->getAttributes());
        $this->assertArrayNotHasKey('model_id', $visibleShared->getAttributes());
    }

    public function test_shared_model_is_visible_and_usable_but_cannot_be_managed_or_reveal_a_key(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $sharedModel = $this->model($provider);
        $policy = app(AiModelPolicy::class);

        $this->assertTrue($policy->view($consumer, $sharedModel));
        $this->assertTrue($policy->useModel($consumer, $sharedModel));
        $this->assertFalse($policy->update($consumer, $sharedModel));
        $this->assertFalse($policy->delete($consumer, $sharedModel));
        $this->assertFalse($policy->test($consumer, $sharedModel));
        $this->assertFalse($policy->disable($consumer, $sharedModel));
        $this->assertFalse($policy->archive($consumer, $sharedModel));
        $this->assertFalse($policy->viewApiKey($consumer, $sharedModel));
    }

    public function test_ordinary_administrator_can_manage_only_its_own_user_content_model(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $ownModel = $this->model($consumer);
        $otherModel = $this->model($this->admin('other', 'admin'));
        $policy = app(AiModelPolicy::class);

        $this->assertTrue($policy->update($consumer, $ownModel));
        $this->assertTrue($policy->test($consumer, $ownModel));
        $this->assertTrue($policy->disable($consumer, $ownModel));
        $this->assertTrue($policy->archive($consumer, $ownModel));
        $this->assertTrue($policy->delete($consumer, $ownModel));
        $this->assertFalse($policy->update($consumer, $otherModel));
        $this->assertFalse($policy->delete($consumer, $otherModel));
    }

    public function test_shared_model_presenter_exposes_only_sanitized_metadata(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $sharedModel = $this->model($provider, [
            'name' => 'Shared Chat',
            'version' => 'v1',
            'model_id' => 'sensitive-provider-model-id',
            'api_key' => 'sensitive-ciphertext',
            'api_url' => 'https://sensitive-provider.example.test/account/123',
            'failover_priority' => 12,
        ]);

        $metadata = app(AdminAiModelAccessResolver::class)
            ->sanitizedFor($consumer, $sharedModel)
            ->toArray();

        $this->assertSame([
            'id' => $sharedModel->id,
            'name' => 'Shared Chat',
            'version' => 'v1',
            'model_type' => 'chat',
            'status' => 'active',
            'failover_priority' => 12,
            'is_available' => true,
            'is_shared' => true,
        ], $metadata);
        $this->assertArrayNotHasKey('api_key', $metadata);
        $this->assertArrayNotHasKey('api_url', $metadata);
        $this->assertArrayNotHasKey('model_id', $metadata);
    }

    public function test_sensitive_access_fields_require_a_trusted_assignment_path(): void
    {
        $admin = new Admin([
            'shared_ai_config_owner_id' => 99,
            'ai_config_access_version' => 88,
        ]);
        $model = new AiModel([
            'owner_admin_id' => 99,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'archived_at' => now(),
        ]);

        $this->assertNull($admin->shared_ai_config_owner_id);
        $this->assertSame(1, $admin->ai_config_access_version);
        $this->assertNull($model->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $model->access_scope);
        $this->assertNull($model->archived_at);
    }

    public function test_sharing_provider_delete_is_restricted_until_sharing_is_closed_with_null(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);

        try {
            $provider->delete();
            $this->fail('Expected the sharing relationship to restrict provider deletion.');
        } catch (QueryException) {
            $this->assertModelExists($provider);
        }

        $consumer->forceFill(['shared_ai_config_owner_id' => null])->save();
        $provider->delete();

        $this->assertModelMissing($provider);
    }

    public function test_model_owner_delete_is_restricted_while_owned_model_exists(): void
    {
        $owner = $this->admin('owner', 'admin');
        $model = $this->model($owner);

        try {
            $owner->delete();
            $this->fail('Expected model ownership to restrict administrator deletion.');
        } catch (QueryException) {
            $this->assertModelExists($owner);
            $this->assertModelExists($model);
        }
    }

    public function test_ai_model_access_indexes_match_the_resolver_query_shape(): void
    {
        $adminIndexes = collect(Schema::getIndexes('admins'))->keyBy('name');
        $modelIndexes = collect(Schema::getIndexes('ai_models'))->keyBy('name');

        $this->assertArrayHasKey('admins_shared_ai_config_owner_id_index', $adminIndexes->all());
        $this->assertSame(
            ['shared_ai_config_owner_id'],
            $adminIndexes['admins_shared_ai_config_owner_id_index']['columns'],
        );
        $this->assertArrayHasKey('ai_models_owner_access_candidates_index', $modelIndexes->all());
        $this->assertSame(
            ['owner_admin_id', 'access_scope', 'status', 'model_type', 'failover_priority', 'id'],
            $modelIndexes['ai_models_owner_access_candidates_index']['columns'],
        );
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

        if ($overrides !== []) {
            $admin->forceFill($overrides)->save();
        }

        return $admin->refresh();
    }

    /** @param array<string, mixed> $overrides */
    private function model(Admin $owner, array $overrides = []): AiModel
    {
        $attributes = array_merge([
            'name' => $owner->username.' model',
            'version' => 'test',
            'api_key' => 'encrypted-test-key',
            'model_id' => $owner->username.'-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides);
        $model = new AiModel($attributes);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $attributes['access_scope'] ?? AiModel::ACCESS_SCOPE_USER_CONTENT,
            'archived_at' => $attributes['archived_at'] ?? null,
        ])->save();

        return $model;
    }
}
