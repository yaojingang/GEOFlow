<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Services\Admin\AdminAiAccessShadowReportService;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiAccessShadowReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_exposes_rollout_metrics_without_model_configuration(): void
    {
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($consumer, 'personal');
        $shared = $this->model($provider, 'shared');

        app(AdminAiModelAccessResolver::class)->resolveCandidates($consumer, 'chat');

        DB::table('ai_model_usage_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'request_payload_digest' => hash('sha256', 'shadow-report'),
            'call_key' => 'shadow-report-call',
            'payload_fingerprint' => hash('sha256', 'shadow-report-payload'),
            'operation' => 'chat',
            'ai_model_id' => $shared->id,
            'config_owner_admin_id' => $provider->id,
            'execution_admin_id' => $consumer->id,
            'ai_config_access_version' => 1,
            'execution_scope' => 'interactive_admin',
            'model_source' => 'shared',
            'business_source' => 'shadow_report_test',
            'source_type' => null,
            'source_id' => null,
            'status' => 'succeeded',
            'error_code' => null,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'estimated_cost' => null,
            'created_at' => now(),
        ]);

        $report = app(AdminAiAccessShadowReportService::class)->report(24);
        $this->assertSame(1, $report['shared_usage']['shared_success_count']);
        $this->assertSame(15, $report['shared_usage']['shared_total_tokens']);

        $this->assertSame(0, Artisan::call('geoflow:admin-ai-shadow-report', [
            '--hours' => 24,
            '--json' => true,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('"resolution_count": 1', $output);
        $this->assertStringContainsString('"shared_success_count": 1', $output);
        $this->assertStringContainsString('"shared_total_tokens": 15', $output);

        $this->assertSame($personal->id, $consumer->fresh()->ownedAiModels()->sole()->id);
    }

    private function admin(string $username, string $role, array $overrides = []): Admin
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

    private function model(Admin $owner, string $modelId): AiModel
    {
        return AiModel::query()->forceCreate([
            'owner_admin_id' => $owner->id,
            'name' => $modelId,
            'version' => '1.0',
            'api_key' => 'secret-'.$modelId,
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://api.example.test/v1',
            'failover_priority' => 100,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ]);
    }
}
