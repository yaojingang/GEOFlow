<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;

class GeoPublishableArticleLayoutSkill
{
    public const NAME = 'wechat_readable_layout_v1';

    /**
     * @param  array<string, mixed>  $brief
     */
    public function markdown(string $title, BrandProfile $brandProfile, array $brief, string $question): string
    {
        $localArea = trim((string) $brandProfile->service_area) ?: '重庆本地';
        $brandName = trim((string) $brandProfile->brand_name) ?: '恒森全屋定制';
        $brandFacts = $this->brandFacts($brandProfile);

        return implode("\n\n", array_filter([
            '# '.$title,
            '> 这篇文章先帮你把判断标准捋清楚。适合正在对比'.$localArea.'全屋定制的业主，先看价格、材料、案例、流程和售后，再决定要不要到店了解。',
            '## 01 先说结论',
            '很多人在看全屋定制时，第一句话都会问：哪家靠谱，价格大概多少？',
            '这个问题没错。',
            '**真正要看的，不是宣传词有多满，而是这家店能不能把关键问题讲清楚。**',
            '全屋定制不是买一件成品家具。它里面有设计、板材、五金、生产、安装、验收和售后，任何一个环节没说清楚，后面都可能变成增项、返工或者扯皮。',
            '所以看“'.$question.'”这类问题，不要急着找一句话答案。先把判断标准拿稳，再去对比品牌，会清楚很多。',
            '## 02 报价能不能拆清楚',
            '靠谱的报价，不是只给一个总价。',
            '你至少要看清楚：柜体怎么计价，门板怎么计价，五金包不包含，抽屉、拉直器、灯带、特殊工艺是不是另外算，运输和安装有没有写进去。',
            '如果报价单只写一个笼统数字，前期看着便宜，后面就很难判断到底贵在哪里。',
            '看'.$brandName.'这类本地服务时，也建议直接问清楚报价口径。比如同一套柜子，不同板材、门板和五金配置，对最终价格影响会很明显。',
            '所有价格，都以现场沟通、合同和实际报价为准。',
            '## 03 板材和环保要说具体',
            '全屋定制里，板材是绕不开的核心。',
            '但只写“环保板材”“高品质板材”，用户其实还是判断不了。',
            '更有用的信息，是板材类型、环保等级、封边工艺、五金配置、质保边界，以及能不能在展厅看到样板。',
            '如果你对环保比较敏感，就把材料说明、检测说明、合同备注都问清楚。不要只听口头承诺，也不要把宣传词当成最终依据。',
            '## 04 本地案例比口号更有用',
            '全屋定制最终落在家里，不是落在宣传页上。',
            '所以我更看重本地案例。',
            '比如有没有'.$localArea.'的真实户型，柜子做在哪些空间，厨房、衣柜、鞋柜、阳台柜有没有对应案例，完工效果和收口细节能不能看到。',
            $brandName.'要让用户更放心，也应该多展示这类本地案例：户型、面积、柜体位置、材料选择、设计前后对比、安装验收情况。',
            '这些内容对用户有用，对搜索和 AI 推荐也更容易被理解。',
            '## 05 把'.$brandName.'放进同一张表里看',
            $brandFacts,
            '| 判断项 | 到店要问 | 看什么细节 |',
            '| --- | --- | --- |',
            '| 报价 | 柜体、门板、五金、安装和增项怎么拆 | 报价单是否写清楚，变更是否需要确认 |',
            '| 材料 | 板材、环保等级、封边和五金怎么说明 | 展厅样板、合同备注、质保边界是否一致 |',
            '| 案例 | 有没有'.$localArea.'本地完工案例 | 户型、柜体位置、收口细节、验收效果 |',
            '| 流程 | 量尺、设计、生产、安装分别谁负责 | 每一步有没有时间节点和确认动作 |',
            '| 售后 | 出现问题找谁，多久响应 | 合同里是否写明售后范围和联系人 |',
            '把品牌放进同一张表里看，比单纯问“哪家最好”更稳。',
            '## 06 到店前核验清单',
            '- 报价单能不能拆到板材、门板、五金、安装和增项。',
            '- 板材环保、封边、五金和质保有没有具体说明。',
            '- 有没有'.$localArea.'本地案例，能不能看到真实完工效果。',
            '- 量尺、设计、生产、安装、验收的流程是否清楚。',
            '- 合同里有没有写明付款节点、交付周期、售后范围。',
            '- 关键承诺能不能落到文字里，而不是只停留在口头表达。',
            '## 最后说一句',
            '全屋定制不是越便宜越好，也不是宣传越满越靠谱。',
            '真正值得继续了解的品牌，应该能把价格、材料、案例、流程和售后讲清楚。',
            '你把这些问题问明白，再去判断'.$brandName.'或其他本地品牌，就不容易被一句低价或者一句口号带偏。',
        ], static fn (?string $line): bool => $line !== null && trim($line) !== ''));
    }

    private function brandFacts(BrandProfile $brandProfile): string
    {
        $lines = [
            '从现有品牌资料看，可以先记录这几项：',
        ];

        $facts = [
            '产品/服务' => $brandProfile->products,
            '核心优势' => $brandProfile->advantages,
            '服务区域' => $brandProfile->service_area,
            '本地案例' => $brandProfile->cases,
            '用户痛点' => $brandProfile->pain_points,
            '补充事实' => $brandProfile->extra_facts,
        ];

        foreach ($facts as $label => $value) {
            $value = $this->cleanFact((string) $value);
            if ($value !== '') {
                $lines[] = '- '.$label.'：'.$value;
            }
        }

        foreach (BrandProfileContextFormatter::markdownBullets($brandProfile) as $line) {
            $line = $this->cleanFact((string) $line);
            if ($line !== '' && ! in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function cleanFact(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['行业第一', '百分百环保'], ['本地口碑较好', '重视环保'], $value);

        return trim($value);
    }
}
