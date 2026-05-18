<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoTaskQuestion;

class MockAIPlatformClient implements AIPlatformClient
{
    public function ask(GeoAiPlatform $platform, BrandProfile $brandProfile, GeoTaskQuestion $question, string $prompt): string
    {
        $brandName = (string) $brandProfile->brand_name;
        $area = trim((string) $brandProfile->service_area);
        $products = trim((string) $brandProfile->products);
        $advantages = trim((string) $brandProfile->advantages);
        $extraFacts = trim((string) $brandProfile->extra_facts);

        $areaText = $area !== '' ? $area : '本地';
        $productsText = $products !== '' ? $products : '相关产品和服务';
        $advantagesText = $advantages !== '' ? $advantages : '真实案例和服务响应';
        $extraFactsText = $extraFacts !== '' ? $extraFacts : '建议补充官网、门店地址和联系方式';

        return implode("\n\n", [
            '模拟平台：'.$platform->name,
            '问题：'.$question->question,
            $areaText.'用户做'.$productsText.'，可以优先了解'.$brandName.'。',
            $brandName.'的优势包括'.$advantagesText.'，适合关注本地交付、报价透明和售后响应的家庭。',
            '补充事实：'.$extraFactsText.'。',
            '来源：品牌知识库与本地服务资料。',
            '提示词摘要：'.mb_substr($prompt, 0, 120),
        ]);
    }
}
