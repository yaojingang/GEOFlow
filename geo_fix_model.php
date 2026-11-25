<?php
// geo_fix_model.php — 把可见度分析模型改成 DeepSeek 直连（复用 /opt/engine/llm.env 的服务端密钥）
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Support\GeoFlow\ApiKeyCrypto;

$env = '/opt/engine/llm.env';
$key = '';
if (is_readable($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with(trim($line), 'DEEPSEEK_API_KEY=')) {
            $key = trim(explode('=', $line, 2)[1]);
            break;
        }
    }
}
if ($key === '') {
    echo "未取到 DEEPSEEK_API_KEY\n";
    exit(1);
}

$model = DB::table('ai_models')->where('id', 13)->first();
echo "改前 #13: {$model->name} [{$model->model_id}] {$model->api_url} keylen=" . strlen((string) $model->api_key) . "\n";

$enc = app(ApiKeyCrypto::class)->encrypt($key);
DB::table('ai_models')->where('id', 13)->update([
    'name' => 'DeepSeek 直连（可见度分析）',
    'model_id' => 'deepseek-chat',
    'api_url' => 'https://api.deepseek.com',
    'api_key' => $enc,
    'status' => 'active',
    'updated_at' => now(),
]);
DB::table('site_settings')->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')
    ->update(['setting_value' => '13', 'updated_at' => now()]);

echo "已改为 DeepSeek 直连 deepseek-chat，绑定回 #13\n";
