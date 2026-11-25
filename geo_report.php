<?php
// geo_report.php — 汇总 3 主题 30 关键词的 AI 可见度基线报告
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$since = 3861;
$report = ['generated_at' => date('c'), 'topics' => [], 'competitors' => []];

foreach (DB::table('ai_visibility_topics')->orderBy('id')->get() as $t) {
    $aliases = json_decode((string) $t->brand_aliases, true) ?: [];
    $kws = DB::table('ai_visibility_topic_keywords')->where('ai_visibility_topic_id', $t->id)
        ->orderBy('id')->get(['keyword']);
    $rows = [];
    $hit = 0;
    foreach ($kws as $k) {
        $h = hash('sha256', $k->keyword);
        $search = DB::table('ai_visibility_runs')
            ->where('keyword', $k->keyword)->where('provider_type', 'doubao_search_custom')
            ->where('status', 'completed')->where('id', '>', $since)->orderByDesc('id')->first();
        $analysis = DB::table('ai_visibility_runs')->where('keyword', $k->keyword)
            ->where('provider_type', 'deepseek_analysis')->where('id', '>', $since)
            ->orderByDesc('id')->first();
        $answer = (string) ($search->answer_text ?? '');
        $m = null;
        foreach ($aliases as $a) {
            if ($a !== '' && mb_stripos($answer, $a) !== false) { $m = $a; break; }
        }
        if ($m !== null) { $hit++; }
        $summary = '';
        if ($analysis && $analysis->answer_text) {
            $summary = mb_substr(preg_replace('/\s+/u', ' ', (string) $analysis->answer_text), 0, 160);
        }
        $rows[] = [
            'keyword' => $k->keyword,
            'search' => $search->status ?? 'missing',
            'analysis' => $analysis->status ?? 'missing',
            'brand' => $m,
            'len' => mb_strlen($answer),
            'ai_summary' => $summary,
            'answer_head' => mb_substr(preg_replace('/\s+/u', ' ', $answer), 0, 220),
        ];
    }
    $cnt = count($rows);
    $report['topics'][] = [
        'id' => $t->id, 'name' => $t->name, 'keywords' => $cnt,
        'mentioned' => $hit, 'rate' => $cnt > 0 ? round($hit / $cnt * 100, 1) : null,
        'rows' => $rows,
    ];
}

$comps = DB::table('ai_visibility_competitors')->where('created_at', '>=', date('Y-m-d 00:00:00'))
    ->orderByDesc('id')->limit(60)->get(['name', 'aliases', 'source']);
foreach ($comps as $c) {
    $report['competitors'][] = $c->name;
}

file_put_contents(__DIR__ . '/storage/geo-visibility-report2.json',
    json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Markdown
$md = "# GEO AI 可见度基线报告\n\n生成时间：" . date('Y-m-d H:i') . "\n\n";
foreach ($report['topics'] as $tt) {
    $md .= "## {$tt['name']}\n\n关键词 {$tt['keywords']} 个，品牌提及 {$tt['mentioned']} 个，提及率 {$tt['rate']}%\n\n";
    $md .= "| 关键词 | 搜索 | 分析 | 品牌提及 | AI 回答首段 |\n|---|---|---|---|---|\n";
    foreach ($tt['rows'] as $r) {
        $b = $r['brand'] ?? '未提及';
        $md .= "| {$r['keyword']} | {$r['search']} | {$r['analysis']} | {$b} | " . str_replace('|', ' ', $r['answer_head']) . " |\n";
    }
    $md .= "\n";
}
if ($report['competitors']) {
    $md .= "## 竞品识别（今日新增）\n\n" . implode('、', $report['competitors']) . "\n";
}
file_put_contents(__DIR__ . '/storage/geo-visibility-report2.md', $md);
echo "报告已生成：storage/geo-visibility-report2.md\n";
foreach ($report['topics'] as $tt) {
    echo "  {$tt['name']}: 关键词 {$tt['keywords']}，提及 {$tt['mentioned']}，{$tt['rate']}%\n";
}
echo "竞品新增：" . count($report['competitors']) . " 个\n";
