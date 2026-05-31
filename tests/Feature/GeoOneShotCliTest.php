<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\GeoAnswer;
use App\Models\GeoKeyword;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoTaskQuestion;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GeoOneShotCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_imports_payload_creates_keywords_and_runs_diagnosis(): void
    {
        $admin = $this->createAdmin('geo_cli_admin');
        $payload = [
            'admin' => $admin->username,
            'organization_name' => '恒森全屋定制',
            'brand' => [
                'name' => '恒森全屋定制',
                'aliases' => ['恒森定制', '涪陵恒森'],
                'products' => '衣柜、橱柜、鞋柜、全屋定制',
                'advantages' => '本地工厂、透明计价、环保板材',
                'cases' => '涪陵本地家庭定制案例',
                'pain_points' => '价格不透明、板材环保难判断',
                'service_area' => '重庆涪陵',
                'extra_facts' => '支持上门量尺和定制设计',
                'short_name' => '恒森',
                'writing_directions' => '用本地业主案例讲清楚选择标准',
                'copy_types' => ['客户问答', '避坑指南'],
                'product_features' => ['E0级板材', '自有工厂交付'],
                'trust_proofs' => ['本地展厅可看样'],
                'promotion_regions' => ['重庆涪陵'],
                'forbidden_claims' => ['行业第一'],
            ],
            'keywords' => [
                ['keyword' => '涪陵全屋定制哪家好', 'type' => 'question', 'intent' => 'commercial'],
                '重庆全屋定制避坑',
            ],
            'platform_codes' => ['deepseek_mock', 'kimi_mock'],
            'report_mode' => 'with_recommendations',
        ];

        $exitCode = Artisan::call('geoamplify:geo-run', [
            '--json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            '--pretty' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = json_decode(Artisan::output(), true);
        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertTrue((bool) ($output['ran'] ?? false));
        $this->assertSame('completed', $output['task_status'] ?? null);

        $organization = Organization::query()->where('owner_admin_id', $admin->id)->firstOrFail();
        $brandProfile = BrandProfile::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('恒森', $brandProfile->extended_profile['short_name']);
        $this->assertSame(['客户问答', '避坑指南'], $brandProfile->extended_profile['copy_types']);

        $this->assertSame(2, GeoKeyword::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, GeoTask::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, GeoTaskQuestion::query()->count());
        $this->assertSame(4, GeoAnswer::query()->count());
        $this->assertSame(1, GeoReport::query()->count());
        $this->assertDatabaseHas('geo_tasks', [
            'organization_id' => $organization->id,
            'status' => 'completed',
            'points_cost' => 4,
        ]);
    }

    public function test_cli_can_read_payload_file_without_running_task(): void
    {
        $admin = $this->createAdmin('geo_cli_file_admin');
        $path = tempnam(sys_get_temp_dir(), 'geo-cli-payload-');
        $this->assertIsString($path);

        file_put_contents($path, json_encode([
            'admin' => $admin->username,
            'organization_name' => '恒森全屋定制',
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地交付',
            'service_area' => '重庆涪陵',
            'keywords_text' => "涪陵全屋定制哪家好\n重庆全屋定制推荐",
            'platform_codes' => ['deepseek_mock'],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $exitCode = Artisan::call('geoamplify:geo-run', [
                '--file' => $path,
                '--no-run' => true,
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = json_decode(Artisan::output(), true);
        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertFalse((bool) ($output['ran'] ?? true));
        $this->assertSame('pending', $output['task_status'] ?? null);
        $this->assertSame(2, GeoTaskQuestion::query()->count());
        $this->assertSame(0, GeoAnswer::query()->count());
        $this->assertSame(0, GeoReport::query()->count());
    }

    private function createAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => 'GEO CLI Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
