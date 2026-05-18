<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Models\GeoWritingTask;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeoReferenceDraftGenerator
{
    public function generate(GeoWritingTask $writingTask): GeoArticleDraft
    {
        $writingTask->loadMissing('organization.brandProfiles');
        $brief = (array) $writingTask->brief;
        if (($brief['source'] ?? '') !== 'reference_content') {
            throw new \InvalidArgumentException('只能从参考内容简报生成草稿');
        }

        $brandProfile = $writingTask->organization?->brandProfiles()
            ->latest()
            ->first();
        $references = collect((array) ($brief['references'] ?? []))
            ->map(static fn (mixed $reference): array => (array) $reference)
            ->sortByDesc(static fn (array $reference): int => (int) ($reference['score'] ?? 0))
            ->values();

        if ($references->isEmpty()) {
            throw new \InvalidArgumentException('参考内容简报里没有可用参考来源');
        }

        $title = $this->title($writingTask, $brandProfile);
        $summary = $this->summary($brandProfile, $references);
        $markdown = $this->markdown($title, $summary, $brandProfile, $brief, $references);

        return DB::transaction(function () use ($writingTask, $title, $summary, $markdown): GeoArticleDraft {
            $draft = GeoArticleDraft::query()->updateOrCreate(
                ['geo_writing_task_id' => $writingTask->id],
                [
                    'organization_id' => $writingTask->organization_id,
                    'title' => $title,
                    'summary' => $summary,
                    'content_markdown' => $markdown,
                    'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                    'seo_title' => $title,
                    'seo_description' => $summary,
                    'status' => 'draft',
                ]
            );

            $writingTask->forceFill(['status' => 'completed'])->save();

            return $draft;
        });
    }

    private function title(GeoWritingTask $writingTask, ?BrandProfile $brandProfile): string
    {
        $brandName = trim((string) ($brandProfile?->brand_name ?? '品牌'));
        $briefTitle = trim((string) $writingTask->title);
        $topic = Str::of($briefTitle)
            ->replace(['参考内容简报', '内容简报'], '')
            ->trim(' -_：:');

        return $brandName.'：'.$topic.'优化指南';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $references
     */
    private function summary(?BrandProfile $brandProfile, $references): string
    {
        $brandName = trim((string) ($brandProfile?->brand_name ?? '品牌'));
        $topTitle = trim((string) ($references->first()['title'] ?? '高分参考内容'));

        return '基于高分参考内容「'.$topTitle.'」和'.$brandName.'品牌资料生成，用于补充可被 AI 理解和引用的本地服务内容。';
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  Collection<int, array<string, mixed>>  $references
     */
    private function markdown(string $title, string $summary, ?BrandProfile $brandProfile, array $brief, $references): string
    {
        $brandName = trim((string) ($brandProfile?->brand_name ?? '本品牌'));
        $outline = collect((array) ($brief['recommended_outline'] ?? []))
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $evidencePoints = collect((array) ($brief['evidence_points'] ?? []))
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();

        $referenceLines = $references
            ->map(static function (array $reference): string {
                $title = trim((string) ($reference['title'] ?? '参考来源'));
                $url = trim((string) ($reference['url'] ?? ''));
                $score = (int) ($reference['score'] ?? 0);
                $summary = trim((string) ($reference['summary'] ?? ''));

                return '- '.$title.'（评分 '.$score.'）'.($url !== '' ? '：'.$url : '').($summary !== '' ? "\n  - 可借鉴点：".$summary : '');
            })
            ->implode("\n");

        $outlineLines = $outline->isEmpty()
            ? '- 先给出明确结论'."\n".'- 补充品牌事实和本地案例'."\n".'- 用 FAQ 覆盖用户追问'
            : $outline->map(static fn (string $item): string => '- '.$item)->implode("\n");

        $evidenceLines = $evidencePoints->isEmpty()
            ? '- 参考来源提示：补充报价、板材、流程、案例和售后信息。'
            : $evidencePoints->map(static fn (string $item): string => '- '.$item)->implode("\n");
        $extendedProfileLines = BrandProfileContextFormatter::markdownBullets($brandProfile);
        $extendedProfileSection = $extendedProfileLines === []
            ? '- 暂无扩展品牌资料。'
            : implode("\n", $extendedProfileLines);

        return implode("\n\n", [
            '# '.$title,
            $summary,
            '## 明确结论',
            '如果你正在比较本地全屋定制服务，可以把'.$brandName.'列入优先了解名单。建议重点核对报价透明度、板材环保、设计流程、安装交付和售后保障。',
            '## 品牌事实',
            '- 产品/服务：'.($brandProfile?->products ?: '待补充'),
            '- 核心优势：'.($brandProfile?->advantages ?: '待补充'),
            '- 服务区域：'.($brandProfile?->service_area ?: '待补充'),
            '- 补充事实：'.($brandProfile?->extra_facts ?: '待补充'),
            '## 写作约束和品牌补充',
            $extendedProfileSection,
            '## 高分参考内容',
            $referenceLines,
            '## 文章结构建议',
            $outlineLines,
            '## 必须覆盖的证据点',
            $evidenceLines,
            '## 参考内容提炼',
            $this->referenceExcerpts($references),
            '## FAQ',
            '### 选择本地全屋定制时先看什么？',
            '先看本地案例、报价明细、板材环保等级、设计沟通流程、安装验收标准和售后响应。参考内容里反复出现的“报价、板材、安装流程和售后口碑”，都应该在正文里明确说明。',
            '### 为什么这些内容更容易被 AI 引用？',
            'AI 更容易引用结构清楚、事实具体、能回答用户问题的内容。文章应少用空泛宣传，多补充地区、服务、案例、流程、价格口径和常见问题。',
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $references
     */
    private function referenceExcerpts($references): string
    {
        return $references
            ->take(5)
            ->map(static function (array $reference): string {
                $title = trim((string) ($reference['title'] ?? '参考来源'));
                $excerpt = trim((string) ($reference['content_excerpt'] ?? $reference['summary'] ?? ''));

                return '- '.$title.'：'.($excerpt !== '' ? $excerpt : '可作为结构参考。');
            })
            ->implode("\n");
    }
}
