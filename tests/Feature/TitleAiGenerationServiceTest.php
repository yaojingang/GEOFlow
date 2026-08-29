<?php

namespace Tests\Feature;

use App\Ai\Agents\TitleGeneratorAgent;
use App\Models\AiModel;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TitleAiGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_generation_defers_without_calling_a_model_after_daily_quota_is_used(): void
    {
        TitleGeneratorAgent::fake(['真实模型标题'])->preventStrayPrompts();
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

        $this->assertTrue($result->quotaWasExhausted());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        TitleGeneratorAgent::assertNeverPrompted();
    }

    public function test_successful_title_generation_records_daily_and_total_usage(): void
    {
        TitleGeneratorAgent::fake(["GEO 标题一\nGEO 标题二"])->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            2,
            'professional',
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(['GEO 标题一', 'GEO 标题二'], $result->titles);
        $this->assertSame(now()->toDateString(), $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        TitleGeneratorAgent::assertPrompted(
            fn ($prompt): bool => $prompt->agent->maxTokens() === 512,
        );
    }

    public function test_failed_title_generation_records_the_reserved_daily_attempt(): void
    {
        TitleGeneratorAgent::fake([''])->preventStrayPrompts();
        $model = $this->createModel(['daily_limit' => 1]);

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            1,
            'professional',
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_provider_failures_return_only_a_safe_internal_code(): void
    {
        TitleGeneratorAgent::fake(
            static fn (): never => throw new \RuntimeException('Authorization: Bearer provider-secret'),
        )->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            1,
            'professional',
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('ai_provider_request_failed', $result->failureCode);
        $this->assertStringNotContainsString('provider-secret', (string) $result->failureCode);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_title_generation_filters_explanatory_lines_and_markdown_fences(): void
    {
        TitleGeneratorAgent::fake(["以下是标题列表：\n```text\n1. 2026年GEO趋势\n```"])->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            4,
            'professional',
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(['2026年GEO趋势'], $result->titles);
    }

    public function test_title_generation_parses_a_json_title_list(): void
    {
        TitleGeneratorAgent::fake(['["GEO 标题一","GEO 标题二"]'])->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            2,
            'professional',
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(['GEO 标题一', 'GEO 标题二'], $result->titles);
    }

    public function test_oversized_model_output_is_rejected_and_counts_the_reserved_attempt(): void
    {
        TitleGeneratorAgent::fake([str_repeat('超', 2001)])->preventStrayPrompts();
        $model = $this->createModel();

        $result = app(TitleAiGenerationService::class)->generateTitles(
            $model,
            ['GEO 内容工程'],
            1,
            'professional',
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('ai_title_response_too_large', $result->failureCode);
        $this->assertSame(1, (int) $model->fresh()->used_today);
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
