<?php

declare(strict_types=1);

use GeoFlow\Distribution\StandaloneArguments;
use GeoFlow\Distribution\StandaloneFiles;
use GeoFlow\Distribution\StandaloneInstaller;

require_once __DIR__.'/StandaloneArguments.php';
require_once __DIR__.'/StandaloneFiles.php';
require_once __DIR__.'/StandaloneBundle.php';
require_once __DIR__.'/StandaloneInstaller.php';

try {
    if (PHP_VERSION_ID < 80300) {
        throw new RuntimeException('PHP 8.3 or newer is required.');
    }
    foreach (['curl', 'fileinfo', 'mbstring', 'openssl', 'Phar', 'sodium'] as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException('Missing PHP extension: '.$extension);
        }
    }
    $options = StandaloneArguments::parse(array_slice($argv, 1), ['bundle', 'trusted-keys', 'bin-dir'], ['update', 'recover', 'rollback']);
    if (count(array_intersect(['recover', 'update', 'rollback'], array_keys($options))) > 1) {
        throw new RuntimeException('--recover, --update and --rollback are mutually exclusive.');
    }
    foreach (['trusted-keys', 'bin-dir'] as $required) {
        if (! isset($options[$required]) || ! is_string($options[$required]) || $options[$required] === '') {
            throw new RuntimeException('Required: --bundle DIR --trusted-keys FILE --bin-dir DIR [--update], or --recover --trusted-keys FILE --bin-dir DIR.');
        }
    }
    if (isset($options['recover']) === isset($options['bundle']) || (isset($options['bundle']) && ! is_string($options['bundle']))) {
        throw new RuntimeException('Choose either --bundle DIR or --recover.');
    }
    $trusted = json_decode(StandaloneFiles::read($options['trusted-keys'], 65536), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($trusted['keys'] ?? null)) {
        throw new RuntimeException('Invalid trusted key file.');
    }
    $result = (new StandaloneInstaller($options['bin-dir'], $trusted['schema_version'] ?? null ? $trusted : $trusted['keys']))->run($options['bundle'] ?? null, isset($options['update']), rollback: isset($options['rollback']));
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\nIf activation was interrupted, use --recover with the same trusted keys and bin directory.\n");
    exit(1);
}
