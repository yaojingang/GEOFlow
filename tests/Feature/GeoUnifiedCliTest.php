<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Models\GeoTaskQuestion;
use App\Models\Organization;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class GeoUnifiedCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_cli_reports_machine_readable_status(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        [$exitCode, $output] = $this->callCli([
            'action' => 'status',
            '--admin' => $admin->username,
            '--pretty' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertSame('status', $output['action'] ?? null);
        $this->assertSame((int) $admin->id, $output['admin']['id'] ?? null);
        $this->assertSame((int) $organization->id, $output['organization']['id'] ?? null);
        $this->assertContains('topic-pipeline', $output['actions'] ?? []);
        $this->assertSame(1, $output['counts']['active_admins'] ?? null);
        $this->assertSame(1, $output['counts']['brand_profiles'] ?? null);
    }

    public function test_unified_cli_delegates_one_shot_diagnosis_payload(): void
    {
        $admin = $this->createAdmin('geo_unified_cli_diagnosis');
        $payload = [
            'admin' => $admin->username,
            'organization_name' => '恒森全屋定制',
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂、透明计价',
            'service_area' => '重庆涪陵',
            'keywords_text' => "涪陵全屋定制哪家好\n重庆全屋定制推荐",
            'platform_codes' => ['deepseek_mock'],
            'no_run' => true,
        ];

        [$exitCode, $output] = $this->callCli([
            'action' => 'diagnosis',
            '--json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertSame('diagnosis', $output['action'] ?? null);
        $this->assertSame('pending', $output['result']['task_status'] ?? null);
        $this->assertFalse((bool) ($output['result']['ran'] ?? true));
        $this->assertSame(2, GeoTaskQuestion::query()->count());
    }

    public function test_unified_cli_runs_topic_pipeline_and_returns_draft_payload(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '全屋定制报价要先拆柜体、门板、五金、安装和售后。参考：https://example.test/quote-risk',
                    ],
                ]],
            ]),
            'https://ai-b.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '客户要核验报价、板材、合同和增项边界。参考来源：https://example.test/contract-checklist',
                    ],
                ]],
            ]),
            'https://example.test/quote-risk' => Http::response(
                '<html><head><title>全屋定制报价避坑清单</title></head><body><article><p>报价要拆到柜体、门板、五金、安装、运输和售后。</p><p>合同要写清容易增项的边界。</p></article></body></html>',
                200,
            ),
            'https://example.test/contract-checklist' => Http::response(
                '<html><head><title>合同项目核验清单</title></head><body><main><p>合同里要写清计价方式、板材型号、五金品牌、安装边界和售后响应。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $modelA = $this->createAiModel('测试平台A', 'https://ai-a.test');
        $modelB = $this->createAiModel('测试平台B', 'https://ai-b.test');

        [$exitCode, $output] = $this->callCli([
            'action' => 'topic-pipeline',
            '--admin' => $admin->username,
            '--json' => json_encode([
                'topic' => '全屋定制报价怎么避免增项',
                'platform_codes' => ['ai_model:'.$modelA->id, 'ai_model:'.$modelB->id],
                'max_references' => 2,
            ], JSON_UNESCAPED_UNICODE),
            '--pretty' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertSame('topic-pipeline', $output['action'] ?? null);
        $this->assertSame((int) $organization->id, $output['organization_id'] ?? null);
        $this->assertSame('draft', $output['draft']['status'] ?? null);
        $this->assertSame(2, $output['references_count'] ?? null);
        $this->assertSame('wxmp_publish_package_v1', $output['publish_package']['version'] ?? null);

        $draft = GeoArticleDraft::query()->findOrFail((int) $output['draft']['id']);
        $this->assertSame('全屋定制报价怎么避免增项', $draft->title);
        Storage::disk('local')->assertExists($output['publish_package']['manifest_path']);
    }

    /**
     * @return array{0: Admin, 1: Organization}
     */
    private function createBrandFixture(): array
    {
        $admin = $this->createAdmin('geo_unified_cli_admin');
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'cases' => '涪陵本地家庭定制案例',
            'pain_points' => '价格不透明、板材环保难判断、售后不稳定',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);

        return [$admin, $organization];
    }

    private function createAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => 'GEO Unified CLI Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function createAiModel(string $name, string $apiUrl): AiModel
    {
        return AiModel::query()->create([
            'name' => $name,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 10,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callCli(array $parameters): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoamplify:cli', $parameters, $output);
        $decoded = json_decode($output->fetch(), true);
        $this->assertIsArray($decoded);

        return [$exitCode, $decoded];
    }
}
