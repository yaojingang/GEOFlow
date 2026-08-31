<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiQualityInspectionDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_default_quality_prompt_is_installed_once(): void
    {
        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();

        $this->assertSame('quality_check', $prompt->type);
        $this->assertSame('2.2.0', $prompt->system_version);
        $this->assertStringContainsString('# R: Role', $prompt->content);
        $this->assertStringContainsString('{{fact_candidates}}', $prompt->content);
        $this->assertStringContainsString('stable_key', $prompt->content);
        $this->assertStringContainsString('truncated_issue_count', $prompt->content);
        $this->assertStringContainsString('reviewed_claim_hashes', $prompt->content);
        $this->assertStringNotContainsString('ai_generated_disclosure', $prompt->content);
        $this->assertSame(1, Prompt::query()->where('system_key', $prompt->system_key)->count());
    }

    public function test_ai_generation_disclosure_prompt_removal_is_reversible(): void
    {
        $fastPromptMigration = require database_path('migrations/2026_08_29_091000_sync_fast_ai_quality_prompt_v2_1.php');
        $removalMigration = require database_path('migrations/2026_08_30_133000_remove_ai_generated_disclosure_from_quality_prompt.php');

        $removalMigration->down();

        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $this->assertSame('2.1.0', $prompt->system_version);
        $this->assertStringContainsString('ai_generated_disclosure', $prompt->content);

        $fastPromptMigration->down();
        $prompt->refresh();
        $this->assertSame('2.0.0', $prompt->system_version);
        $this->assertStringContainsString('ai_generated_disclosure', $prompt->content);
        $this->assertStringNotContainsString('reviewed_claim_hashes', $prompt->content);

        $fastPromptMigration->up();
        $prompt->refresh();
        $this->assertSame('2.1.0', $prompt->system_version);
        $this->assertStringContainsString('ai_generated_disclosure', $prompt->content);
        $this->assertStringContainsString('reviewed_claim_hashes', $prompt->content);

        $removalMigration->up();

        $prompt->refresh();
        $this->assertSame('2.2.0', $prompt->system_version);
        $this->assertStringNotContainsString('ai_generated_disclosure', $prompt->content);
        $this->assertSame(
            hash('sha256', trim((string) file_get_contents(resource_path('prompts/versions/article-quality-cn-v2.2.0.txt')))),
            hash('sha256', trim($prompt->content)),
        );
    }

    public function test_quality_policy_snapshots_drop_deprecated_ai_generation_label_metadata(): void
    {
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $policy = $resolver->fromArticleSnapshot([
            'publication_context' => [
                'advertising_label_status' => 'present',
                'ai_generated_label_status' => 'missing',
                'is_ai_generated' => true,
            ],
        ]);

        $this->assertSame('present', data_get($policy, 'publication_context.advertising_label_status'));
        $this->assertArrayNotHasKey('ai_generated_label_status', $policy['publication_context']);
        $this->assertArrayNotHasKey('is_ai_generated', $policy['publication_context']);
        $this->assertArrayNotHasKey(
            'ai_generated_label_status',
            $resolver->snapshot($policy)['publication_context'],
        );
        $this->assertArrayNotHasKey(
            'is_ai_generated',
            $resolver->snapshot($policy)['publication_context'],
        );
    }

    public function test_claim_coverage_prompt_upgrade_rolls_back_with_its_schema_version(): void
    {
        $migration = require database_path('migrations/2026_08_29_091000_sync_fast_ai_quality_prompt_v2_1.php');

        $migration->down();
        $previous = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $this->assertSame('2.0.0', $previous->system_version);
        $this->assertStringNotContainsString('reviewed_claim_hashes', $previous->content);

        $migration->up();
        $current = $previous->fresh();
        $this->assertSame('2.1.0', $current->system_version);
        $this->assertStringContainsString('reviewed_claim_hashes', $current->content);
    }

    public function test_quality_prompt_migration_switches_content_and_version_together_on_rollback(): void
    {
        $migration = require database_path('migrations/2026_08_28_234104_sync_fast_ai_quality_prompt_v2.php');

        $migration->down();
        $legacy = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $this->assertSame('1.0.0', $legacy->system_version);
        $this->assertStringContainsString('knowledge_coverage', $legacy->content);
        $this->assertStringContainsString('ai_generated_disclosure', $legacy->content);
        $this->assertStringNotContainsString('truncated_issue_count', $legacy->content);

        $migration->up();
        $current = $legacy->fresh();
        $this->assertSame('2.0.0', $current->system_version);
        $this->assertStringContainsString('truncated_issue_count', $current->content);
        $this->assertStringContainsString('ai_generated_disclosure', $current->content);
        $this->assertStringNotContainsString('reviewed_claim_hashes', $current->content);
    }

    public function test_quality_prompt_upgrade_recreates_a_missing_system_prompt(): void
    {
        Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->delete();
        $migration = require database_path('migrations/2026_08_28_234104_sync_fast_ai_quality_prompt_v2.php');

        $migration->up();

        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $this->assertSame('quality_check', $prompt->type);
        $this->assertSame('2.0.0', $prompt->system_version);
        $this->assertStringContainsString('truncated_issue_count', $prompt->content);
    }

    public function test_task_quality_policy_and_article_check_are_persisted_with_safe_defaults(): void
    {
        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '质检任务',
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_model_id' => $model->id,
        ]);
        $category = Category::query()->create(['name' => '质检', 'slug' => 'quality']);
        $author = Author::query()->create(['name' => '质检员']);

        $article = new Article([
            'title' => '待检查文章',
            'slug' => 'quality-check-article',
            'content' => '正文',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => ['pass_score' => 85],
        ]);
        $article->task()->associate($task);
        $article->save();

        $check = $article->aiQualityChecks()->create([
            'request_key' => 'quality-request-1',
            'active_dedupe_key' => hash('sha256', 'article-input'),
            'status' => 'queued',
            'pass_score' => 85,
            'manual_override_min_score' => 70,
            'input_fingerprint' => hash('sha256', 'input'),
            'algorithm_version' => '1.0.0',
        ]);

        $this->assertTrue($task->fresh()->ai_quality_enabled);
        $this->assertFalse($task->fresh()->ai_quality_timeout_sampling_enabled);
        $this->assertSame(85, $task->fresh()->ai_quality_pass_score);
        $this->assertSame(70, $task->fresh()->ai_quality_manual_override_min_score);
        $this->assertTrue($article->fresh()->ai_quality_required_at_creation);
        $this->assertSame(['pass_score' => 85], $article->fresh()->ai_quality_policy_snapshot);
        $this->assertTrue($task->qualityPrompt->is($prompt));
        $this->assertTrue($task->qualityModel->is($model));
        $this->assertTrue($article->latestAiQualityCheck->is($check));
        $this->assertInstanceOf(ArticleAiQualityCheck::class, $check);
        $this->assertTrue($check->gate_applied);
        $this->assertSame('primary', $check->evaluation_mode);
        $this->assertSame('v1', $check->scoring_version);
        $this->assertSame('full', $check->inspection_scope);
        $this->assertNull($check->primary_deadline_at);
        $this->assertNull($check->sampled_deadline_at);
        $this->assertNull($check->fallback_trigger_code);
        $this->assertNull($check->coverage_meta);
        $this->assertSame([], $article->fresh()->generation_evidence_snapshot ?? []);
    }

    public function test_timeout_sampling_schema_is_forward_compatible_and_defaults_to_full_inspection(): void
    {
        $this->assertTrue(Schema::hasColumn('tasks', 'ai_quality_timeout_sampling_enabled'));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'primary_deadline_at'));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'sampled_deadline_at'));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'inspection_scope'));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'fallback_trigger_code'));
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'coverage_meta'));
        $this->assertTrue(Schema::hasTable('article_ai_quality_rollouts'));
        $this->assertTrue(Schema::hasTable('article_ai_quality_rollout_events'));
        $this->assertTrue(Schema::hasColumns('article_ai_quality_rollout_events', [
            'action',
            'track',
            'from_percent',
            'to_percent',
            'incident_code',
            'before_state',
            'after_state',
            'created_at',
        ]));

        $task = Task::query()->create(['name' => 'Timeout sampling default task']);

        $this->assertFalse($task->fresh()->ai_quality_timeout_sampling_enabled);
    }

    public function test_ai_quality_optimization_schema_and_defaults_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('tasks', [
            'ai_quality_auto_optimize_enabled',
            'ai_quality_optimization_level',
        ]));
        $this->assertTrue(Schema::hasTable('article_ai_optimization_runs'));
        $this->assertTrue(Schema::hasTable('article_ai_optimization_steps'));

        $task = Task::query()->create(['name' => '自动优化默认任务']);

        $this->assertFalse($task->fresh()->ai_quality_auto_optimize_enabled);
        $this->assertSame('excellent_80', $task->fresh()->ai_quality_optimization_level);
        $this->assertInstanceOf(
            ArticleAiOptimizationRun::class,
            $task->aiOptimizationRuns()->make(),
        );
        $this->assertInstanceOf(
            ArticleAiOptimizationStep::class,
            (new ArticleAiOptimizationRun)->steps()->make(),
        );
    }

    public function test_optimization_evaluation_modes_are_mapped_safely_during_rollback(): void
    {
        $category = Category::query()->create(['name' => '回滚质检', 'slug' => 'optimization-mode-rollback']);
        $author = Author::query()->create(['name' => '回滚质检员']);
        $article = Article::query()->create([
            'title' => '质检模式回滚',
            'slug' => 'optimization-mode-rollback',
            'content' => '正文',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
        $candidate = ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'input_fingerprint' => hash('sha256', 'candidate-mode'),
            'algorithm_version' => 'test',
            'evaluation_mode' => 'optimization_candidate',
        ]);
        $final = ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'input_fingerprint' => hash('sha256', 'final-mode'),
            'algorithm_version' => 'test',
            'evaluation_mode' => 'optimization_final',
        ]);
        $migration = require database_path('migrations/2026_08_30_091612_expand_ai_quality_evaluation_mode_for_optimization.php');

        $migration->down();

        $this->assertSame('shadow', $candidate->fresh()->evaluation_mode);
        $this->assertSame('primary', $final->fresh()->evaluation_mode);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('article_ai_quality_checks', 'evaluation_mode'));
    }
}
