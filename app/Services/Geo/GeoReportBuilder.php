<?php

namespace App\Services\Geo;

use App\Models\AiModel;
use App\Models\GeoTask;
use App\Support\Site\ArticleHtmlPresenter;

class GeoReportBuilder
{
    /**
     * @return array{title:string, summary:string, markdown_report:string, html_report:string}
     */
    public function build(GeoTask $task): array
    {
        $task->loadMissing(['brandProfile', 'answers.question', 'answers.score']);
        $brandName = (string) $task->brandProfile->brand_name;
        $score = (int) $task->total_score;
        $reportMode = (string) ($task->report_mode ?: 'with_recommendations');
        $summary = $this->summaryForScore($score);
        $platformNames = $this->platformNames($task->answers->pluck('platform_code')->all());

        $rows = $task->answers
            ->map(function ($answer) use ($platformNames): string {
                $score = $answer->score;
                $question = $answer->question?->question ?? '';
                $platformName = $platformNames[(string) $answer->platform_code] ?? (string) $answer->platform_code;

                return '| '.str_replace('|', '\\|', $platformName).' | '.str_replace('|', '\\|', $question).' | '
                    .($score?->brand_mentioned ? '是' : '否').' | '
                    .($score?->is_recommended ? '是' : '否').' | '
                    .(int) ($score?->score ?? 0).' |';
            })
            ->implode("\n");

        $sections = [
            '# '.$brandName.' GEO 诊断报告',
            '## 报告类型',
            $reportMode === 'visibility_only' ? '客户可读可见度报告' : '内部优化建议报告',
            '## 诊断结论',
            '- 综合得分：'.$score,
            '- 结论：'.$summary,
            '## 平台回答评分',
            "| 平台 | 问题 | 提及品牌 | 正向推荐 | 分数 |\n|---|---|---|---|---|\n".$rows,
        ];

        if ($reportMode === 'with_recommendations') {
            $sections = array_merge($sections, [
                '## 优化建议',
                '- 继续补充品牌官网、门店地址、联系方式、案例和常见问题内容。',
                '- 围绕低曝光问题词生成结构化文章，让 AI 更容易识别品牌实体和服务范围。',
                '- 保留同一评分口径持续复测，区分模拟平台和真实模型的趋势变化。',
            ]);
        }

        $markdown = implode("\n\n", $sections);

        return [
            'title' => $brandName.' GEO 诊断报告',
            'summary' => $summary,
            'markdown_report' => $markdown,
            'html_report' => ArticleHtmlPresenter::markdownToHtml($markdown),
        ];
    }

    private function summaryForScore(int $score): string
    {
        if ($score >= 80) {
            return 'AI 可见度较好，品牌在回答中稳定出现并被正向推荐。';
        }

        if ($score >= 60) {
            return '已有基础曝光，但仍需要补充可引用事实和更多问题词内容。';
        }

        if ($score >= 30) {
            return '品牌偶尔出现，推荐稳定性不足，需要加强内容资产。';
        }

        return '品牌基本不可见，需要先建立清晰的品牌实体和内容来源。';
    }

    /**
     * @param  array<int, mixed>  $platformCodes
     * @return array<string, string>
     */
    private function platformNames(array $platformCodes): array
    {
        $names = [
            GeoWebWorkbenchClient::PLATFORM_CODE => GeoWebWorkbenchClient::PLATFORM_NAME,
            'deepseek_mock' => 'DeepSeek 模拟',
            'kimi_mock' => 'Kimi 模拟',
            'qwen_mock' => '通义千问模拟',
        ];

        $modelIds = collect($platformCodes)
            ->map(static fn (mixed $code): string => (string) $code)
            ->map(function (string $code): int {
                if (preg_match('/^ai_model:(\d+)$/', $code, $matches) !== 1) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($modelIds === []) {
            return $names;
        }

        AiModel::query()
            ->whereIn('id', $modelIds)
            ->get(['id', 'name', 'model_id'])
            ->each(function (AiModel $model) use (&$names): void {
                $label = trim((string) $model->name);
                $modelId = trim((string) $model->model_id);
                if ($modelId !== '') {
                    $label .= $label !== '' ? ' · '.$modelId : $modelId;
                }

                $names['ai_model:'.(int) $model->id] = $label !== '' ? $label : '真实 AI 模型 #'.(int) $model->id;
            });

        return $names;
    }
}
