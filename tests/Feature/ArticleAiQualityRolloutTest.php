<?php

namespace Tests\Feature;

use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArticleAiQualityRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_tracks_use_independent_percentages_and_freeze_switch(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        KnowledgeBase::query()->forceCreate(['id' => 23, 'name' => 'KB23']);
        $library = KnowledgeFactLibrary::query()->create([
            'knowledge_base_id' => 23,
            'active_hash' => str_repeat('a', 64),
            'source_hash' => str_repeat('b', 64),
            'serving_status' => 'ready',
        ]);
        $revision = $library->revisions()->create([
            'version' => 1, 'library_hash' => $library->active_hash, 'source_hash' => $library->source_hash,
            'manifest_json' => ['facts' => []], 'published_at' => now(),
        ]);
        $library->forceFill(['active_revision_id' => $revision->id])->save();
        $reportPath = storage_path('framework/testing/local-atomic-report.json');
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode([
            'schema_version' => 2,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'live',
            'evaluation_scope' => 'local_atomic_comparison',
            'model' => ['id' => 3],
            'knowledge_base_id' => 23,
            'case_set' => [
                'version' => 'kb23-five-articles-v1',
                'article_ids' => [449, 467, 471, 473, 486],
                'sha256' => hash('sha256', 'kb23-five-articles-v1|449,467,471,473,486'),
            ],
            'atomic_revision' => ['id' => $library->active_revision_id, 'library_hash' => $library->active_hash, 'source_hash' => $library->source_hash],
            'local_atomic_gate_ready' => true,
            'metrics' => ['article_count' => 5, 'call_count' => 30, 'repeat' => 3, 'gate_checks' => ['all_passed' => true]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'promote', '--track' => 'atomic-shadow', '--to' => 10, '--report' => $reportPath])->assertSuccessful();
        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'promote', '--track' => 'atomic-fact', '--to' => 10, '--report' => $reportPath])->assertSuccessful();
        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'freeze', '--track' => 'atomic-fact'])->assertSuccessful();

        $rollout = ArticleAiQualityRollout::query()->findOrFail(1);
        $this->assertSame(10, $rollout->atomic_shadow_percent);
        $this->assertSame(10, $rollout->atomic_fact_percent);
        $this->assertTrue($rollout->atomic_fact_frozen);
        $this->assertSame(4, $rollout->epoch);
        $this->assertFalse(app(ArticleAiQualityRolloutPolicy::class)->atomicFactEnabled(1));
    }

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
        $this->assertSame(2, $rollout->epoch);
        $this->assertNotNull($rollout->latest_evaluation_at);
        $this->assertDatabaseHas('article_ai_quality_rollout_events', [
            'action' => 'promote',
            'track' => 'execution',
            'from_percent' => 0,
            'to_percent' => 10,
        ]);
        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'ai_quality_rollout_promote',
            'policy_version' => 2,
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
        $this->assertSame(2, $rollout->epoch);
        $this->assertFalse($rollout->sampled_auto_release_enabled);
        $this->assertFalse(app(ArticleAiQualityVersionPolicy::class)->sampledAutoReleaseEnabled());
        $this->assertDatabaseHas('article_ai_quality_rollout_events', [
            'action' => 'incident',
            'incident_code' => 'major-risk-missed-20260829',
        ]);
    }

    public function test_rollout_freeze_invalidates_checks_bound_to_the_previous_epoch(): void
    {
        Queue::fake();
        app(ArticleAiQualityRolloutPolicy::class)->ensureState();
        $category = Category::query()->create(['name' => 'Epoch category', 'slug' => 'epoch-category']);
        $author = Author::query()->create(['name' => 'Epoch author']);
        $article = Article::query()->create([
            'title' => 'Epoch guarded article',
            'slug' => 'epoch-guarded-article',
            'content' => 'Stable content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'ai_quality_required_at_creation' => true,
            'ai_quality_retrieval_mode_override' => 'atomic_first',
        ]);
        $check = ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'input_fingerprint' => str_repeat('b', 64),
            'algorithm_version' => 'rollout-epoch-test',
            'retrieval_basis_hash' => str_repeat('a', 64),
            'execution_meta' => [
                'retrieval_basis' => ['rollout' => ['epoch' => 1]],
            ],
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Epoch channel',
            'domain' => 'epoch-channel.test',
            'endpoint_url' => 'https://epoch-channel.test',
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $queuedDistribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'rollout-queued-'.$check->id,
            'payload_hash' => str_repeat('c', 64),
            'remote_meta' => ['ai_quality_guard' => ['check_id' => $check->id, 'rollout_epoch' => 1]],
        ]);
        $sendingDistribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'update',
            'status' => 'sending',
            'idempotency_key' => 'rollout-sending-'.$check->id,
            'payload_hash' => str_repeat('d', 64),
            'remote_meta' => ['ai_quality_guard' => ['check_id' => $check->id, 'rollout_epoch' => 1]],
        ]);

        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'freeze'])
            ->assertSuccessful();

        $check->refresh();
        $this->assertSame('stale', $check->status);
        $this->assertSame('rollout_epoch_changed', $check->error_code);
        $this->assertSame('failed', $queuedDistribution->fresh()->status);
        $this->assertNull($queuedDistribution->fresh()->next_retry_at);
        $this->assertSame('outcome_unknown', $sendingDistribution->fresh()->status);
        $this->assertNull($sendingDistribution->fresh()->next_retry_at);
        Queue::assertPushed(
            ReconcileArticleAiQualityJob::class,
            static fn (ReconcileArticleAiQualityJob $job): bool => in_array((int) $article->id, $job->articleIds, true),
        );

        Queue::fake();
        $this->artisan('geoflow:ai-quality-rollout', ['action' => 'unfreeze'])
            ->assertSuccessful();
        Queue::assertPushed(
            ReconcileArticleAiQualityJob::class,
            static fn (ReconcileArticleAiQualityJob $job): bool => in_array((int) $article->id, $job->articleIds, true),
        );
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
