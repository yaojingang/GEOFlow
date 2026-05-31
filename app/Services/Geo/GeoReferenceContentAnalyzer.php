<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoCitationPageSnapshot;
use App\Models\GeoCitationSource;
use App\Models\GeoReferenceContentAnalysis;
use App\Models\GeoReferenceContentScore;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GeoReferenceContentAnalyzer
{
    public function analyze(Organization $organization, GeoCitationSource $source): GeoReferenceContentAnalysis
    {
        $source->load(['searchAnswer.question', 'searchAnswer.opportunity', 'searchAnswer.searchRun.brandProfile']);
        $snapshot = $source->pageSnapshots()
            ->where('crawl_status', 'succeeded')
            ->with('latestScore')
            ->latest()
            ->first();

        if (! $snapshot instanceof GeoCitationPageSnapshot) {
            throw new InvalidArgumentException('请先成功采集页面内容，再生成本地分析档案');
        }

        $score = $snapshot->latestScore;
        if (! $score instanceof GeoReferenceContentScore) {
            throw new InvalidArgumentException('请先完成质量评分，再生成本地分析档案');
        }

        $brandProfile = $source->searchAnswer?->searchRun?->brandProfile
            ?? BrandProfile::query()->where('organization_id', $organization->id)->latest()->first();
        $structure = $this->structure($organization, $source, $snapshot, $score, $brandProfile);
        $markdown = $this->markdown($source, $snapshot, $score, $structure);
        $basePath = 'geo/reference-analyses/source-'.$source->id.'/'.now()->format('YmdHis').'-snapshot-'.$snapshot->id;
        $markdownPath = $basePath.'.md';
        $jsonPath = $basePath.'.json';

        Storage::disk('local')->put($markdownPath, $markdown);
        Storage::disk('local')->put($jsonPath, json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return GeoReferenceContentAnalysis::query()->create([
            'organization_id' => $organization->id,
            'geo_citation_source_id' => $source->id,
            'geo_citation_page_snapshot_id' => $snapshot->id,
            'geo_reference_content_score_id' => $score->id,
            'article_title' => $snapshot->title ?: $source->title,
            'structure_json' => $structure,
            'analysis_markdown' => $markdown,
            'markdown_path' => $markdownPath,
            'json_path' => $jsonPath,
            'analyzed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function structure(Organization $organization, GeoCitationSource $source, GeoCitationPageSnapshot $snapshot, GeoReferenceContentScore $score, ?BrandProfile $brandProfile): array
    {
        $title = trim((string) $snapshot->title);
        $description = trim((string) $snapshot->description);
        $content = trim((string) $snapshot->content_text);
        $query = trim((string) ($source->searchAnswer?->question?->question ?? $source->searchAnswer?->opportunity?->keyword ?? $title));
        $terms = $this->terms($organization, $brandProfile, $query, $source->domain);

        return [
            'source' => [
                'id' => (int) $source->id,
                'url' => $source->url,
                'domain' => $source->domain,
                'title' => $title,
                'score' => (int) $score->total_score,
                'suggested_usage' => $score->suggested_usage,
            ],
            'search_intent' => [
                'query' => $query,
                'matched_terms' => $this->matchedTerms($title.' '.$description.' '.$content, $terms),
            ],
            'article_sections' => $this->articleSections($title, $description, $content),
            'citation_reasons' => $this->citationReasons($source, $snapshot, $score, $terms),
            'writing_patterns' => $this->writingPatterns($snapshot),
            'reuse_notes' => $this->reuseNotes($snapshot, $score),
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function markdown(GeoCitationSource $source, GeoCitationPageSnapshot $snapshot, GeoReferenceContentScore $score, array $structure): string
    {
        $lines = [
            '# '.($snapshot->title ?: $source->domain ?: '引用来源分析'),
            '',
            '- 来源：'.$source->url,
            '- 域名：'.($source->domain ?: '未知'),
            '- 质量评分：'.(int) $score->total_score.' / 100',
            '- 本地快照：snapshot #'.(int) $snapshot->id,
            '- 分析时间：'.now()->toDateTimeString(),
            '',
            '## 文章结构拆解',
        ];

        foreach ((array) $structure['article_sections'] as $index => $section) {
            $lines[] = ($index + 1).'. '.(string) ($section['title'] ?? '内容段落');
            $summary = trim((string) ($section['summary'] ?? ''));
            if ($summary !== '') {
                $lines[] = '   - '.$summary;
            }
        }

        $lines[] = '';
        $lines[] = '## 为什么会被引用';
        foreach ((array) $structure['citation_reasons'] as $reason) {
            $lines[] = '- '.$reason;
        }

        $lines[] = '';
        $lines[] = '## 可复用写法';
        foreach ((array) $structure['writing_patterns'] as $pattern) {
            $lines[] = '- '.$pattern;
        }

        $lines[] = '';
        $lines[] = '## 本地正文摘要';
        $lines[] = Str::limit((string) $snapshot->content_text, 1200, '');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{title: string, summary: string}>
     */
    private function articleSections(string $title, string $description, string $content): array
    {
        $sections = [];
        if ($title !== '') {
            $sections[] = [
                'title' => '标题层：'.$title,
                'summary' => $description !== '' ? $description : '标题承担搜索意图识别和点击判断。',
            ];
        }

        $sentences = collect(preg_split('/(?<=[。！？!?])\s*/u', $content) ?: [])
            ->map(static fn (string $sentence): string => trim($sentence))
            ->filter(static fn (string $sentence): bool => mb_strlen($sentence) >= 12)
            ->values();

        $cues = ['本地交付', '报价', '板材', '安装流程', '售后', '口碑', '案例', '门店', '电话', '环保', '合同', '验收', '行动步骤'];
        foreach ($cues as $cue) {
            $sentence = $sentences->first(static fn (string $candidate): bool => str_contains($candidate, $cue));
            if ($sentence !== null) {
                $sections[] = [
                    'title' => $cue,
                    'summary' => Str::limit($sentence, 180, ''),
                ];
            }

            if (count($sections) >= 7) {
                break;
            }
        }

        if (count($sections) <= 1) {
            foreach ($sentences->take(5) as $index => $sentence) {
                $sections[] = [
                    'title' => '正文段落 '.($index + 1),
                    'summary' => Str::limit($sentence, 180, ''),
                ];
            }
        }

        return array_values($sections);
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function citationReasons(GeoCitationSource $source, GeoCitationPageSnapshot $snapshot, GeoReferenceContentScore $score, array $terms): array
    {
        $text = trim((string) $snapshot->title.' '.(string) $snapshot->description.' '.(string) $snapshot->content_text);
        $reasons = [];

        if ($this->matchedTerms((string) $snapshot->title, $terms) !== [] || str_contains((string) $snapshot->title, '全屋定制')) {
            $reasons[] = '标题直接命中搜索意图';
        }

        if ((int) $score->relevance_score >= 70) {
            $reasons[] = '正文与关键词和本地场景高度相关';
        }

        if (preg_match('/20\d{2}|价格|报价|电话|地址|门店|㎡|元/u', $text) === 1) {
            $reasons[] = '包含年份、价格、门店、电话或地址等可核验信息';
        }

        if (preg_match('/建议|步骤|流程|对比|避坑|选择|验收|售后/u', $text) === 1) {
            $reasons[] = '提供选择标准或行动步骤，方便 AI 摘要引用';
        }

        if ((int) $score->total_score >= 75) {
            $reasons[] = '质量评分较高，适合进入核心参考池';
        }

        if ($source->domain !== '') {
            $reasons[] = '来自独立外部域名，可补充品牌自有资料之外的第三方信号';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return list<string>
     */
    private function writingPatterns(GeoCitationPageSnapshot $snapshot): array
    {
        $text = (string) $snapshot->content_text;
        $patterns = ['标题使用“地域 + 品类 + 推荐/价格/避坑/案例”的搜索型结构'];

        if (preg_match('/先|再|最后|步骤|流程/u', $text) === 1) {
            $patterns[] = '正文按“先判断标准，再补充证据，最后给行动步骤”展开';
        }

        if (preg_match('/价格|报价|板材|环保|售后|安装|验收/u', $text) === 1) {
            $patterns[] = '用报价、板材、环保、安装、售后这些用户决策词承接搜索意图';
        }

        if (preg_match('/重庆|涪陵|本地|门店|地址|电话/u', $text) === 1) {
            $patterns[] = '持续加入本地化词和门店线索，提升地域相关性';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @return list<string>
     */
    private function reuseNotes(GeoCitationPageSnapshot $snapshot, GeoReferenceContentScore $score): array
    {
        return [
            '优先复用结构，不照抄原文表达。',
            '补充自有品牌事实时要保持可核验，避免行业第一、绝对环保等夸大词。',
            '当前参考评分为 '.(int) $score->total_score.' 分，建议作为 '.($score->suggested_usage ?: 'reference').' 使用。',
            '正文摘要：'.Str::limit((string) $snapshot->content_summary, 180, ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function terms(Organization $organization, ?BrandProfile $brandProfile, string $query, string $domain): array
    {
        $values = [
            $organization->name,
            $query,
            $domain,
            $brandProfile?->brand_name,
            $brandProfile?->service_area,
            $brandProfile?->products,
            $brandProfile?->advantages,
        ];

        return collect($values)
            ->flatMap(static fn (mixed $value): array => preg_split('/[\s,，。；;、]+/u', (string) $value) ?: [])
            ->map(static fn (string $term): string => trim($term))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function matchedTerms(string $text, array $terms): array
    {
        return collect($terms)
            ->filter(static fn (string $term): bool => $term !== '' && str_contains($text, $term))
            ->values()
            ->all();
    }
}
