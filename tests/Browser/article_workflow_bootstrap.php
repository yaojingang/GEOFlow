<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;

/** Isolated browser harness; never loads a project environment or existing database. */
$temporaryRoot = realpath((string) getenv('GEOFLOW_BROWSER_TEST_ROOT'));
$database = realpath((string) getenv('DB_DATABASE'));
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! $temporaryRoot || ! str_starts_with(basename($temporaryRoot), 'geoflow-workflow-browser-')
    || ! is_file($temporaryRoot.'/.browser-test-only') || $database !== $temporaryRoot.'/test.sqlite') {
    throw new RuntimeException('Browser harness requires its dedicated temporary testing database.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($temporaryRoot)->loadEnvironmentFrom('.env.browser-test-unused');
$app->useStoragePath($temporaryRoot.'/storage');
$app->booted(function () use ($temporaryRoot): void {
    config([
        'queue.default' => 'null',
        'queue.connections.null' => ['driver' => 'null'],
        // Some workflow jobs explicitly select Redis; discard those jobs in this HTTP-only test too.
        'queue.connections.redis' => ['driver' => 'null'],
    ]);
    Http::preventStrayRequests();
    Vite::useHotFile($temporaryRoot.'/unused-vite.hot');
});

return $app;
