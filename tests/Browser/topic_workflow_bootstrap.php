<?php

use App\Ai\Agents\MarkdownContentWriterAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;

/** Local acceptance environment with a dedicated disposable database and deterministic AI. */
$temporaryRoot = realpath((string) getenv('GEOFLOW_BROWSER_TEST_ROOT'));
$database = realpath((string) getenv('DB_DATABASE'));
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! $temporaryRoot || ! str_starts_with(basename($temporaryRoot), 'geoflow-workflow-browser-')
    || ! is_file($temporaryRoot.'/.browser-test-only') || $database !== $temporaryRoot.'/test.sqlite') {
    throw new RuntimeException('Topic browser verification requires its disposable testing database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($temporaryRoot)->loadEnvironmentFrom('.env.browser-test-unused');
$app->useStoragePath($temporaryRoot.'/storage');
$app->booted(function () use ($temporaryRoot): void {
    config(['queue.default' => 'sync', 'queue.connections.redis' => ['driver' => 'sync']]);
    Http::preventStrayRequests();
    Vite::useHotFile($temporaryRoot.'/unused-vite.hot');
    MarkdownContentWriterAgent::fake(function (string $prompt): string {
        $input = json_decode($prompt, true, 64, JSON_THROW_ON_ERROR);
        if (($input['phase'] ?? '') === 'verify') {
            return json_encode(['supported' => true, 'unsupported_fields' => []], JSON_THROW_ON_ERROR);
        }
        $sources = array_slice($input['source_articles'] ?? [], 0, 4);

        return json_encode([
            'intro' => '本地测试生成结果：按顺序阅读来源文章，核对专题中的结论与引用。',
            'summary' => ['one_sentence' => '先整理来源，再构建有引用的摘要。', 'facts' => [], 'scope' => '这些内容用于交互验收。', 'reading_advice' => '点击原文查看完整材料。'],
            'tags' => ['GEO', '内容整理'],
            'articles' => array_map(fn (array $source): array => ['article_id' => $source['article_id'], 'group' => '阅读材料', 'reason' => '本地验收选文'], $sources),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    })->preventStrayPrompts();
});

return $app;
