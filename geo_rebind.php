<?php
// geo_rebind.php — 切换 AI 可见度的分析模型绑定（带备份读数）
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$key = 'ai_visibility_deepseek_analysis_model_id';
$cur = DB::table('site_settings')->where('setting_key', $key)->value('setting_value');
echo "当前绑定：{$cur}\n";

$target = $argv[1] ?? null;
if ($target === null) {
    foreach (DB::table('ai_models')->where('status', 'active')->orderBy('failover_priority')->limit(8)->get(['id', 'name', 'model_id', 'api_url']) as $m) {
        echo "  候选 #{$m->id} {$m->name} [{$m->model_id}] {$m->api_url}\n";
    }
    exit(0);
}

$model = DB::table('ai_models')->where('id', (int) $target)->first();
if (!$model) {
    echo "目标模型不存在\n";
    exit(1);
}
DB::table('site_settings')->where('setting_key', $key)->update([
    'setting_value' => (string) $model->id,
    'updated_at' => now(),
]);
echo "已切换 {$cur} -> {$model->id}（{$model->name} [{$model->model_id}] {$model->api_url}）\n";
