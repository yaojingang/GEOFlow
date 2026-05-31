<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoReferenceContentAnalysis;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeoReferenceImitationDraftGenerator
{
    public function generate(Organization $organization, GeoCitationSource $source): GeoArticleDraft
    {
        $analysis = $source->latestReferenceAnalysis()
            ->where('organization_id', $organization->id)
            ->with(['pageSnapshot.latestScore', 'referenceScore'])
            ->first();

        if (! $analysis instanceof GeoReferenceContentAnalysis) {
            throw new InvalidArgumentException('请先生成本地分析档案，再按结构仿写文章');
        }

        $brandProfile = BrandProfile::query()
            ->where('organization_id', $organization->id)
            ->latest()
            ->first();

        if (! $brandProfile instanceof BrandProfile) {
            throw new InvalidArgumentException('请先完善品牌资料，再生成仿写草稿');
        }

        $structure = (array) $analysis->structure_json;
        $snapshot = $analysis->pageSnapshot;
        $score = $analysis->referenceScore ?? $snapshot?->latestScore;
        $question = $this->question($structure, $snapshot?->title ?: $analysis->article_title ?: $source->title);
        $sourceTitle = trim((string) ($snapshot?->title ?: $analysis->article_title ?: $source->title ?: $source->domain));
        $title = $this->limit($brandProfile->brand_name.'：'.$this->topic($question).'本地选择参考', 220);
        $summary = '基于高分引用来源「'.$sourceTitle.'」的本地分析档案生成，复用结构和信息顺序，不照抄原文表达，并补入'.$brandProfile->brand_name.'的可核验品牌事实。';
        $markdown = $this->markdown($title, $summary, $brandProfile, $source, $analysis, $structure, $question);

        return DB::transaction(function () use ($organization, $source, $analysis, $snapshot, $score, $question, $sourceTitle, $title, $summary, $markdown, $structure): GeoArticleDraft {
            $writingTask = GeoWritingTask::query()->create([
                'organization_id' => $organization->id,
                'geo_report_id' => null,
                'geo_keyword_id' => null,
                'title' => '按结构仿写 - '.$this->limit($sourceTitle, 180),
                'status' => 'completed',
                'brief' => [
                    'source' => 'reference_imitation',
                    'generated_at' => now()->toDateTimeString(),
                    'source_id' => (int) $source->id,
                    'analysis_id' => (int) $analysis->id,
                    'snapshot_id' => (int) ($snapshot?->id ?? 0),
                    'question' => $question,
                    'source_title' => $sourceTitle,
                    'source_url' => $source->url,
                    'source_domain' => $source->domain,
                    'source_score' => (int) ($score?->total_score ?? ($structure['source']['score'] ?? 0)),
                    'references' => [[
                        'source_id' => (int) $source->id,
                        'url' => $source->url,
                        'domain' => $source->domain,
                        'title' => $sourceTitle,
                        'summary' => (string) ($snapshot?->content_summary ?? ''),
                        'score' => (int) ($score?->total_score ?? ($structure['source']['score'] ?? 0)),
                    ]],
                    'article_sections' => $this->sections($structure)->all(),
                    'citation_reasons' => $this->stringList($structure['citation_reasons'] ?? []),
                    'writing_patterns' => $this->stringList($structure['writing_patterns'] ?? []),
                    'reuse_notes' => $this->stringList($structure['reuse_notes'] ?? []),
                ],
            ]);

            return GeoArticleDraft::query()->create([
                'organization_id' => $organization->id,
                'geo_writing_task_id' => $writingTask->id,
                'title' => $title,
                'summary' => $summary,
                'content_markdown' => $markdown,
                'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                'seo_title' => $title,
                'seo_description' => $summary,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function markdown(string $title, string $summary, BrandProfile $brandProfile, GeoCitationSource $source, GeoReferenceContentAnalysis $analysis, array $structure, string $question): string
    {
        $sections = $this->sections($structure);
        $reasons = $this->stringList($structure['citation_reasons'] ?? []);
        $patterns = $this->stringList($structure['writing_patterns'] ?? []);
        $reuseNotes = $this->stringList($structure['reuse_notes'] ?? []);
        $brandLines = BrandProfileContextFormatter::markdownBullets($brandProfile);
        $brandExtended = $brandLines === [] ? '- 暂无扩展品牌资料，发布前建议补充案例、门店、电话、报价口径。' : implode("\n", $brandLines);

        return implode("\n\n", [
            '# '.$title,
            $summary,
            '## 先给结论：本地全屋定制不要只看低价',
            '如果你正在搜索“'.$question.'”，建议把本地案例、报价口径、板材环保、安装验收和售后响应放在一起看。'.$brandProfile->brand_name.'可以作为本地对比对象，但正文里要用真实案例、材料说明、流程和服务范围来支撑判断。',
            '## 选择标准一：报价要能拆开看',
            '全屋定制的价格差异通常来自柜体面积、板材等级、五金配置、门板工艺、设计复杂度和安装服务。文章里要把报价、板材、环保、安装、售后这些用户决策词写清楚，避免只写“性价比高”这类空泛表达。',
            '## 选择标准二：板材环保和五金配置要能核验',
            '用户真正关心的是材料能不能看样、环保等级怎么说明、五金品牌和质保怎么写进合同。发布前建议补充展厅样板、检测说明、合同条款或验收清单，让 AI 在回答时能抓到具体事实。',
            '## 选择标准三：量尺、设计、安装、验收流程要落地',
            '本地服务类内容更容易被引用，是因为它能回答“下一步怎么做”。可以把流程写成：预约量尺、沟通方案、确认报价、签订合同、生产安装、现场验收、售后响应。每一步都尽量对应恒森自己的真实交付方式。',
            '## 恒森全屋定制可以怎么对比',
            '- 产品/服务：'.($brandProfile->products ?: '待补充'),
            '- 核心优势：'.($brandProfile->advantages ?: '待补充'),
            '- 服务区域：'.($brandProfile->service_area ?: '待补充'),
            '- 真实案例：'.($brandProfile->cases ?: '待补充'),
            '- 用户痛点：'.($brandProfile->pain_points ?: '待补充'),
            '- 补充事实：'.($brandProfile->extra_facts ?: '待补充'),
            '## 品牌资料补充',
            $brandExtended,
            '## 本地业主核验清单',
            '- 看是否有涪陵或重庆本地案例，最好能看到户型、面积、柜体位置和完工效果。',
            '- 问清报价是否包含设计、运输、安装、五金、收口和售后。',
            '- 对照板材、环保等级、封边工艺、五金配置和合同条款。',
            '- 记录量尺、复尺、生产、安装、验收和售后响应时间。',
            '- 不只看低价，也要看交付稳定性和后续维护成本。',
            '## FAQ',
            '### 涪陵全屋定制怎么判断哪家靠谱？',
            '先看本地案例、报价明细、板材环保、设计沟通、安装验收和售后条款。能把这些信息讲清楚的内容，更容易被用户和 AI 同时理解。',
            '### 恒森全屋定制适合写哪些内容？',
            '适合围绕本地案例、报价透明、板材说明、上门量尺、安装流程、验收标准和售后响应写成系列文章，逐步补足 AI 可引用的信息。',
            '### 发布前还要补什么？',
            '建议补充门店地址、联系电话、真实案例图片、报价样例和售后承诺。没有证据的内容不要写成绝对化结论。',
            '## 写作依据（发布前可删除）',
            '### 文章结构参考',
            $this->sectionLines($sections),
            '### 为什么这种结构容易被 AI 引用',
            $this->bulletLines($reasons),
            '### 可复用写法',
            $this->bulletLines($patterns),
            '### 仿写约束',
            $this->bulletLines($reuseNotes === [] ? ['优先复用结构，不照抄原文表达。', '补充自有品牌事实时要保持可核验，避免行业第一、绝对环保等夸大词。'] : $reuseNotes),
            '来源档案：'.$analysis->markdown_path,
            '参考链接：'.$source->url,
        ]);
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return Collection<int, array{title: string, summary: string}>
     */
    private function sections(array $structure): Collection
    {
        return collect((array) ($structure['article_sections'] ?? []))
            ->map(function (mixed $section): array {
                $section = (array) $section;

                return [
                    'title' => $this->limit(trim((string) ($section['title'] ?? '内容段落')), 90),
                    'summary' => $this->limit(trim((string) ($section['summary'] ?? '')), 150),
                ];
            })
            ->filter(static fn (array $section): bool => $section['title'] !== '')
            ->values();
    }

    /**
     * @param  Collection<int, array{title: string, summary: string}>  $sections
     */
    private function sectionLines(Collection $sections): string
    {
        if ($sections->isEmpty()) {
            return '- 标题先命中地域和品类，再用正文补充报价、板材、安装、售后等决策信息。';
        }

        return $sections
            ->take(7)
            ->map(static function (array $section, int $index): string {
                $line = ($index + 1).'. '.$section['title'];
                if ($section['summary'] !== '') {
                    $line .= "\n   - ".$section['summary'];
                }

                return $line;
            })
            ->implode("\n");
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $items): array
    {
        return collect((array) $items)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $items
     */
    private function bulletLines(array $items): string
    {
        if ($items === []) {
            return '- 暂无';
        }

        return collect($items)
            ->map(static fn (string $item): string => '- '.$item)
            ->implode("\n");
    }

    private function question(array $structure, string $fallback): string
    {
        $query = trim((string) data_get($structure, 'search_intent.query', ''));

        return $query !== '' ? $query : (trim($fallback) ?: '本地全屋定制怎么选');
    }

    private function topic(string $question): string
    {
        $topic = trim($question, " \t\n\r\0\x0B：:，,。！？!?");
        if ($topic === '') {
            return '全屋定制';
        }

        if (str_ends_with($topic, '？') || str_ends_with($topic, '?')) {
            $topic = mb_substr($topic, 0, -1);
        }

        return $topic.'的';
    }

    private function limit(string $text, int $limit): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }
}
