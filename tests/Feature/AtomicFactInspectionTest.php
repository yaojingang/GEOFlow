<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AtomicFactInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_revision_deterministically_blocks_a_critical_version_conflict(): void
    {
        [$base, $library] = $this->readyLibrary('product.public_version', '公开版本号', 'version', 'GEOFlow 当前公开版本为 v2.1.0。', 'v2.1.0');

        $result = app(ArticleAtomicFactInspector::class)->inspect('GEOFlow 2.1.1 是什么？本文介绍 v2.1.1。', [$base->id]);

        $this->assertSame('hybrid', $result['mode']);
        $this->assertSame(1, $result['contradicted_count']);
        $this->assertSame('critical', data_get($result, 'issues.0.severity'));
        $this->assertSame($library->active_revision_id, data_get($result, 'issues.0.atomic_fact.revision_id'));
        $this->assertSame($base->id, data_get($result, 'results.0.knowledge_base_id'));
        $this->assertSame(str_repeat('a', 64), data_get($result, 'results.0.source_hash'));
    }

    public function test_unmentioned_fact_is_not_covered_and_does_not_create_an_issue(): void
    {
        [$base] = $this->readyLibrary('product.wordpress', 'WordPress 连接能力', 'string', 'GEOFlow 支持连接 WordPress。', '支持 WordPress');

        $result = app(ArticleAtomicFactInspector::class)->inspect('这篇文章只介绍主题工作流。', [$base->id]);

        $this->assertSame(1, $result['not_covered_count']);
        $this->assertSame([], $result['issues']);
    }

    public function test_generic_product_subject_does_not_recall_an_unrelated_version_fact(): void
    {
        [$base] = $this->readyLibrary(
            'product.public_version',
            '公开版本',
            'version',
            'GEOFlow 当前公开版本为 v2.1.0。',
            'v2.1.0',
            'GEOFlow',
            '当前公开版本为',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect(
            'GEOFlow Agent 渠道支持下载目标站点包。',
            [$base->id],
        );

        $this->assertSame(0, $result['ambiguous_count']);
        $this->assertSame([], $result['results']);
        $this->assertSame(1, $result['fallback_count']);
    }

    public function test_generic_predicate_alone_does_not_recall_an_unrelated_fact(): void
    {
        [$base] = $this->readyLibrary(
            'knowledge.markdown_editor',
            '知识库Markdown编辑器',
            'string',
            'GEOFlow知识库详情页新增Markdown编辑器。',
            'Markdown编辑器',
            'GEOFlow知识库详情页',
            '新增',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect(
            '站点设置新增首页模块和自定义样式能力。',
            [$base->id],
        );

        $this->assertSame([], $result['results']);
        $this->assertSame(1, $result['fallback_count']);
    }

    public function test_semantically_equivalent_channel_capability_is_supported(): void
    {
        [$base] = $this->readyLibrary(
            'product.wordpress_capability',
            'WordPress REST渠道核心能力',
            'string',
            'WordPress REST渠道使用Application Password发布、更新、删除文章，上传媒体，维护分类和标签。',
            '发布、更新、删除文章；上传媒体；维护分类和标签',
            'WordPress REST渠道',
            '使用Application Password执行',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect(
            'WordPress REST 渠道使用 Application Password 连接已有站点，支持文章发布、更新、删除，以及媒体上传、分类和标签维护。',
            [$base->id],
        );

        $this->assertSame(1, $result['supported_count']);
        $this->assertSame(0, $result['fallback_count']);
        $this->assertSame('text_similarity', data_get($result, 'results.0.comparison_method'));
    }

    public function test_conflicting_stable_keys_from_multiple_ready_libraries_are_reported(): void
    {
        [$first] = $this->readyLibrary('product.version', '版本', 'version', '版本是 v2.1.0。', 'v2.1.0');
        [$second] = $this->readyLibrary('product.version', '版本', 'version', '版本是 v2.2.0。', 'v2.2.0');

        $result = app(ArticleAtomicFactInspector::class)->inspect('当前版本是 v2.1.0。', [$first->id, $second->id]);

        $this->assertSame(1, $result['conflict_count']);
        $this->assertSame(0, $result['contradicted_count']);
    }

    public function test_claim_limit_moves_overflow_into_chunk_fallback_without_omitting_content(): void
    {
        [$base] = $this->readyLibrary('product.version', '版本', 'version', '版本是 v2.1.0。', 'v2.1.0');
        $article = collect(range(1, 30))
            ->map(static fn (int $index): string => '第'.$index.'项说明用于验证完整回退。')
            ->implode('');

        $result = app(ArticleAtomicFactInspector::class)->inspect($article, [$base->id]);

        $this->assertSame(30, $result['detected_claim_count']);
        $this->assertSame(24, $result['atomic_processed_count']);
        $this->assertSame(6, $result['overflow_fallback_count']);
        $this->assertSame(0, $result['uninspected_claim_count']);
        $this->assertSame(30, $result['fallback_count']);
        $this->assertStringContainsString('第30项说明用于验证完整回退。', $result['fallback_content']);
    }

    public function test_numeric_comparison_pairs_the_value_nearest_to_the_fact_with_its_own_unit(): void
    {
        [$base] = $this->readyLibrary(
            'company.revenue',
            '公司营收',
            'number',
            '公司营收为 1 亿元。',
            '1',
            '公司',
            '营收',
            '亿',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect('2026年公司营收为1亿元。', [$base->id]);

        $this->assertSame(1, $result['supported_count']);
        $this->assertSame(0, $result['contradicted_count']);
    }

    public function test_specific_subject_does_not_recall_an_unrelated_numeric_fact(): void
    {
        [$base] = $this->readyLibrary(
            'company.employee_count',
            '员工人数',
            'number',
            'Acme 员工人数为 128 人。',
            '128',
            'Acme',
            '员工数',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect(
            'Acme 2026年营收为1000万元。',
            [$base->id],
        );

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['contradicted_count']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(1, $result['fallback_count']);
    }

    public function test_semicolon_separated_claims_cannot_borrow_support_from_each_other(): void
    {
        [$base] = $this->readyLibrary(
            'company.employee_count',
            '员工人数',
            'number',
            'Acme 员工人数为 128 人。',
            '128',
            'Acme',
            '员工人数为',
            '人',
        );

        $result = app(ArticleAtomicFactInspector::class)->inspect(
            'Acme 员工人数为 128 人；2026 年营收为 999 亿元。',
            [$base->id],
        );

        $this->assertSame(1, $result['supported_count']);
        $this->assertSame(1, $result['fallback_count']);
        $this->assertStringContainsString('2026 年营收为 999 亿元。', $result['fallback_content']);
    }

    public function test_malformed_alias_and_scope_leaves_cannot_crash_runtime_inspection(): void
    {
        [$base, $library] = $this->readyLibrary(
            'company.employee_count',
            '员工人数',
            'number',
            '员工人数为 128 人。',
            '128',
            'Acme',
            '员工数',
        );
        $manifest = $library->activeRevision->manifest_json;
        $manifest['facts'][0]['aliases'] = [['nested' => 'invalid']];
        $manifest['facts'][0]['values'][0]['scope'] = [['nested' => 'invalid']];
        DB::table('knowledge_fact_library_revisions')
            ->where('id', $library->active_revision_id)
            ->update(['manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

        $result = app(ArticleAtomicFactInspector::class)->inspect('员工人数为 128 人。', [$base->id]);

        $this->assertSame(1, $result['supported_count']);
        $this->assertSame([], $result['issues']);
    }

    /** @return array{KnowledgeBase,KnowledgeFactLibrary} */
    private function readyLibrary(
        string $stableKey,
        string $label,
        string $type,
        string $answer,
        string $value,
        string $subject = 'GEOFlow',
        string $predicate = '值为',
        string $unit = '',
    ): array {
        $base = KnowledgeBase::query()->create([
            'name' => $label,
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => str_repeat('a', 64),
            'chunk_serving_generation' => 'serving-v1',
            'chunk_serving_source_hash' => str_repeat('a', 64),
        ]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $revision = $library->revisions()->create([
            'version' => 1,
            'library_hash' => hash('sha256', $answer),
            'source_hash' => str_repeat('a', 64),
            'published_at' => now(),
            'manifest_json' => ['schema_version' => 1, 'source_hash' => str_repeat('a', 64), 'facts' => [[
                'stable_key' => $stableKey, 'label' => $label, 'subject' => $subject, 'predicate' => $predicate,
                'value_type' => $type, 'importance' => 'critical', 'aliases' => [],
                'values' => [['canonical_value' => array_filter(['value' => $value, 'unit' => $unit]), 'canonical_answer' => $answer, 'evidence' => [['knowledge_chunk_id' => 1, 'excerpt' => $answer]]]],
            ]]],
        ]);
        $library->forceFill(['active_revision_id' => $revision->id, 'active_hash' => $revision->library_hash, 'source_hash' => $revision->source_hash, 'serving_status' => 'ready'])->save();

        return [$base, $library->fresh()];
    }
}
