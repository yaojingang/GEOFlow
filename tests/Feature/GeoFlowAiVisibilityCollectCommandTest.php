<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoFlowAiVisibilityCollectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_collects_search_and_deepseek_analysis_with_saved_bindings(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_cli_collect',
                'Result' => [
                    'WebResults' => [
                        [
                            'Title' => 'GEOFlow',
                            'Url' => 'https://example.com/geoflow',
                            'Snippet' => 'GEOFlow visibility source',
                        ],
                    ],
                ],
            ]),
        ]);
        MarkdownContentWriterAgent::fake(['分析完成'])->preventStrayPrompts();

        $provider = AiSourceProvider::query()->create([
            'name' => 'Doubao Search Custom',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('search-key'),
            'status' => 'active',
            'daily_limit' => 10,
        ]);
        $model = AiModel::query()->create([
            'name' => 'DeepSeek Analysis',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('deepseek-key'),
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'failover_priority' => 10,
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'ai_visibility_deepseek_analysis_model_id',
            'setting_value' => (string) $model->id,
        ]);

        $this->artisan('geoflow:ai-visibility:collect', [
            'keywords' => ['GEOFlow'],
        ])
            ->expectsOutputToContain('GEOFlow')
            ->assertSuccessful();

        $this->assertDatabaseHas('ai_visibility_runs', [
            'keyword' => 'GEOFlow',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'status' => AiVisibilityRun::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('ai_visibility_runs', [
            'keyword' => 'GEOFlow',
            'provider_type' => AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
            'status' => AiVisibilityRun::STATUS_COMPLETED,
        ]);
        $this->assertSame(1, (int) $provider->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }
}
