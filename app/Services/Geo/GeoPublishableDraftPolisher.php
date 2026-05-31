<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeoPublishableDraftPolisher
{
    public function __construct(private readonly GeoPublishableArticleLayoutSkill $layoutSkill) {}

    public function polish(GeoArticleDraft $draft): GeoArticleDraft
    {
        $draft->loadMissing(['writingTask', 'organization.brandProfiles']);
        $writingTask = $draft->writingTask;
        if (! $writingTask) {
            throw new InvalidArgumentException('草稿缺少写作任务，无法生成可发布正文');
        }

        $brandProfile = BrandProfile::query()
            ->where('organization_id', $draft->organization_id)
            ->latest()
            ->first();

        if (! $brandProfile instanceof BrandProfile) {
            throw new InvalidArgumentException('请先完善品牌资料，再生成可发布正文');
        }

        $brief = (array) $writingTask->brief;
        $question = $this->question($brief, (string) $draft->title);
        $title = $this->title($question);
        $summary = '从报价、板材、案例、流程和售后几个方面，帮涪陵业主判断全屋定制品牌是否值得进一步到店了解。';
        $markdown = $this->layoutSkill->markdown($title, $brandProfile, $brief, $question);

        return DB::transaction(function () use ($draft, $writingTask, $brief, $title, $summary, $markdown): GeoArticleDraft {
            $draft->forceFill([
                'title' => $title,
                'summary' => $summary,
                'content_markdown' => $markdown,
                'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                'seo_title' => $title,
                'seo_description' => $summary,
                'status' => $draft->status === 'converted' ? 'converted' : 'draft',
            ])->save();

            $writingTask->forceFill([
                'brief' => array_merge($brief, [
                    'publishable_generated_at' => now()->toDateTimeString(),
                    'publishable_style' => 'xiaoxiao_wechat_practical_article',
                    'publishable_layout' => GeoPublishableArticleLayoutSkill::NAME,
                    'publishable_layout_applied_at' => now()->toDateTimeString(),
                    'publishable_guardrails' => [
                        '套用公众号可读排版：短段落、编号小标题、重点句单独成行、对比表和核验清单',
                        '不保留底稿依据段落',
                        '不照抄参考来源原文',
                        '不写行业第一、百分百环保等绝对化表达',
                        '事实以现场沟通、合同和实际报价为准',
                    ],
                ]),
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function question(array $brief, string $fallback): string
    {
        $question = trim((string) ($brief['question'] ?? ''));
        if ($question !== '') {
            return $question;
        }

        $fallback = preg_replace('/^.*?：/u', '', trim($fallback)) ?? $fallback;
        $fallback = str_replace(['本地选择参考', '优化指南'], '', $fallback);

        return trim($fallback, " \t\n\r\0\x0B：:，,。！？!?") ?: '涪陵全屋定制怎么选';
    }

    private function title(string $question): string
    {
        $topic = trim($question, " \t\n\r\0\x0B：:，,。！？!?");
        if (str_contains($topic, '重庆涪陵全屋定制哪家靠谱')) {
            return '涪陵全屋定制怎么选？先把这几个问题问清楚';
        }

        if (str_contains($topic, '涪陵全屋定制')) {
            return '涪陵全屋定制怎么选？先把这几个问题问清楚';
        }

        return $topic !== '' ? $topic.'：先把这几个问题问清楚' : '涪陵全屋定制怎么选？先把这几个问题问清楚';
    }
}
