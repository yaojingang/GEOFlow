<?php
// geo_dump.php — 抽查一条搜索运行的字段结构，确认 AI 答案落在哪
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

foreach (Schema::getColumnListing('ai_visibility_runs') as $c) {
    echo $c . ' ';
}
echo "\n";

foreach (DB::table('ai_visibility_runs')->where('provider_type', 'doubao_search_custom')
    ->where('id', '>', 3861)->orderByDesc('id')->limit(1)->get() as $r) {
    foreach (['id', 'keyword', 'status'] as $f) { echo "{$f}=" . $r->$f . "\n"; }
    echo 'answer_text len=' . mb_strlen((string) $r->answer_text) . "\n";
    echo 'analysis_json len=' . strlen((string) $r->analysis_json) . "\n";
    echo 'raw_response_json len=' . strlen((string) $r->raw_response_json) . "\n";
    echo "answer_text head: " . mb_substr((string) $r->answer_text, 0, 200) . "\n";
    echo "analysis_json head: " . mb_substr((string) $r->analysis_json, 0, 400) . "\n";
    echo "raw head: " . mb_substr((string) $r->raw_response_json, 0, 400) . "\n";
}
