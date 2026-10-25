<?php

declare(strict_types=1);

use App\Console\GeoFlowCli\CliVersion;
use GeoFlow\Distribution\StandaloneArguments;
use GeoFlow\Distribution\StandaloneFiles;

require_once dirname(__DIR__).'/packages/geoflow-cli/StandaloneArguments.php';
require_once dirname(__DIR__).'/packages/geoflow-cli/StandaloneFiles.php';

$options = StandaloneArguments::parse(array_slice($argv, 1), ['output', 'signing-key-file', 'key-id']);
$root = dirname(__DIR__);
$output = $options['output'] ?? $root.'/storage/app/private/cli-build';
if (! is_string($output)) {
    throw new RuntimeException('Use an absolute output directory.');
}
if ((bool) ini_get('phar.readonly')) {
    throw new RuntimeException('Build with php -d phar.readonly=0 scripts/build-geoflow-cli.php');
}
$key = null;
if (isset($options['signing-key-file'])) {
    if (! is_string($options['signing-key-file']) || ! is_string($options['key-id'] ?? null)
        || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $options['key-id']) !== 1) {
        throw new RuntimeException('A regular signing key file and key ID are required.');
    }
    $key = base64_decode(trim(StandaloneFiles::read($options['signing-key-file'], 4096)), true);
    if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Invalid Ed25519 signing key.');
    }
} elseif (isset($options['key-id'])) {
    throw new RuntimeException('--key-id requires --signing-key-file.');
}
$output = StandaloneFiles::directory($output);
$lock = StandaloneFiles::lock($output.'/.build.lock');
$stage = $output.'/.build-'.bin2hex(random_bytes(12));
try {
    foreach (new FilesystemIterator($output) as $entry) {
        if (preg_match('/^\.build-[a-f0-9]{24}$/D', $entry->getFilename()) === 1) {
            StandaloneFiles::removeTree($entry->getPathname());
        }
    }
    StandaloneFiles::directory($stage);
    foreach (['payload/src', 'payload/contracts', 'payload/bin', 'bundle'] as $directory) {
        StandaloneFiles::directory($stage.'/'.$directory);
    }
    $copy = static function (string $source, string $destination): void {
        StandaloneFiles::writeNew($destination, StandaloneFiles::read($source, 32 * 1024 * 1024));
    };
    foreach (glob($root.'/app/Console/GeoFlowCli/*.php') ?: [] as $file) {
        $copy($file, $stage.'/payload/src/'.basename($file));
    }
    foreach ([
        'app/Support/Api/ManagementOperationRegistry.php' => 'contracts/ManagementOperationRegistry.php',
        'bin/geoflow' => 'bin/geoflow', 'LICENSE' => 'LICENSE',
        'packages/geoflow-cli/composer.json' => 'composer.json',
        'packages/geoflow-cli/composer.lock' => 'composer.lock',
    ] as $source => $destination) {
        $copy($root.'/'.$source, $stage.'/payload/'.$destination);
    }
    $process = proc_open(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress', '--no-plugins', '--no-scripts', '--optimize-autoloader'], [0 => STDIN, 1 => STDERR, 2 => STDERR], $pipes, $stage.'/payload');
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Standalone Composer install failed.');
    }
    $archivePath = $stage.'/bundle/geoflow.phar';
    $archive = new Phar($archivePath);
    $archive->startBuffering();
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage.'/payload', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || ! $file->isFile()) {
            throw new RuntimeException('Build input must contain only regular files.');
        }
        $files[substr($file->getPathname(), strlen($stage.'/payload') + 1)] = $file->getPathname();
    }
    ksort($files);
    $archive->buildFromIterator(new ArrayIterator($files));
    $archive->setSignatureAlgorithm(Phar::SHA256);
    $archive->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('geoflow.phar'); require 'phar://geoflow.phar/bin/geoflow'; __HALT_COMPILER();");
    $archive->stopBuffering();
    unset($archive);
    // Persist the archive as well as metadata before making the bundle discoverable.
    $archiveBytes = StandaloneFiles::read($archivePath, 100 * 1024 * 1024);
    StandaloneFiles::replace($archivePath, $archiveBytes, 0755);
    require_once $root.'/app/Console/GeoFlowCli/CliVersion.php';
    $manifest = json_encode([
        'schema_version' => 1, 'version' => CliVersion::VALUE,
        'protocol_version' => '1.0', 'php' => '^8.3', 'file' => 'geoflow.phar',
        'sha256' => hash('sha256', $archiveBytes), 'size' => strlen($archiveBytes),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $signature = $key === null ? null : json_encode([
        'key_id' => $options['key-id'], 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $key)),
    ], JSON_THROW_ON_ERROR)."\n";
    StandaloneFiles::writeNew($stage.'/bundle/manifest.json', $manifest);
    if ($signature !== null) {
        StandaloneFiles::writeNew($stage.'/bundle/manifest.sig', $signature);
    }
    StandaloneFiles::syncDirectory($stage.'/bundle');
    StandaloneFiles::directory($output.'/bundles');
    $relative = 'bundles/'.hash('sha256', $manifest.($signature ?? ''));
    $bundle = $output.'/'.$relative;
    if (file_exists($bundle) || is_link($bundle)) {
        if (realpath($bundle) !== $bundle || StandaloneFiles::read($bundle.'/manifest.json', 65536) !== $manifest
            || StandaloneFiles::read($bundle.'/geoflow.phar', 100 * 1024 * 1024) !== $archiveBytes
            || ($signature !== null && StandaloneFiles::read($bundle.'/manifest.sig', 4096) !== $signature)) {
            throw new RuntimeException('Existing immutable build bundle differs from this candidate.');
        }
    } elseif (! rename($stage.'/bundle', $bundle)) {
        throw new RuntimeException('Cannot finalize immutable build bundle.');
    }
    StandaloneFiles::syncDirectory($output.'/bundles');
    StandaloneFiles::replace($output.'/current.json', json_encode(['schema_version' => 1, 'bundle' => $relative], JSON_THROW_ON_ERROR)."\n");
    fwrite(STDOUT, json_encode(['bundle' => $bundle, 'archive' => $bundle.'/geoflow.phar', 'signed' => $signature !== null], JSON_THROW_ON_ERROR)."\n");
} finally {
    if (is_string($key)) {
        sodium_memzero($key);
    }
    if (is_dir($stage)) {
        StandaloneFiles::removeTree($stage);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
