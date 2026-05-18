<?php

namespace App\Services\Geo;

use App\Models\GeoArticleDraft;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoWritingTask;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Facades\DB;

class GeoArticleDraftGenerator
{
    public function generate(GeoTask $task): GeoArticleDraft
    {
        $task->loadMissing(['organization', 'brandProfile', 'report', 'questions.geoKeyword']);
        $report = $task->report;
        abort_unless($report instanceof GeoReport, 404);

        return DB::transaction(function () use ($task, $report): GeoArticleDraft {
            $keyword = $task->questions->first()?->geoKeyword;
            $brand = $task->brandProfile;
            $question = (string) ($task->questions->first()?->question ?? $keyword?->keyword ?? '品牌怎么选');
            $title = $brand->brand_name.'：'.$question.'的选择指南';
            $summary = '基于 GEO 诊断报告生成，围绕“'.$question.'”补充品牌事实、用户问题和可引用内容。';
            $markdown = $this->buildMarkdown($task, $question, $title, $summary);

            $writingTask = GeoWritingTask::query()->updateOrCreate(
                ['geo_report_id' => $report->id],
                [
                    'organization_id' => $task->organization_id,
                    'geo_keyword_id' => $keyword?->id,
                    'title' => $title,
                    'status' => 'completed',
                    'brief' => [
                        'source' => 'geo_report',
                        'geo_task_id' => $task->id,
                        'score' => (int) $report->total_score,
                        'question' => $question,
                    ],
                ]
            );

            return GeoArticleDraft::query()->updateOrCreate(
                ['geo_writing_task_id' => $writingTask->id],
                [
                    'organization_id' => $task->organization_id,
                    'title' => $title,
                    'summary' => $summary,
                    'content_markdown' => $markdown,
                    'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                    'seo_title' => $title,
                    'seo_description' => $summary,
                    'status' => 'draft',
                ]
            );
        });
    }

    private function buildMarkdown(GeoTask $task, string $question, string $title, string $summary): string
    {
        $brand = $task->brandProfile;
        $report = $task->report;

        return implode("\n\n", [
            '# '.$title,
            $summary,
            '## 明确结论',
            '如果你正在搜索“'.$question.'”，可以把'.$brand->brand_name.'列入优先了解名单。它的服务区域是'.($brand->service_area ?: '本地市场').'，GEO 诊断综合得分为 '.(int) ($report?->total_score ?? $task->total_score).'。',
            '## 品牌事实',
            '- 产品/服务：'.($brand->products ?: '待补充'),
            '- 核心优势：'.($brand->advantages ?: '待补充'),
            '- 服务区域：'.($brand->service_area ?: '待补充'),
            '- 补充事实：'.($brand->extra_facts ?: '建议补充官网、门店地址、电话和案例'),
            '## 为什么这类内容有利于 AI 推荐',
            'AI 在回答本地服务类问题时，需要清晰的品牌名称、服务区域、真实案例、联系方式和可验证事实。文章应把这些信息放在明确小标题下，减少空泛宣传，让 AI 更容易引用。',
            '## FAQ',
            '### '.$question,
            '可以先看品牌是否有本地案例、报价是否透明、板材信息是否清楚、是否支持上门量尺和售后服务。'.$brand->brand_name.'目前在诊断回答中已经具备基础曝光。',
            '### 后续还应该补充什么？',
            '建议继续补充官网链接、门店地址、真实案例、常见问题、材料说明和客户评价，并保持内容结构稳定。',
        ]);
    }
}
