<?php
/**
 * SEOGEO 中台 · 广告法极限词 / 敏感词基础库
 * blocked = 不可发布；warning = 提醒修改。幂等 updateOrCreate。
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SensitiveWord;

$blocked = [
    '国家级' => '避免使用国家机关名义背书，改用可验证的资质与案例。',
    '最高级' => '广告法禁止使用最高级表述，改为具体数据对比。',
    '最佳' => '改为在特定范围内可验证的客观评价。',
    '最先进' => '改为可佐证的技术描述或第三方评测结论。',
    '第一品牌' => '不可宣称品牌排名第一，需提供权威机构排名证明。',
    '全网第一' => '不可宣称全网排名第一，改为可核验的市场数据。',
    '顶级' => '改为具体配置或规格说明。',
    '极品' => '改为客观质量描述。',
    '100%有效' => '不可承诺绝对效果，改为限定条件下的测试结果。',
    '根治' => '不可使用医疗式根治承诺，改为阶段性改善表述。',
    '包治百病' => '禁用夸大疗效表述。',
    '特效' => '改为具体功能与适用场景说明。',
    '万能' => '改为明确适用范围。',
    '绝无仅有' => '改为客观、可核验的稀缺性描述。',
    '史无前例' => '改为可佐证的事实表述。',
];

$warning = [
    '最好' => '建议改为可验证的具体评价。',
    '最低价' => '需标注价格对比范围与时间，避免绝对化。',
    '独家' => '需有真实独家合作证明，否则改为合作方式描述。',
    '永久' => '改为明确的期限或保障条款。',
    '领先' => '改为可佐证的排名或技术指标。',
    '首选' => '改为用户选择数据或场景说明。',
    '王牌' => '改为客观产品定位描述。',
    '冠军' => '需有权威赛事或排名证明。',
    '第一' => '需有权威排名依据，避免绝对化。',
    '唯一' => '需提供唯一性证明。',
    '完美' => '改为具体优势描述。',
    '超值' => '改为价格与价值的客观对比。',
];

foreach ($blocked as $word => $suggestion) {
    SensitiveWord::updateOrCreate(
        ['word' => $word],
        ['severity' => 'blocked', 'category' => 'advertising_law', 'is_enabled' => true, 'suggestion' => $suggestion, 'applies_to' => []]
    );
}
foreach ($warning as $word => $suggestion) {
    SensitiveWord::updateOrCreate(
        ['word' => $word],
        ['severity' => 'warning', 'category' => 'advertising_law', 'is_enabled' => true, 'suggestion' => $suggestion, 'applies_to' => []]
    );
}

echo "敏感词库已就位：blocked " . count($blocked) . " 条，warning " . count($warning) . " 条\n";
echo "SENSITIVE_SEED_DONE\n";
