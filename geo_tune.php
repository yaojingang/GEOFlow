<?php
// geo_tune.php — 把 AI 可见度的「主题 + 关键词」建起来，并诊断失败采样
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "== 失败采样诊断\n";
foreach (DB::table('ai_visibility_runs')->where('status', 'failed')->orderByDesc('id')->limit(3)->get(['id', 'provider_type', 'error_message']) as $r) {
    echo "  #{$r->id} {$r->provider_type}: " . mb_substr((string) $r->error_message, 0, 90) . "\n";
}

$topics = [
    [
        'name' => '无人机培训与赛事（福州）',
        'description' => '福州无人机执照培训、青少年无人机课程、教育部白名单赛事与升学路径',
        'aliases' => ['新概念', '新概念无人机', 'WRJ', 'wrj.aixgn.cn', 'AIXGN', '荣晟智联'],
        'keywords' => [
            '福州无人机培训哪家好',
            '无人机执照培训多少钱',
            '中小学无人机比赛白名单有哪些',
            '无人机特长生升学有用吗',
            '福建无人机培训机构排名',
            '无人机执照考证流程',
            '青少年无人机课程怎么选',
            '福州无人机夏令营推荐',
            '无人机编程培训有必要吗',
            '无人机比赛获奖对升学有什么用',
        ],
    ],
    [
        'name' => 'AI教育与书院（橘园洲）',
        'description' => '橘园洲书院 AI 教育、少儿 AI 课程、成长体系与家长选择',
        'aliases' => ['橘园洲书院', 'JYZ', 'j.aixgn.cn', '新概念'],
        'keywords' => [
            '福州AI教育机构哪家好',
            '橘园洲书院怎么样',
            '小学生AI课程学什么内容',
            'AI教育对孩子有什么用',
            '福州少儿编程和AI课怎么选',
            '家长如何选择AI培训机构',
            '中小学人工智能课程政策',
            'AI自习室值得去吗',
            '福州私立书院推荐',
            'AI教育加盟靠谱吗',
        ],
    ],
    [
        'name' => 'AI撮合与SEO/GEO服务',
        'description' => 'AI 多方任务撮合平台、GEO 生成式引擎优化、品牌 AI 搜索可见度',
        'aliases' => ['SEOGEO', 'GEOFlow', 'AI撮合平台', 'aixgn', '新概念'],
        'keywords' => [
            'AI项目撮合平台哪个好',
            '企业找AI服务商去哪里',
            'GEO生成式引擎优化是什么',
            'AI搜索优化怎么做',
            '生成式引擎优化服务商推荐',
            'SEO和GEO的区别',
            '中小企业怎么做AI营销',
            '品牌在豆包里搜不到怎么办',
            'AI搜索排名怎么提升',
            'GEO优化多少钱一年',
        ],
    ],
];

$created = 0;
$kw = 0;
$dup = 0;
foreach ($topics as $t) {
    $topicId = DB::table('ai_visibility_topics')->where('name', $t['name'])->value('id');
    if (!$topicId) {
        $topicId = DB::table('ai_visibility_topics')->insertGetId([
            'name' => $t['name'],
            'description' => $t['description'],
            'brand_aliases' => json_encode($t['aliases'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $created++;
        echo "  + 主题 #{$topicId} {$t['name']}\n";
    } else {
        echo "  = 主题 #{$topicId} {$t['name']} 已存在\n";
    }
    foreach ($t['keywords'] as $k) {
        $h = hash('sha256', $k);
        if (DB::table('ai_visibility_topic_keywords')->where('keyword_hash', $h)->exists()) {
            $dup++;
            continue;
        }
        DB::table('ai_visibility_topic_keywords')->insert([
            'ai_visibility_topic_id' => $topicId,
            'keyword' => $k,
            'keyword_hash' => $h,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kw++;
    }
}

echo "== 结果：新增主题 {$created}，新增关键词 {$kw}，跳过重复 {$dup}\n";
echo "   主题总数 " . DB::table('ai_visibility_topics')->count()
   . "，关键词总数 " . DB::table('ai_visibility_topic_keywords')->count() . "\n";
