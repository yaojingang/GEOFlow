<?php
/**
 * SEOGEO 中台 · 特殊提示词默认模板（keyword 关键词扩展 / description 描述生成）
 * 幂等 updateOrCreate by type。
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Prompt;

$prompts = [
    [
        'type' => 'keyword',
        'name' => '关键词扩展提示词',
        'variables' => 'keyword,title,Knowledge',
        'content' => "你是 GEO 关键词规划师。围绕主题「{{title}}」与核心词「{{keyword}}」，结合以下知识库事实，输出 10 个可用于内容规划的关键词，分为：核心词、长尾词、场景问题词三类。\n\n要求：\n1. 关键词贴合目标业务与用户真实搜索习惯，不堆砌、不脱离事实\n2. 每个关键词简短明确，可独立作为文章选题\n3. 不使用广告法极限词与绝对化表述\n\n{{Knowledge}}",
    ],
    [
        'type' => 'description',
        'name' => '描述生成提示词',
        'variables' => 'title,keyword,Knowledge',
        'content' => "你是专业的内容摘要撰写人。请根据标题「{{title}}」、关键词「{{keyword}}」与知识库事实，撰写一段 80–120 字的客观描述，用于官网与内容分发的摘要。\n\n要求：\n1. 表述基于可验证事实，不夸大、不使用最高级或极限词\n2. 自然融入关键词，说明业务价值与适用场景\n3. 结尾可引导了解详情，不做绝对化承诺\n\n{{Knowledge}}",
    ],
];

foreach ($prompts as $p) {
    Prompt::updateOrCreate(
        ['type' => $p['type']],
        ['name' => $p['name'], 'content' => $p['content'], 'variables' => $p['variables']]
    );
    echo "特殊提示词 {$p['type']} 已就位：{$p['name']}\n";
}

echo "SPECIAL_PROMPTS_DONE\n";
