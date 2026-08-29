<?php

namespace Tests\Feature;

use App\Models\ArticleAiQualityRollout;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArticleAiQualityRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rollout_defaults_fail_closed_and_ignore_unapproved_environment_percentages(): void
    {
        config()->set('geoflow.ai_quality_principle_v2_percent', 100);
        config()->set('geoflow.ai_quality_fast_v2_percent', 100);
        config()->set('geoflow.ai_quality_scoring_v2_percent', 100);
        app(ArticleAiQualityRolloutPolicy::class)->ensureState();

        $selection = app(ArticleAiQualityVersionPolicy::class)->selection(99);

        $this->assertSame('v1', $selection['principles']);
        $this->assertSame('legacy', $selection['execution']);
        $this->assertSame('v1', $selection['scoring']);
        $this->assertTrue(app(ArticleAiQualityVersionPolicy::class)->sampledAutoReleaseEnabled());
    }

    public function test_rollout_promotion_requires_the_next_stage_and_a_recent_passing_live_report(): void
    {
        $reportPath = storage_path('framework/testing/ai-quality-rollout-report.json');
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'mode' => 'live',
            'evaluation_scope' => 'production_components',
            'production_gate_ready' => true,
            'gate_checks' => ['end_to_end_latency' => true, 'repeat_stability' => true],
            'metrics' => ['by_inspection_scope' => ['fallback_sampled' => ['case_count' => 60]]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'promote',
            '--track' => 'execution',
            '--to' => 25,
            '--report' => $reportPath,
        ])->assertFailed();

        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'promote',
            '--track' => 'execution',
            '--to' => 10,
            '--report' => $reportPath,
        ])->assertSuccessful();

        $rollout = ArticleAiQualityRollout::query()->findOrFail(1);
        $this->assertSame(10, $rollout->execution_percent);
        $this->assertNotNull($rollout->latest_evaluation_at);
        $this->assertDatabaseHas('article_ai_quality_rollout_events', [
            'action' => 'promote',
            'track' => 'execution',
            'from_percent' => 0,
            'to_percent' => 10,
        ]);
    }

    public function test_major_risk_incident_freezes_rollout_and_disables_sampled_auto_release(): void
    {
        app(ArticleAiQualityRolloutPolicy::class)->ensureState();

        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'incident',
            '--incident' => 'major-risk-missed-20260829',
        ])->assertSuccessful();

        $rollout = ArticleAiQualityRollout::query()->findOrFail(1);
        $this->assertTrue($rollout->frozen);
        $this->assertFalse($rollout->sampled_auto_release_enabled);
        $this->assertFalse(app(ArticleAiQualityVersionPolicy::class)->sampledAutoReleaseEnabled());
        $this->assertDatabaseHas('article_ai_quality_rollout_events', [
            'action' => 'incident',
            'incident_code' => 'major-risk-missed-20260829',
        ]);
    }

    public function test_an_incident_requires_a_verified_recovery_report_before_unfreezing(): void
    {
        app(ArticleAiQualityRolloutPolicy::class)->ensureState();
        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'incident',
            '--incident' => 'major-risk-recovery-test',
        ])->assertSuccessful();

        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'unfreeze'])
            ->assertFailed();
        $this->assertTrue(ArticleAiQualityRollout::query()->findOrFail(1)->frozen);

        $reportPath = $this->writePassingReport('ai-quality-rollout-recovery-report.json');
        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'unfreeze',
            '--report' => $reportPath,
        ])->assertSuccessful();

        $rollout = ArticleAiQualityRollout::query()->findOrFail(1);
        $this->assertFalse($rollout->frozen);
        $this->assertNull($rollout->incident_code);
        $this->assertFalse($rollout->sampled_auto_release_enabled);
        $this->assertNotNull($rollout->latest_evaluation_at);
        $this->assertDatabaseHas('article_ai_quality_rollout_events', ['action' => 'unfreeze']);
    }

    public function test_a_malformed_report_date_fails_safely(): void
    {
        $reportPath = $this->writePassingReport('ai-quality-rollout-invalid-date.json', 'definitely-not-a-date');

        $this->artisan('geoflow:ai-quality-rollout', [
            'action' => 'promote',
            '--track' => 'execution',
            '--to' => 10,
            '--report' => $reportPath,
        ])->assertFailed();

        $this->assertDatabaseMissing('article_ai_quality_rollouts', ['execution_percent' => 10]);
    }

    private function writePassingReport(string $filename, ?string $generatedAt = null): string
    {
        $reportPath = storage_path('framework/testing/'.$filename);
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode([
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
            'mode' => 'live',
            'evaluation_scope' => 'production_components',
            'production_gate_ready' => true,
            'gate_checks' => ['end_to_end_latency' => true, 'repeat_stability' => true],
            'metrics' => ['by_inspection_scope' => ['fallback_sampled' => ['case_count' => 60]]],
        ], JSON_THROW_ON_ERROR));

        return $reportPath;
    }
}
