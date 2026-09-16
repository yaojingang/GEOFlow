<?php

declare(strict_types=1);

use GeoFlow\Distribution\StandaloneArguments;
use GeoFlow\Distribution\StandaloneBundle;
use GeoFlow\Distribution\StandaloneFiles;

require_once __DIR__.'/StandaloneArguments.php';
require_once __DIR__.'/StandaloneFiles.php';
require_once __DIR__.'/StandaloneBundle.php';

$key = null;
try {
    $options = StandaloneArguments::parse(array_slice($argv, 1), ['bundle', 'trust', 'key-file', 'key-id'], ['verify']);
    foreach (['bundle', 'trust'] as $required) {
        if (! isset($options[$required])) {
            throw new RuntimeException('Required: --bundle DIR --trust FILE');
        }
    }
    $trustBytes = StandaloneFiles::read($options['trust'], 65536);
    $trust = json_decode($trustBytes, true, flags: JSON_THROW_ON_ERROR);
    $keys = StandaloneBundle::activeKeys($trust);
    $bundle = $options['bundle'];
    if (! isset($options['verify'])) {
        $key = base64_decode(trim(StandaloneFiles::read($options['key-file'] ?? '', 4096)), true);
        $keyId = $options['key-id'] ?? '';
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || ($keys[$keyId] ?? null) !== base64_encode(sodium_crypto_sign_publickey_from_secretkey($key))) {
            throw new RuntimeException('Signing key must match an active official CLI trust key.');
        }
        $manifest = StandaloneFiles::read($bundle.'/manifest.json', 65536);
        if (StandaloneBundle::manifest($manifest)['schema_version'] !== 2) {
            throw new RuntimeException('Official signing requires release manifest schema 2.');
        }
        StandaloneFiles::writeNew($bundle.'/manifest.sig', json_encode(['key_id' => $keyId, 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $key))], JSON_THROW_ON_ERROR)."\n");
        StandaloneFiles::writeNew($bundle.'/trust.json', $trustBytes);
    }
    StandaloneBundle::verify($bundle, $trust);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
} finally {
    if (is_string($key)) {
        sodium_memzero($key);
    }
}
