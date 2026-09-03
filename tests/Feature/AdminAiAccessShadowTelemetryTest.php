<?php

namespace Tests\Feature;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AdminAiAccessShadowEvent;
use App\Models\AiModel;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminAiAccessShadowTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_records_legacy_and_safe_preferred_model_difference_without_exposing_configuration(): void
    {
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);

        $unrelated = $this->admin('unrelated');
        $legacyPreferred = $this->model($unrelated, 'legacy-global-first', [
            'api_key' => 'must-never-enter-shadow-data',
            'api_url' => 'https://private-provider.example.test/v1',
        ]);
        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($consumer, 'personal-first');
        $this->model($provider, 'shared-fallback');

        $resolved = app(AdminAiModelAccessResolver::class)
            ->resolveCandidates($consumer, 'chat');

        $this->assertSame($personal->id, $resolved->first()?->id);
        $event = AdminAiAccessShadowEvent::query()->sole();
        $this->assertSame($consumer->id, $event->execution_admin_id);
        $this->assertSame($legacyPreferred->id, $event->legacy_preferred_model_id);
        $this->assertSame($personal->id, $event->safe_preferred_model_id);
        $this->assertSame(AdminAiAccessShadowEvent::COMPARISON_DIFFERENT, $event->comparison);
        $this->assertSame(AdminAiAccessShadowEvent::MODEL_SOURCE_PERSONAL, $event->safe_model_source);
        $this->assertGreaterThanOrEqual(1, $event->inaccessible_legacy_model_count);

        $serialized = $event->toJson();
        $this->assertStringNotContainsString('must-never-enter-shadow-data', $serialized);
        $this->assertStringNotContainsString('private-provider.example.test', $serialized);
    }

    public function test_shadow_records_a_missing_safe_pool_before_the_resolver_fails_closed(): void
    {
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);

        $consumer = $this->admin('consumer');
        $legacyPreferred = $this->model($this->admin('other'), 'legacy-only');

        try {
            app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat');
            $this->fail('Expected an empty safe candidate pool to fail closed.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_MODEL_UNAVAILABLE, $exception->getErrorCode());
        }

        $event = AdminAiAccessShadowEvent::query()->sole();
        $this->assertSame($legacyPreferred->id, $event->legacy_preferred_model_id);
        $this->assertNull($event->safe_preferred_model_id);
        $this->assertSame(AdminAiAccessShadowEvent::COMPARISON_SAFE_MISSING, $event->comparison);
    }

    public function test_shadow_can_be_disabled_without_changing_safe_resolution(): void
    {
        config()->set('geoflow.admin_ai_access.shadow_enabled', false);

        $consumer = $this->admin('consumer');
        $personal = $this->model($consumer, 'personal');

        $resolved = app(AdminAiModelAccessResolver::class)
            ->resolveCandidates($consumer, 'chat');

        $this->assertSame($personal->id, $resolved->first()?->id);
        $this->assertDatabaseCount('admin_ai_access_shadow_events', 0);
    }

    private function admin(string $username, string $role = 'admin', array $overrides = []): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'password',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function model(Admin $owner, string $modelId, array $overrides = []): AiModel
    {
        return AiModel::query()->forceCreate([
            'owner_admin_id' => $owner->id,
            'name' => $modelId,
            'version' => '1.0',
            'api_key' => 'encrypted-key',
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://api.example.test/v1',
            'failover_priority' => 100,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ...$overrides,
        ]);
    }
}
