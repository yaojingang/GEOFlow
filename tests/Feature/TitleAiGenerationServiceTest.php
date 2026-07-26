<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class TitleAiGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_generation_uses_fallback_without_calling_a_model_after_daily_quota_is_used(): void
    {
        AnonymousAgent::fake(['真实模型标题'])->preventStrayPrompts();
        $model = $this->createModel([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            1,
            'professional',
        );

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_successful_title_generation_records_daily_and_total_usage(): void
    {
        AnonymousAgent::fake(["GEO 标题一\nGEO 标题二"])->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            2,
            'professional',
        );

        $this->assertFalse($result['fallback_used']);
        $this->assertSame(['GEO 标题一', 'GEO 标题二'], $result['titles']);
        $this->assertSame(now()->toDateString(), $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_failed_title_generation_releases_the_reserved_daily_usage(): void
    {
        AnonymousAgent::fake([''])->preventStrayPrompts();
        $model = $this->createModel(['daily_limit' => 1]);

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            1,
            'professional',
        );

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    private function createModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Title Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('title-test-key'),
            'model_id' => 'title-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'daily_limit' => 10,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
