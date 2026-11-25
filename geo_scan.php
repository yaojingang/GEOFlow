<?php
// geo_scan.php — GEO 可见度诊断：主题归集、品牌提及统计、报告落盘
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "== 主题与关键词\n";
$topics = DB::table('ai_visibility_topics')->orderBy('id')->get();
foreach ($topics as $t) {
    $n = DB::table('ai_visibility_topic_keywords')->where('ai_visibility_topic_id', $t->id)->count();
    $runs = DB::table('ai_visibility_runs')->where('ai_visibility_topic_id', $t->id)->count();
    echo "  #{$t->id} {$t->name}  关键词 {$n}  采样 {$runs}\n";
}

echo "== 归集诊断\n";
$kwRows = DB::table('ai_visibility_topic_keywords')->get(['keyword', 'keyword_hash', 'ai_visibility_topic_id']);
$byHash = [];
foreach ($kwRows as $k) {
    $byHash[$k->keyword_hash] = $k->ai_visibility_topic_id;
}
$orphan = DB::table('ai_visibility_runs')->whereNull('ai_visibility_topic_id')->where('status', 'completed')->count();
echo "  未归集 completed 采样：{$orphan}\n";

// 按「关键词哈希一致」把历史采样补归集
$fixed = 0;
foreach (DB::table('ai_visibility_runs')->whereNull('ai_visibility_topic_id')->get(['id', 'keyword']) as $r) {
    $h = hash('sha256', (string) $r->keyword);
    if (isset($byHash[$h])) {
        DB::table('ai_visibility_runs')->where('id', $r->id)->update(['ai_visibility_topic_id' => $byHash[$h]]);
        $fixed++;
    }
}
echo "  按哈希补归集：{$fixed} 条\n";

echo "== 品牌提及统计（按主题）\n";
$report = ['generated_at' => date('c'), 'topics' => []];
foreach ($topics as $t) {
    $aliases = json_decode((string) $t->brand_aliases, true) ?: [];
    $runs = DB::table('ai_visibility_runs')->where('ai_visibility_topic_id', $t->id)
        ->where('status', 'completed')->get(['id', 'keyword', 'answer_text', 'provider_type']);
    $mentioned = 0;
    $details = [];
    foreach ($runs as $r) {
        $hit = null;
        foreach ($aliases as $a) {
            if ($a !== '' && mb_stripos((string) $r->answer_text, $a) !== false) {
                $hit = $a;
                break;
            }
        }
        if ($hit !== null) {
            $mentioned++;
        }
        $details[] = ['keyword' => $r->keyword, 'hit' => $hit, 'len' => mb_strlen((string) $r->answer_text)];
    }
    $rate = count($runs) > 0 ? round($mentioned / count($runs) * 100, 1) : null;
    echo "  {$t->name}: 采样 " . count($runs) . "，提及 {$mentioned}，提及率 {$rate}%\n";
    $report['topics'][] = ['name' => $t->name, 'runs' => count($runs), 'mentioned' => $mentioned,
        'rate' => $rate, 'details' => $details];
}

file_put_contents(__DIR__ . '/storage/geo-visibility-report.json',
    json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  报告：/opt/GEOFlow/storage/geo-visibility-report.json\n";
