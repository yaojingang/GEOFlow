<?php

namespace Tests\Feature;

use App\Contracts\ArticleAiQualityReviewer;
use App\Models\AiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvaluateArticleAiQualityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_comparator_contract_contains_250_valid_deterministic_cases(): void
    {
        $this->artisan('geoflow:validate-atomic-comparator-contract')->assertSuccessful();
    }

    public function test_offline_evaluation_generates_machine_and_human_readable_reports(): void
    {
        $directory = storage_path('framework/testing/ai-quality-evaluation');
        $basePath = $directory.'/report';

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => base_path('tests/Fixtures/ai-quality/golden-v1.json'),
            '--output' => $basePath,
        ])->assertSuccessful();

        $this->assertFileExists($basePath.'.json');
        $this->assertFileExists($basePath.'.md');
        $report = json_decode((string) file_get_contents($basePath.'.json'), true);
        $this->assertSame('offline', $report['mode']);
        $this->assertSame(240, $report['dataset']['case_count']);
        $this->assertSame(2, $report['schema_version']);
        $this->assertSame(64, strlen($report['dataset']['sha256']));
        $this->assertSame('tests/Fixtures/ai-quality/golden-v1.json', $report['dataset']['path']);
        $this->assertFalse($report['production_gate_ready']);
        $this->assertSame('saved_predictions', $report['evaluation_scope']);
        $this->assertFalse($report['gate_checks']['end_to_end_latency']);
        $this->assertFalse($report['gate_checks']['repeat_stability']);
        $this->assertArrayHasKey('decision_confusion_matrix', $report['metrics']);
        $this->assertArrayHasKey('prompt_tokens', $report['metrics']);
        $this->assertArrayHasKey('completion_tokens', $report['metrics']);
        $this->assertArrayHasKey('token_reduction_vs_baseline', $report['metrics']);
        $this->assertArrayHasKey('repeat_stability', $report['metrics']);
        $this->assertArrayHasKey('atomic_facts', $report['metrics']);
        $this->assertArrayHasKey('wilson_95', $report['metrics']['atomic_facts']['precision']);
        $this->assertSame(240, $report['metrics']['by_inspection_scope']['full']['case_count']);
        $this->assertSame(0, $report['metrics']['by_inspection_scope']['fallback_sampled']['case_count']);
        $this->assertStringContainsString('AI 质检黄金集评测报告', (string) file_get_contents($basePath.'.md'));
        $this->assertStringContainsString('已保存预测离线复算', (string) file_get_contents($basePath.'.md'));
    }

    public function test_manual_review_of_a_safe_case_counts_as_a_false_gate(): void
    {
        $directory = storage_path('framework/testing/ai-quality-evaluation-false-gate');
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        File::put($datasetPath, json_encode([
            'version' => 'false-gate-test',
            'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60],
            'cases' => [[
                'id' => 'safe-held-for-review',
                'split' => 'calibration',
                'expected' => ['decision' => 'passed', 'issue_codes' => []],
                'prediction' => ['decision' => 'needs_review', 'issue_codes' => []],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
        ])->assertSuccessful();

        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals(1.0, $report['metrics']['safe_false_block_rate']);
        $this->assertFalse($report['production_gate_ready']);
    }

    public function test_live_evaluation_runs_distinct_full_and_sampled_production_components(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            /** @var list<string> */
            public array $instructions = [];

            public function review(AiModel $model, string $instructions): array
            {
                $this->instructions[] = $instructions;

                return [
                    'result' => [
                        'summary' => '检查完成。',
                        'promotion_context' => 'informational',
                        'reviewed_claim_hashes' => [],
                        'issues' => [],
                        'uncertainties' => [],
                        'truncated_issue_count' => 0,
                    ],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $model = AiModel::query()->create([
            'name' => 'Live evaluation fake model',
            'model_id' => 'live-evaluation-fake-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $directory = storage_path('framework/testing/ai-quality-evaluation-live');
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        File::put($datasetPath, json_encode([
            'version' => 'live-components-test',
            'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60],
            'cases' => [
                [
                    'id' => 'full-safe',
                    'split' => 'calibration',
                    'inspection_scope' => 'full',
                    'article' => ['title' => 'Full check', 'content' => str_repeat('Safe content. ', 100)],
                    'facts' => [],
                    'evidence' => [],
                    'expected' => ['decision' => 'passed', 'issue_codes' => []],
                ],
                [
                    'id' => 'sampled-safe',
                    'split' => 'regression',
                    'inspection_scope' => 'fallback_sampled',
                    'article' => ['title' => 'Sampled check', 'content' => str_repeat('Safe sampled content. ', 1000)],
                    'facts' => [],
                    'evidence' => [],
                    'expected' => ['decision' => 'passed', 'issue_codes' => []],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--model' => $model->id,
        ])->assertSuccessful();

        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('production_components', $report['evaluation_scope']);
        $this->assertSame(1, $report['metrics']['by_inspection_scope']['full']['case_count']);
        $this->assertSame(1, $report['metrics']['by_inspection_scope']['fallback_sampled']['case_count']);
        $this->assertNull($report['cases'][0]['coverage']);
        $this->assertSame(
            'article-quality-sampling-1.1.0',
            $report['cases'][1]['coverage']['algorithm_version'],
        );
        $this->assertCount(2, $reviewer->instructions);
        $this->assertStringContainsString('fallback_sampled', $reviewer->instructions[1]);
    }
}
