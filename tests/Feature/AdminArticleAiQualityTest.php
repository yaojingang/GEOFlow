<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminArticleAiQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_poll_the_latest_ai_quality_progress_for_an_article(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'segment_count' => 4,
            'completed_segment_count' => 2,
            'evidence_snapshot' => [['ref' => 'K001']],
            'started_at' => now()->subSeconds(20),
            'execution_meta' => [
                'current_phase' => 'inspecting',
                'timings_ms' => ['evidence_retrieval' => 123],
            ],
        ])->save();
        $check->newQuery()->whereKey($check->id)->update(['created_at' => now()->subSeconds(25)]);
        $check->refresh();

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('check_id', $check->id)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('phase', 'inspecting')
            ->assertJsonPath('progress_percent', 56)
            ->assertJsonPath('completed_segments', 2)
            ->assertJsonPath('total_segments', 4)
            ->assertJsonPath('active', true)
            ->assertJsonPath('reload', false)
            ->assertJsonPath('timings.evidence_retrieval', 123)
            ->assertJsonPath('requested_retrieval_mode', AiQualityRetrievalMode::CHUNK)
            ->assertJsonPath('effective_retrieval_mode', null)
            ->assertJsonPath('retrieval_strategy_version', 'chunk-evidence-1.1.0')
            ->assertJsonPath('safe_error_code', null)
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('next_poll_ms', 2000);

        $payload = $response->json();
        $this->assertGreaterThanOrEqual(24_000, $payload['elapsed_ms']);
        $this->assertLessThanOrEqual(27_000, $payload['elapsed_ms']);
        $this->assertSame($check->deadline_at->toIso8601String(), $payload['deadline_at']);
        $this->assertSame('running', $payload['effective_status']);
        $this->assertArrayHasKey('service_status', $payload);
        $this->assertArrayHasKey('queue_wait_ms', $payload);
        $this->assertArrayHasKey('next_action', $payload);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_ai_quality_progress_uses_truthful_queue_and_terminal_states(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->newQuery()->whereKey($check->id)->update(['created_at' => now()->subSeconds(20)]);
        $check->refresh();

        $queuedResponse = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('phase', 'queued')
            ->assertJsonPath('progress_percent', 8)
            ->assertJsonPath('active', true);
        $this->assertGreaterThanOrEqual(19_000, $queuedResponse->json('elapsed_ms'));
        $this->assertSame($check->deadline_at->toIso8601String(), $queuedResponse->json('deadline_at'));

        $check->forceFill([
            'status' => 'running',
            'segment_count' => 4,
            'completed_segment_count' => 0,
            'evidence_snapshot' => null,
        ])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('phase', 'evidence')
            ->assertJsonPath('progress_percent', 18)
            ->assertJsonPath('active', true);

        $check->forceFill([
            'completed_segment_count' => 4,
            'evidence_snapshot' => [['ref' => 'K001']],
        ])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('phase', 'summarizing')
            ->assertJsonPath('progress_percent', 94)
            ->assertJsonPath('active', true);

        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('phase', 'completed')
            ->assertJsonPath('progress_percent', 100)
            ->assertJsonPath('active', false)
            ->assertJsonPath('reload', true);
    }

    public function test_sampled_quality_status_exposes_scope_labels_deadlines_and_public_coverage(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 93,
            'inspection_scope' => 'fallback_sampled',
            'fallback_trigger_code' => 'inspection_primary_deadline_exceeded',
            'coverage_meta' => [
                'checked_chars' => 5800,
                'total_chars' => 26000,
                'coverage_ratio' => 0.2231,
                'range_count' => 10,
                'mandatory_claims_total' => 7,
                'mandatory_claims_covered' => 7,
                'mandatory_overflow' => false,
                'regions_covered' => ['front', 'middle', 'back'],
                'sampled_content' => '该字段不能出现在轻量状态接口。',
                'sampled_ranges' => [[
                    'field' => 'content',
                    'start_offset' => 10,
                    'end_offset' => 40,
                    'characters' => 30,
                    'content' => '原文内容不能出现在轻量状态接口。',
                ]],
            ],
            'execution_meta' => array_replace($check->execution_meta, [
                'fallback' => [
                    'trigger_code' => 'inspection_primary_deadline_exceeded',
                    'started_at' => now()->subSeconds(20)->toIso8601String(),
                ],
            ]),
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('inspection_scope', 'fallback_sampled')
            ->assertJsonPath('degraded', true)
            ->assertJsonPath('result_label', '抽样质检通过')
            ->assertJsonPath('score_label', '抽样得分')
            ->assertJsonPath('coverage.checked_chars', 5800)
            ->assertJsonPath('coverage.mandatory_claims_covered', 7)
            ->assertJsonPath('coverage.sampled_ranges.0.start_offset', 10)
            ->assertJsonPath('fallback.trigger_code', 'inspection_primary_deadline_exceeded');

        $this->assertSame($check->primary_deadline_at->toIso8601String(), $response->json('primary_deadline_at'));
        $this->assertArrayNotHasKey('sampled_content', $response->json('coverage'));
        $this->assertArrayNotHasKey('content', $response->json('coverage.sampled_ranges.0'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-sampled-result', false)
            ->assertSee(__('admin.articles.ai_quality.sampled_passed_label'))
            ->assertSee(__('admin.articles.ai_quality.sampled_score_label'))
            ->assertSee(__('admin.articles.ai_quality.sampled_no_issues'))
            ->assertDontSee(__('admin.articles.ai_quality.no_issues'));
    }

    public function test_active_ai_quality_check_renders_the_dynamic_progress_component(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'segment_count' => 4,
            'completed_segment_count' => 1,
            'evidence_snapshot' => [['ref' => 'K001']],
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-progress', false)
            ->assertSee(route('admin.articles.ai-quality.status', ['articleId' => $article->id], false), false)
            ->assertSee('role="progressbar"', false)
            ->assertSee('data-ai-quality-progress-percent', false)
            ->assertSee(__('admin.articles.ai_quality.progress_auto_refresh'), false);
    }

    public function test_detached_article_uses_distinct_model_selectors_for_generation_and_optimization(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 90,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill(['task_id' => null])->save();

        $createHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->getContent();
        $editHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($createHtml, 'id="article-ai-model"'));
        $this->assertSame(0, substr_count($createHtml, 'id="article-ai-optimization-model"'));
        $this->assertSame(0, substr_count($editHtml, 'id="article-ai-model"'));
        $this->assertSame(1, substr_count($editHtml, 'id="article-ai-optimization-model"'));
    }

    public function test_progress_reconciles_the_authoritative_state_after_the_persisted_deadline(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill(['deadline_at' => now()->subSeconds(6)])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('effective_status', 'failed')
            ->assertJsonPath('active', false)
            ->assertJsonPath('reconciling', true)
            ->assertJsonPath('reload', false)
            ->assertJsonPath('safe_error_code', 'queue_worker_unavailable')
            ->assertJsonPath('detail', __('admin.articles.ai_quality.failure_reason_worker_unavailable', [
                'seconds' => 0,
                'deadline' => 180,
            ]))
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('next_action', 'retry');
    }

    public function test_failed_ai_quality_result_explains_the_failure_without_fabricating_scores_or_a_clean_result(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'score' => null,
            'summary' => null,
            'dimension_scores' => null,
            'issues' => null,
            'knowledge_coverage' => 'sufficient',
            'error_code' => 'provider_gateway_error',
            'error_message' => 'AI 质检执行失败。',
            'execution_meta' => [
                'retryable_failure' => true,
                'model_attempts' => [[
                    'duration_ms' => 35_219,
                    'outcome' => 'failed',
                    'error_code' => 'provider_gateway_error',
                ]],
                'failure' => [
                    'code' => 'provider_gateway_error',
                    'retryable' => true,
                    'http_status' => 502,
                    'provider_code' => 'upstream_unavailable',
                ],
            ],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $statusResponse = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('safe_error_code', 'provider_gateway_error')
            ->assertJsonPath('failure.code', 'provider_gateway_error')
            ->assertJsonPath('failure.retryable', true)
            ->assertJsonPath('failure.model_attempt_seconds', 35)
            ->assertJsonPath('failure.provider_http_status', 502)
            ->assertJsonPath('failure.provider_code', 'upstream_unavailable')
            ->assertJsonPath('next_action', 'retry');

        $this->assertStringContainsString('35', (string) $statusResponse->json('failure.reason'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-failure', false)
            ->assertSee('provider_gateway_error')
            ->assertSee($statusResponse->json('failure.reason'))
            ->assertSee($statusResponse->json('failure.next_step'))
            ->assertSee('HTTP 502')
            ->assertSee('upstream_unavailable')
            ->assertSee(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]), false)
            ->assertDontSeeText('0/35')
            ->assertDontSeeText('0/25')
            ->assertDontSeeText('0/30')
            ->assertDontSeeText('0/10')
            ->assertDontSee(__('admin.articles.ai_quality.no_issues'));
    }

    public function test_persisted_non_retryable_retrieval_failure_overrides_legacy_code_inference(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'error_code' => 'evidence_retrieval_failed',
            'execution_meta' => array_replace($check->execution_meta, [
                'retryable_failure' => false,
                'failure' => [
                    'code' => 'evidence_retrieval_failed',
                    'retryable' => false,
                ],
            ]),
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('safe_error_code', 'evidence_retrieval_failed')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('failure.retryable', false);
    }

    public function test_exhausted_post_quality_workflow_is_visible_with_an_operator_retry_action(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $executionMeta['workflow_apply'] = [
            'status' => 'exhausted',
            'attempts' => 3,
            'error_code' => 'workflow_apply_exhausted',
            'updated_at' => now()->toIso8601String(),
        ];
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'execution_meta' => $executionMeta,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-workflow-failure', false)
            ->assertSee(__('admin.articles.ai_quality.workflow_failure_exhausted'))
            ->assertSee('data-ai-quality-workflow-retry', false)
            ->assertSee(route('admin.articles.ai-quality.workflow-retry', ['articleId' => $article->id]), false);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('workflow_apply.status', 'exhausted')
            ->assertJsonPath('workflow_apply.operator_retryable', true)
            ->assertJsonPath('next_action', 'retry_workflow');
    }

    public function test_non_retryable_model_failure_routes_the_operator_to_model_settings(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'score' => null,
            'summary' => null,
            'dimension_scores' => null,
            'issues' => null,
            'error_code' => 'provider_authentication_failed',
            'error_message' => 'AI 质检执行失败。',
            'execution_meta' => [
                'retryable_failure' => false,
                'failure' => [
                    'code' => 'provider_authentication_failed',
                    'retryable' => false,
                    'http_status' => 401,
                    'provider_code' => 'invalid_api_key',
                ],
            ],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertOk()
            ->assertJsonPath('failure.retryable', false)
            ->assertJsonPath('next_action', 'configure_model');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-failure-action="model-settings"', false)
            ->assertSee(route('admin.ai-models.index'), false)
            ->assertSee(__('admin.articles.ai_quality.failure_open_model_settings'))
            ->assertDontSee('data-ai-quality-failure-action="retry"', false)
            ->assertDontSeeText('0/35');
    }

    public function test_guest_cannot_poll_article_ai_quality_progress(): void
    {
        [, $article] = $this->qualityArticle();

        $this->getJson(route('admin.articles.ai-quality.status', ['articleId' => $article->id]))
            ->assertUnauthorized();
    }

    public function test_article_list_and_edit_page_show_ai_quality_result_and_issue_location(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'summary' => '数据与知识库记录不一致，需要人工核查。',
            'knowledge_coverage' => 'sufficient',
            'dimension_scores' => [
                'knowledge_consistency' => 23,
                'data_traceability' => 13,
                'advertising_compliance' => 24,
                'content_integrity' => 10,
            ],
            'issues' => [
                [
                    'code' => 'data_mismatch',
                    'severity' => 'high',
                    'field' => 'content',
                    'quote' => '服务客户超过 1000 家',
                    'paragraph_index' => 1,
                    'reason' => '知识库记录为 800 家。',
                    'suggestion' => '改为经审核的 800 家。',
                    'knowledge_refs' => ['K001'],
                    'legal_refs' => [],
                ],
                [
                    'code' => 'content_integrity',
                    'severity' => 'low',
                    'field' => 'excerpt',
                    'quote' => '摘要存在截断',
                    'paragraph_index' => 1,
                    'reason' => '摘要句子不完整。',
                    'suggestion' => '补全摘要。',
                    'knowledge_refs' => [],
                    'legal_refs' => [],
                ],
            ],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $listResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['ai_quality_status' => 'needs_review']))
            ->assertOk()
            ->assertSee(__('admin.articles.column.ai_quality'))
            ->assertSee(__('admin.articles.ai_quality.needs_review'))
            ->assertSee('78');

        $listDocument = new \DOMDocument;
        $listDocument->loadHTML((string) $listResponse->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $listXPath = new \DOMXPath($listDocument);
        $scoreBadge = $listXPath->query('//a[@data-ai-quality-score-badge="78"]')?->item(0);

        $this->assertNotNull($scoreBadge);
        $this->assertSame('78', trim((string) $scoreBadge->textContent));
        $this->assertSame(
            __('admin.articles.ai_quality.needs_review').' · '.__('admin.articles.ai_quality.score').' 78',
            $scoreBadge->getAttribute('aria-label'),
        );
        $this->assertSame(1, $listXPath->query('./i[@data-lucide="user-round-check"]', $scoreBadge)?->length);

        $editResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.title'))
            ->assertSee('数据与知识库记录不一致')
            ->assertSee('服务客户超过 1000 家')
            ->assertSee('K001')
            ->assertSee('23/35')
            ->assertSee('13/25')
            ->assertSee('24/30')
            ->assertSee('data-ai-quality-locate', false)
            ->assertSee('revealRange', false)
            ->assertSee('name="run_ai_quality_after_save"', false)
            ->assertSee(route('admin.articles.ai-quality.override', ['articleId' => $article->id]), false);

        $document = new \DOMDocument;
        @$document->loadHTML((string) $editResponse->getContent());
        $xpath = new \DOMXPath($document);
        $collapseToggle = $xpath->query('//*[@data-ai-quality-collapse-toggle]')?->item(0);
        $collapseBody = $xpath->query('//*[@id="ai-quality-result-content"]')?->item(0);
        $compactSummary = $xpath->query('//*[@data-ai-quality-compact-summary]')?->item(0);
        $issueDisclosures = $xpath->query('//details[@data-ai-quality-issue]');

        $this->assertNotNull($collapseToggle);
        $this->assertSame('ai-quality-result-content', $collapseToggle->getAttribute('aria-controls'));
        $this->assertSame('true', $collapseToggle->getAttribute('aria-expanded'));
        $this->assertSame(__('admin.articles.ai_quality.collapse'), $collapseToggle->getAttribute('data-collapse-label'));
        $this->assertSame(__('admin.articles.ai_quality.expand'), $collapseToggle->getAttribute('data-expand-label'));
        $this->assertNotNull($collapseBody);
        $this->assertNotNull($compactSummary);
        $this->assertTrue($compactSummary->hasAttribute('hidden'));
        $this->assertStringContainsString(__('admin.articles.ai_quality.score').' 78', $compactSummary->textContent);
        $this->assertStringContainsString(__('admin.articles.ai_quality.issue_count', ['count' => 2]), $compactSummary->textContent);
        $this->assertSame(2, $issueDisclosures?->length);
        foreach ($issueDisclosures ?? [] as $issueDisclosure) {
            $this->assertFalse($issueDisclosure->hasAttribute('open'));
            $this->assertSame(1, $xpath->query('./summary[@data-ai-quality-issue-summary]', $issueDisclosure)?->length);
            $this->assertSame(1, $xpath->query('./div[@data-ai-quality-issue-details]', $issueDisclosure)?->length);
            $this->assertSame(1, $xpath->query('.//*[@data-ai-quality-locate]', $issueDisclosure)?->length);
        }
    }

    public function test_published_article_with_a_failed_latest_check_hides_the_redundant_risk_banner(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 55,
            'summary' => '关键事实与知识库不一致。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertDontSee(__('admin.articles.ai_quality.published_risk_title'))
            ->assertDontSee(__('admin.articles.ai_quality.published_risk_desc'))
            ->assertSee(__('admin.articles.ai_quality.blocked'))
            ->assertSee('关键事实与知识库不一致。');

        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_published_ai_quality_risk_blocks_admin_and_api_content_updates(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 55,
            'summary' => '关键事实与知识库不一致。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();
        $originalContent = (string) $article->content;

        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => '后台尝试修改后的正文。',
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'published',
                'review_status' => 'approved',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame($originalContent, $article->content);

        $token = $admin->createToken('quality-published-update', ['articles:write'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'API 尝试修改后的正文。'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_blocked');

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame($originalContent, $article->content);
    }

    public function test_article_history_shows_historical_conclusions_and_original_quotes(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $service = app(ArticleAiQualityInspectionService::class);
        $historical = $service->createOrReuse($article, dispatch: false);
        $historical->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 76,
            'summary' => '历史质检发现客户数量不一致。',
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '服务客户超过 1000 家',
                'paragraph_index' => 1,
                'reason' => '历史知识记录为 800 家。',
                'suggestion' => '按历史证据修订。',
                'start_offset' => 0,
                'end_offset' => 13,
                'knowledge_refs' => ['K1'],
                'legal_refs' => [],
            ]],
            'active_dedupe_key' => null,
            'finished_at' => now()->subMinute(),
        ])->save();
        $current = $service->createOrReuse($article, trigger: 'admin_recheck', dispatch: false, force: true);
        $current->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'summary' => '当前质检已通过。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $editResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-history-check', false)
            ->assertSee('历史质检发现客户数量不一致。')
            ->assertSee('历史知识记录为 800 家。')
            ->assertSee('服务客户超过 1000 家')
            ->assertSee('findTextRangeByOccurrence', false);

        $document = new \DOMDocument;
        @$document->loadHTML((string) $editResponse->getContent());
        $xpath = new \DOMXPath($document);
        $historyIssueDisclosures = $xpath->query('//details[@data-ai-quality-history-issue]');

        $this->assertSame(1, $historyIssueDisclosures?->length);
        $historyIssueDisclosure = $historyIssueDisclosures?->item(0);
        $this->assertNotNull($historyIssueDisclosure);
        $this->assertFalse($historyIssueDisclosure->hasAttribute('open'));
        $this->assertSame(1, $xpath->query('./summary[@data-ai-quality-issue-summary]', $historyIssueDisclosure)?->length);
        $this->assertSame(1, $xpath->query('./div[@data-ai-quality-issue-details]', $historyIssueDisclosure)?->length);
    }

    public function test_admin_can_override_reviewable_result_with_audited_reason(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.override', ['articleId' => $article->id]), [
                'ai_quality_override_reason' => '已核对客户盖章的数据证明材料',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertDatabaseHas('article_ai_quality_checks', [
            'id' => $check->id,
            'is_overridden' => true,
            'overridden_by' => $admin->id,
            'override_reason' => '已核对客户盖章的数据证明材料',
        ]);
    }

    public function test_hosted_article_quality_override_requires_protected_workflow_permission(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $channel = DistributionChannel::query()->create([
            'name' => 'Protected quality channel',
            'domain' => 'protected-quality.test',
            'endpoint_url' => 'https://protected-quality.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $article->task->distributionChannels()->attach($channel->id);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $token = $admin->createToken('hosted-quality-override', ['articles:publish'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/override", [
                'reason' => '已通过企业原始证明材料核对',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertFalse((bool) $check->fresh()->is_overridden);
        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_decision_authorization_denied',
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'authorization_result' => 'denied',
        ]);

        $admin->forceFill(['role' => 'super_admin'])->save();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/override", [
                'reason' => '已通过企业原始证明材料核对',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.is_overridden', true);

        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_decision_overridden',
            'article_id' => $article->id,
            'article_ai_quality_check_id' => $check->id,
            'admin_id' => $admin->id,
            'authorization_result' => 'allowed',
        ]);
    }

    public function test_admin_recheck_preserves_the_old_result_and_queues_a_successor(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertSame('completed', $oldCheck->fresh()->status);
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => $article->id,
            'status' => 'queued',
            'supersedes_check_id' => $oldCheck->id,
        ]);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_admin_can_enable_and_queue_ai_quality_for_one_article_when_the_task_setting_is_disabled(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.start_manual'))
            ->assertSee(__('admin.articles.ai_quality.manual_help'))
            ->assertSee('form="article-edit-form"', false)
            ->assertSee('name="run_ai_quality_after_save"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertTrue((bool) data_get($article->ai_quality_policy_snapshot, 'required'));
        $this->assertSame('manual_article', data_get($article->ai_quality_policy_snapshot, 'source'));
        $this->assertFalse((bool) $article->task()->value('ai_quality_enabled'));
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => $article->id,
            'status' => 'queued',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertDatabaseCount('article_ai_quality_checks', 1);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_one_click_ai_quality_saves_the_current_form_content_before_it_queues_the_check(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => '点击质检前刚修改的标题',
                'excerpt' => '刚修改的摘要',
                'content' => '点击一键质检前尚未单独保存的正文。',
                'keywords' => '质检,保存',
                'meta_description' => '保存当前表单后再质检',
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'draft',
                'review_status' => 'pending',
                'run_ai_quality_after_save' => '1',
            ]);
        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('点击质检前刚修改的标题', $article->title);
        $this->assertSame('点击一键质检前尚未单独保存的正文。', $article->content);
        $this->assertSame($article->content, data_get($check->article_snapshot, 'content'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_article_with_a_required_policy_and_no_result_can_start_the_check_from_the_edit_page(): void
    {
        [$admin, $article] = $this->qualityArticle();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.not_started'))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*name="run_ai_quality_after_save")(?![^>]*\sdisabled(?:\s|=|>))[^>]*>/i',
            $html,
        );
    }

    public function test_historical_ai_quality_result_remains_visible_after_the_article_policy_is_disabled(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 80,
            'summary' => '历史单篇质检结论仍需保留。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.disabled_short'))
            ->assertSee('历史单篇质检结论仍需保留。');
    }

    public function test_private_article_content_change_queues_manual_quality_and_remembers_the_private_target(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'private',
            'review_status' => 'approved',
        ])->save();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => '私有文章修改后提交单篇 AI 质检。',
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'private',
                'review_status' => 'approved',
                'run_ai_quality_after_save' => '1',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHas('message', __('admin.articles.ai_quality.recheck_queued'));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('queued', $check->status);
        $this->assertSame('private', data_get($check->execution_meta, 'requested_workflow_state.status'));
        $this->assertSame('approved', data_get($check->execution_meta, 'requested_workflow_state.review_status'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_published_article_without_content_changes_stays_published_while_manual_quality_is_queued(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 20,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now()->subHour(),
        ])->save();
        $publishedAt = $article->published_at?->toDateTimeString();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'published',
                'review_status' => 'approved',
                'run_ai_quality_after_save' => '1',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHas('message', __('admin.articles.ai_quality.recheck_queued'));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertSame($publishedAt, $article->published_at?->toDateTimeString());
        $this->assertSame('queued', $check->status);
        $this->assertSame('published', data_get($check->execution_meta, 'requested_workflow_state.status'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_manual_ai_quality_prerequisite_failure_keeps_the_article_and_history_unchanged(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);
        $article->task->knowledgeBases()->detach();

        $response = $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]));

        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));
        $this->assertStringContainsString(
            __('admin.articles.ai_quality.manual_unavailable_knowledge'),
            implode(' ', session('errors')->all()),
        );
        $article->refresh();
        $this->assertFalse($article->ai_quality_required_at_creation);
        $this->assertNull($article->ai_quality_policy_snapshot);
        $this->assertDatabaseCount('article_ai_quality_checks', 0);
        Queue::assertNothingPushed();
    }

    public function test_recheck_failures_do_not_expose_internal_exception_details(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $article->task()->update(['ai_quality_prompt_id' => null]);
        $internalMessage = 'database-host.internal.example secret-model-token';
        $this->mock(ArticleAiQualityInspectionService::class)
            ->shouldReceive('requestManualInspection')
            ->twice()
            ->andThrow(new \RuntimeException($internalMessage));

        $adminResponse = $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]));

        $adminResponse->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));
        $this->assertStringNotContainsString($internalMessage, implode(' ', session('errors')->all()));

        $token = $admin->createToken('quality-error-api', ['articles:publish'])->plainTextToken;
        $apiResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", [
                'config_version' => (int) $article->fresh()->ai_quality_policy_version,
            ]);

        $apiResponse->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_failed');
        $this->assertStringNotContainsString($internalMessage, (string) $apiResponse->getContent());
    }

    public function test_api_detail_filter_recheck_and_override_expose_quality_workflow(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $issuedToken = $admin->createToken('quality-api', [
            'articles:read',
            'articles:write',
            'articles:publish',
        ]);
        $token = $issuedToken->plainTextToken;
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'summary' => '等待管理员核查。',
            'dimension_scores' => ['factual_consistency' => 23],
            'issues' => [['code' => 'data_mismatch', 'severity' => 'high']],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/articles?ai_quality_status=needs_review')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $article->id)
            ->assertJsonPath('data.items.0.ai_quality.decision', 'needs_review');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_quality.check_id', $check->id)
            ->assertJsonPath('data.ai_quality.issues.0.code', 'data_mismatch');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/override", [
                'reason' => '已通过企业原始证明材料核对',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.is_overridden', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", [
                'config_version' => (int) $article->fresh()->ai_quality_policy_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.status', 'queued');

        $manualRequest = $article->aiQualityChecks()->latest('id')->firstOrFail()->execution_meta['manual_requests'][0];
        $this->assertSame((int) $admin->id, (int) $manualRequest['admin_id']);
        $this->assertSame((int) $issuedToken->accessToken->id, (int) $manualRequest['api_token_id']);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_api_quality_configuration_requires_publish_scope_and_matching_policy_version(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill(['ai_quality_policy_version' => 3])->save();
        $writeToken = $admin->createToken('quality-config-write', ['articles:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$writeToken)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => 3,
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'articles:publish');

        $publishToken = $admin->createToken('quality-config-publish', [
            'articles:write',
            'articles:publish',
        ])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$publishToken)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => 2,
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_config_version_conflict')
            ->assertJsonPath('error.details.current_config_version', 3);

        $this->withHeader('Authorization', 'Bearer '.$publishToken)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => 3,
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.config_version', 4)
            ->assertJsonPath('data.ai_quality.requested_retrieval_mode', 'knowledge_broad');

        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_configuration_changed',
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'policy_version' => 4,
        ]);
    }

    public function test_api_content_change_advances_the_article_quality_policy_version(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill(['ai_quality_policy_version' => 7])->save();
        $token = $admin->createToken('quality-content-version', ['articles:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'content' => '服务客户超过 1200 家。',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.config_version', 8);

        $this->assertSame(8, $article->fresh()->ai_quality_policy_version);
    }

    public function test_api_can_update_content_and_quality_configuration_with_one_expected_version(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill(['ai_quality_policy_version' => 4])->save();
        $token = $admin->createToken('quality-mixed-update', [
            'articles:write',
            'articles:publish',
        ])->plainTextToken;

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'article-mixed-quality-update',
        ];
        $payload = [
            'config_version' => 4,
            'content' => '服务客户超过 1200 家，并建立了完整交付体系。',
            'ai_quality_retrieval_mode_override' => 'knowledge_broad',
        ];
        $first = $this->withHeaders($headers)
            ->patchJson("/api/v1/articles/{$article->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.ai_quality.config_version', 6)
            ->assertJsonPath('data.ai_quality.requested_retrieval_mode', 'knowledge_broad');
        $second = $this->withHeaders($headers)
            ->patchJson("/api/v1/articles/{$article->id}", $payload)
            ->assertOk();
        $second->assertExactJson($first->json());

        $article->refresh();
        $this->assertSame(6, $article->ai_quality_policy_version);
        $this->assertSame('knowledge_broad', $article->ai_quality_retrieval_mode_override);
        $this->assertStringContainsString('完整交付体系', $article->content);
    }

    public function test_api_cannot_detach_a_hosted_article_without_protected_workflow_permission(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $channel = DistributionChannel::query()->create([
            'name' => 'Protected configuration channel',
            'domain' => 'protected-configuration.test',
            'endpoint_url' => 'https://protected-configuration.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $article->task->distributionChannels()->attach($channel->id);
        $originalTaskId = (int) $article->task_id;
        $version = (int) $article->fresh()->ai_quality_policy_version;

        $writeToken = $admin->createToken('hosted-detach-write', ['articles:write'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$writeToken)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => $version,
                'task_id' => null,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'articles:publish');
        $this->assertSame($originalTaskId, (int) $article->fresh()->task_id);

        $publishToken = $admin->createToken('hosted-detach-publish', [
            'articles:write',
            'articles:publish',
        ])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$publishToken)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => $version,
                'task_id' => null,
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame($originalTaskId, (int) $article->fresh()->task_id);
        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_configuration_authorization_denied',
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'authorization_result' => 'denied',
            'reason_code' => 'hosted_task_permission_required',
        ]);
    }

    public function test_task_article_rejects_a_direct_knowledge_base_override(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $other = KnowledgeBase::query()->create([
            'name' => '不可覆盖知识库',
            'content' => '此知识库不应直接绑定到任务文章。',
        ]);
        $token = $admin->createToken('task-article-kb-override', [
            'articles:write',
            'articles:publish',
        ])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'config_version' => (int) $article->fresh()->ai_quality_policy_version,
                'ai_quality_knowledge_base_ids' => [$other->id],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame([], $article->aiQualityKnowledgeBases()->pluck('knowledge_bases.id')->all());
    }

    public function test_api_recheck_rejects_missing_or_stale_configuration_version(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill(['ai_quality_policy_version' => 5])->save();
        $token = $admin->createToken('quality-versioned-recheck', ['articles:publish'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_config_version_required');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", ['config_version' => 4])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_config_version_conflict')
            ->assertJsonPath('error.details.current_config_version', 5);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", ['config_version' => 5])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.status', 'queued');

        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_api_recheck_idempotency_rejects_replay_after_knowledge_source_drift(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $token = $admin->createToken('quality-context-recheck', ['articles:publish'])->plainTextToken;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'quality-context-recheck-1',
        ];
        $payload = ['config_version' => (int) $article->fresh()->ai_quality_policy_version];

        $firstResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", $payload)
            ->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", $payload)
            ->assertOk()
            ->assertExactJson($firstResponse->json());

        $article->task()->update(['next_run_at' => now()->addHour()]);
        Admin::query()->whereKey($admin->id)->update(['updated_at' => now()->addMinute()]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", $payload)
            ->assertOk()
            ->assertExactJson($firstResponse->json());

        $article->task->knowledgeBases()->firstOrFail()->update([
            'content' => '服务客户为 900 家，知识依据已经更新。',
        ]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_api_failed_quality_filter_returns_execution_failures(): void
    {
        [$admin, $article] = $this->qualityArticle();
        [, $healthyArticle] = $this->qualityArticle();
        $token = $admin->createToken('quality-failed-filter', ['articles:read'])->plainTextToken;
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'error_code' => 'provider_timeout',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $healthyCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($healthyArticle, dispatch: false);
        $healthyCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/articles?ai_quality_status=failed')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $article->id)
            ->assertJsonPath('data.items.0.ai_quality.status', 'failed');
    }

    public function test_api_task_rebinding_snapshots_the_effective_quality_policy_on_the_article(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $taskId = (int) $article->task_id;
        $article->forceFill([
            'task_id' => null,
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();

        app(ArticleGeoFlowService::class)->updateArticle($article->id, [
            'task_id' => $taskId,
        ], $admin->id);

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertTrue((bool) data_get($article->ai_quality_policy_snapshot, 'required'));
        $this->assertSame($taskId, $article->task_id);
    }

    public function test_api_task_rebinding_migrates_knowledge_sources_between_independent_and_task_policies(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $task = $article->task()->firstOrFail();
        $independentBase = KnowledgeBase::query()->create([
            'name' => '独立文章知识库 '.uniqid(),
            'content' => '独立文章依据。',
        ]);
        $replacementBase = KnowledgeBase::query()->create([
            'name' => '任务最新知识库 '.uniqid(),
            'content' => '任务最新依据。',
        ]);
        $article->forceFill([
            'task_id' => null,
            'ai_quality_retrieval_mode_override' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->aiQualityKnowledgeBases()->sync([$independentBase->id => ['sort_order' => 0]]);

        app(ArticleGeoFlowService::class)->updateArticle($article->id, [
            'task_id' => $task->id,
        ], $admin->id);

        $article->refresh();
        $this->assertSame($task->id, $article->task_id);
        $this->assertNull($article->ai_quality_retrieval_mode_override);
        $this->assertSame([], $article->aiQualityKnowledgeBases()->pluck('knowledge_bases.id')->all());

        $task->knowledgeBases()->sync([$replacementBase->id => ['sort_order' => 0]]);
        $task->forceFill([
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
            'ai_quality_policy_version' => max(1, (int) $task->ai_quality_policy_version) + 1,
        ])->save();
        app(ArticleGeoFlowService::class)->updateArticle($article->id, [
            'task_id' => null,
        ], $admin->id);

        $article->refresh();
        $this->assertNull($article->task_id);
        $this->assertSame(AiQualityRetrievalMode::CHUNK, $article->ai_quality_retrieval_mode_override);
        $this->assertSame(
            [$replacementBase->id],
            $article->aiQualityKnowledgeBases()->orderByPivot('sort_order')->pluck('knowledge_bases.id')->map('intval')->all(),
        );
        $this->assertSame(
            [$replacementBase->id],
            data_get($article->ai_quality_policy_snapshot, 'knowledge_base_ids'),
        );
    }

    public function test_article_edit_page_shows_the_shared_retrieval_selector_and_frozen_execution_mode(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'effective_retrieval_mode' => 'chunk',
            'status' => 'completed',
            'decision' => 'passed',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-retrieval-selector', false)
            ->assertSee('name="ai_quality_retrieval_mode_override"', false)
            ->assertSee('data-retrieval-mode-help-trigger', false)
            ->assertSee('aria-controls="article-ai-quality-retrieval-mode-chunk-help"', false)
            ->assertSee(__('ai_quality_retrieval.modes.chunk.description'))
            ->assertSee(__('ai_quality_retrieval.current_execution', [
                'mode' => __('ai_quality_retrieval.modes.chunk.label'),
            ]));
    }

    public function test_article_edit_page_presents_chunk_as_the_primary_result_and_atomic_shadow_as_validation_data(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $executionMeta['atomic_facts'] = [
            'mode' => 'shadow',
            'formal' => false,
            'shadow' => true,
            'inspection' => [
                'algorithm_version' => 'atomic-facts-2.3.0',
                'revision_ids' => [1],
                'coverage_rate' => 0.5,
            ],
        ];
        $check->forceFill([
            'effective_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
            'retrieval_strategy_version' => 'chunk-evidence-1.0.0',
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 90,
            'usage_meta' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 20,
                'total_tokens' => 120,
                'atomic_facts' => ['total_tokens' => 0],
                'knowledge_fallback' => ['total_tokens' => 120],
            ],
            'execution_meta' => $executionMeta,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('ai_quality_retrieval.results.primary_title', [
                'mode' => __('ai_quality_retrieval.modes.chunk.label'),
            ]))
            ->assertSee(__('ai_quality_retrieval.results.participates_in_scoring'))
            ->assertSee(__('ai_quality_retrieval.results.primary_tokens', ['tokens' => 120]))
            ->assertSee(__('ai_quality_retrieval.results.atomic_shadow_title'))
            ->assertSee(__('ai_quality_retrieval.results.validation_only'))
            ->assertDontSee('知识库回退 120 Token');
    }

    public function test_article_edit_page_presents_each_effective_retrieval_mode_as_the_primary_result(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 90,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        foreach (AiQualityRetrievalMode::values() as $mode) {
            $check->forceFill([
                'effective_retrieval_mode' => $mode,
                'retrieval_strategy_version' => $mode.'-test-version',
            ])->save();

            $this->actingAs($admin, 'admin')
                ->get(route('admin.articles.edit', ['articleId' => $article->id]))
                ->assertOk()
                ->assertSee(__('ai_quality_retrieval.results.primary_title', [
                    'mode' => __('ai_quality_retrieval.modes.'.$mode.'.label'),
                ]))
                ->assertSee(__('ai_quality_retrieval.results.participates_in_scoring'));
        }
    }

    public function test_ai_quality_result_copy_is_complete_in_every_supported_locale(): void
    {
        $requiredKeys = [
            'primary_title',
            'participates_in_scoring',
            'strategy_version',
            'primary_tokens',
            'atomic_shadow_title',
            'atomic_formal_title',
            'validation_only',
            'atomic_tokens',
        ];

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            $translations = require lang_path($locale.'/ai_quality_retrieval.php');

            foreach ($requiredKeys as $key) {
                $this->assertIsString(data_get($translations, 'results.'.$key));
                $this->assertNotSame('', trim((string) data_get($translations, 'results.'.$key)));
            }
        }
    }

    public function test_article_update_saves_a_mode_override_before_queueing_the_recheck(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'draft',
                'review_status' => 'pending',
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
                'run_ai_quality_after_save' => '1',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('knowledge_broad', $article->fresh()->ai_quality_retrieval_mode_override);
        $this->assertSame('knowledge_broad', $article->aiQualityChecks()->latest('id')->value('requested_retrieval_mode'));
        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_configuration_changed',
            'article_id' => $article->id,
            'admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'article_quality_check_requested',
            'article_id' => $article->id,
            'admin_id' => $admin->id,
        ]);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_independent_article_saves_its_ordered_quality_knowledge_bases(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $first = KnowledgeBase::query()->create(['name' => '独立知识库一', 'content' => '正文一']);
        $second = KnowledgeBase::query()->create(['name' => '独立知识库二', 'content' => '正文二']);
        $article->forceFill(['task_id' => null])->save();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'draft',
                'review_status' => 'pending',
                'ai_quality_retrieval_mode_override' => 'knowledge_broad',
                'ai_quality_knowledge_base_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasNoErrors();

        $article->refresh();
        $this->assertSame('knowledge_broad', $article->ai_quality_retrieval_mode_override);
        $this->assertSame(2, $article->ai_quality_policy_version);
        $this->assertSame(
            [$second->id, $first->id],
            $article->aiQualityKnowledgeBases()->orderByPivot('sort_order')->pluck('knowledge_bases.id')->map('intval')->all(),
        );
    }

    /** @return array{Admin, Article} */
    private function qualityArticle(): array
    {
        $admin = Admin::query()->create([
            'username' => 'article-quality-admin-'.uniqid(),
            'password' => 'secret-123',
            'display_name' => 'AI Quality Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => '质检模型 '.uniqid(),
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $task = Task::query()->create([
            'name' => '质检任务 '.uniqid(),
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_model_id' => $model->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '文章质检知识库 '.uniqid(),
            'content' => '服务客户为 800 家。',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '质检分类', 'slug' => 'quality-category-'.uniqid()]);
        $author = Author::query()->create(['name' => '质检作者']);
        $article = Article::query()->create([
            'title' => 'AI 质检文章',
            'slug' => 'ai-quality-article-'.uniqid(),
            'excerpt' => '摘要',
            'content' => '服务客户超过 1000 家。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'ai_quality_required_at_creation' => true,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        return [$admin, $article];
    }
}
