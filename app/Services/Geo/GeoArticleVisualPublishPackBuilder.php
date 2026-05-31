<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeoArticleVisualPublishPackBuilder
{
    public const VERSION = 'visual_publish_pack_v1';

    public function build(GeoArticleDraft $draft): GeoArticleDraft
    {
        $draft->loadMissing(['writingTask']);
        $writingTask = $draft->writingTask;
        if (! $writingTask) {
            throw new InvalidArgumentException('草稿缺少写作任务，无法生成配图与发布包');
        }

        $brandProfile = BrandProfile::query()
            ->where('organization_id', $draft->organization_id)
            ->latest()
            ->first();

        if (! $brandProfile instanceof BrandProfile) {
            throw new InvalidArgumentException('请先完善品牌资料，再生成配图与发布包');
        }

        $brief = (array) $writingTask->brief;
        $package = $this->package($draft, $brandProfile, $brief);

        return DB::transaction(function () use ($draft, $writingTask, $brief, $package): GeoArticleDraft {
            $writingTask->forceFill([
                'brief' => array_merge($brief, [
                    'visual_pack_generated_at' => now()->toDateTimeString(),
                    'visual_publish_package' => $package,
                ]),
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function package(GeoArticleDraft $draft, BrandProfile $brandProfile, array $brief): array
    {
        $title = trim((string) $draft->title) ?: '全屋定制选择指南';
        $brandName = trim((string) $brandProfile->brand_name) ?: '恒森全屋定制';
        $localArea = trim((string) $brandProfile->service_area) ?: '重庆本地';
        $question = trim((string) ($brief['question'] ?? ''));
        if ($question === '') {
            $question = $title;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toDateTimeString(),
            'target_channels' => ['站内文章', '微信公众号草稿', '小红书二次拆条'],
            'article_title' => $title,
            'items' => [
                [
                    'type' => 'cover_image',
                    'title' => '封面图',
                    'placement' => '文章封面 / 微信公众号首图',
                    'source_mode' => 'ai_design_or_brand_photo',
                    'goal' => '第一眼说明主题，让用户知道这篇文章是在讲'.$localArea.'全屋定制怎么判断。',
                    'prompt' => '生成一张适合公众号和站内文章的横版封面图，主题是「'.$title.'」。画面风格干净、专业、偏家居定制行业，出现柜体、板材样块、报价单、量尺工具等元素，不要生成虚假的真实门店或客户现场。画面中文主标题使用「'.$title.'」，中文文字使用微软雅黑 / Microsoft YaHei 字体，文字清晰居中，不要水印，不要 logo。',
                    'safety_note' => '如果有真实门店、展厅或案例照片，优先用真实图片做封面底图；没有真实素材时，只做知识型封面。',
                ],
                [
                    'type' => 'checklist_infographic',
                    'title' => '到店核验清单图',
                    'placement' => '放在「到店前核验清单」段落前后',
                    'source_mode' => 'ai_infographic',
                    'goal' => '把正文里的检查项变成一眼能保存的清单。',
                    'prompt' => '生成一张竖版知识清单信息图，标题为「到店前先问清这 6 件事」，内容包括：报价是否拆明细、板材环保是否具体、五金配置是否写清、本地案例是否能看、量尺到安装流程是否明确、售后范围是否落合同。整体适合全屋定制业主保存转发，中文文字使用微软雅黑 / Microsoft YaHei 字体，排版清晰，不能有错别字，不要夸大承诺。',
                    'safety_note' => '这张图可以用 AI 生成，因为它是清单知识图，不涉及真实案例伪造。',
                ],
                [
                    'type' => 'process_diagram',
                    'title' => '量尺到安装流程图',
                    'placement' => '放在流程或售后段落中',
                    'source_mode' => 'diagram_or_mermaid',
                    'goal' => '把服务流程讲清楚，降低用户阅读成本。',
                    'prompt' => '制作一张全屋定制服务流程图，流程为：预约沟通 -> 上门量尺 -> 方案设计 -> 报价确认 -> 合同签订 -> 生产排期 -> 现场安装 -> 验收售后。适合公众号正文插图，中文文字使用微软雅黑 / Microsoft YaHei 字体，线条清楚，节点少而准，不要添加未经确认的周期、价格或承诺。',
                    'safety_note' => '如果品牌有自己的真实流程，以品牌实际流程为准；没有确认周期时不要写具体天数。',
                ],
                [
                    'type' => 'real_case_material',
                    'title' => '真实案例图片',
                    'placement' => '放在本地案例段落',
                    'source_mode' => 'real_material_required',
                    'goal' => '补强本地可信度，让用户看到真实交付。',
                    'prompt' => '需要上传或选择'.$brandName.'在'.$localArea.'的真实案例图片，例如厨房柜、衣柜、鞋柜、阳台柜、板材样品、展厅样板或安装现场。不要伪造案例现场，不要用 AI 生成假客户家、假门店、假施工图。若需要加文字，中文文字使用微软雅黑 / Microsoft YaHei 字体，只做简单标注。',
                    'safety_note' => '必须使用真实素材。没有真实素材时，本图位保持待补充，不用 AI 图替代案例。',
                ],
            ],
            'publish_sop' => [
                '先确认正文没有夸大承诺和未核实案例。',
                '先做封面图和知识图，再补真实案例图。',
                '正文图片控制在 3-5 张，避免把所有图片堆到文末。',
                '上传公众号草稿前，把第一张图作为封面，其余图片按段落插入正文。',
                '图片终审不通过，不进入发布或草稿上传。',
            ],
            'guardrails' => [
                '真实案例、门店、客户现场必须用真实素材，不用 AI 伪造。',
                '所有图片文字默认使用微软雅黑 / Microsoft YaHei。',
                '不写行业第一、百分百环保、绝对低价等高风险表达。',
                '价格、周期、材料等级以合同、现场沟通和品牌实际资料为准。',
            ],
            'source_question' => $question,
        ];
    }
}
