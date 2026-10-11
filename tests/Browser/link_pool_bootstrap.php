<?php

use App\Contracts\Outbound\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Tests\Support\FakeHostResolver;

/** This HTTP harness uses only its dedicated temporary database and storage. */
$temporaryRoot = realpath((string) getenv('GEOFLOW_BROWSER_TEST_ROOT'));
$database = realpath((string) getenv('DB_DATABASE'));
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! $temporaryRoot || ! str_starts_with(basename($temporaryRoot), 'geoflow-link-pool-browser-')
    || ! is_file($temporaryRoot.'/.browser-test-only') || $database !== $temporaryRoot.'/test.sqlite') {
    throw new RuntimeException('Link-pool browser harness requires its dedicated temporary testing database.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($temporaryRoot)->loadEnvironmentFrom('.env.browser-test-unused');
$app->useStoragePath($temporaryRoot.'/storage');
$app->booted(static function () use ($temporaryRoot): void {
    config([
        'queue.default' => 'sync',
        'queue.connections.redis' => ['driver' => 'null'],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.aichafen.com/*' => Http::response('External browser fixture', 200),
        'https://example.com/*' => Http::response('', 403),
    ]);
    app()->instance(HostResolver::class, new FakeHostResolver);
    $readback = (string) config('geoflow.link_pool_theme_readback_base_url');
    if (preg_match('/^http:\/\/127\.0\.0\.1:[1-9][0-9]*$/D', $readback)) {
        Http::allowStrayRequests([$readback.'/*']);
    }
    Vite::useHotFile($temporaryRoot.'/unused-vite.hot');
});

return $app;
