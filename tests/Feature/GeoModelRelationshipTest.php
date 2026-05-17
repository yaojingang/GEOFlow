<?php

namespace Tests\Feature;

use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoAnswer;
use App\Models\GeoKeyword;
use App\Models\GeoReport;
use App\Models\GeoScore;
use App\Models\GeoTask;
use App\Models\GeoTaskQuestion;
use App\Models\Organization;
use App\Models\PointLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_diagnosis_records_are_connected_to_an_organization_and_brand(): void
    {
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'points' => 100,
        ]);

        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
        ]);

        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'industry',
            'keyword' => '重庆全屋定制',
            'intent' => 'recommendation',
        ]);

        $platform = GeoAiPlatform::query()->create([
            'name' => 'DeepSeek Mock',
            'code' => 'deepseek_mock',
            'api_mode' => 'mock',
        ]);

        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'name' => '重庆全屋定制可见度诊断',
            'status' => 'running',
        ]);

        $question = GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '重庆全屋定制推荐哪家？',
            'platform_codes' => [$platform->code],
        ]);

        $answer = GeoAnswer::query()->create([
            'geo_task_id' => $task->id,
            'geo_task_question_id' => $question->id,
            'platform_code' => $platform->code,
            'prompt' => $question->question,
            'raw_answer' => '重庆涪陵做全屋定制，可以优先了解恒森全屋定制。',
            'status' => 'succeeded',
        ]);

        GeoScore::query()->create([
            'geo_answer_id' => $answer->id,
            'brand_mentioned' => true,
            'is_recommended' => true,
            'score' => 65,
            'analysis_json' => ['reason' => 'brand recommended'],
        ]);

        GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '重庆全屋定制 GEO 诊断报告',
            'summary' => '品牌已有基础曝光。',
            'total_score' => 65,
            'markdown_report' => '# 诊断结论',
            'status' => 'ready',
        ]);

        PointLog::query()->create([
            'organization_id' => $organization->id,
            'action' => 'geo_diagnosis',
            'points_delta' => -2,
            'ref_type' => GeoTask::class,
            'ref_id' => $task->id,
        ]);

        $this->assertSame('恒森全屋定制', $task->organization->name);
        $this->assertSame('恒森全屋定制', $task->brandProfile->brand_name);
        $this->assertSame('重庆全屋定制推荐哪家？', $task->questions()->first()->question);
        $this->assertSame('deepseek_mock', $task->answers()->first()->platform_code);
        $this->assertTrue($answer->score->brand_mentioned);
        $this->assertSame(65, $task->report->total_score);
        $this->assertSame(-2, $organization->pointLogs()->first()->points_delta);
    }
}
