<?php

declare(strict_types=1);

use GeoFlow\Distribution\StandaloneFiles;

require_once __DIR__.'/StandaloneFiles.php';

$root = dirname(__DIR__, 2);
$temporary = StandaloneFiles::directory(sys_get_temp_dir().'/geoflow-cli-smoke-'.bin2hex(random_bytes(12)));
$server = null;
$secret = null;
$status = 0;
$environment = getenv();
foreach (array_keys($environment) as $name) {
    if (str_starts_with($name, 'GEOFLOW_')) {
        unset($environment[$name]);
    }
}

/** @return array{code:int,output:string,error:string} */
$run = static function (array $arguments, string $cwd, string $input = '') use ($temporary, $environment): array {
    $id = bin2hex(random_bytes(8));
    $stdout = $temporary.'/'.$id.'.stdout';
    $stderr = $temporary.'/'.$id.'.stderr';
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, $cwd, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start smoke test process.');
    }
    if ($input !== '') {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 180;
    do {
        $state = proc_get_status($process);
        if (! $state['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Smoke test process exceeded 180 seconds.');
        }
        usleep(10000);
    } while (true);
    $closed = proc_close($process);

    return [
        'code' => $state['exitcode'] >= 0 ? $state['exitcode'] : $closed,
        'output' => StandaloneFiles::read($stdout, 1024 * 1024),
        'error' => StandaloneFiles::read($stderr, 1024 * 1024),
    ];
};
$json = static function (array $result, string $label): array {
    if ($result['code'] !== 0) {
        throw new RuntimeException($label.' failed: '.substr($result['error'], -12000));
    }

    return json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
};
$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    StandaloneFiles::writeNew($temporary.'/signing.key', base64_encode($secret));
    StandaloneFiles::writeNew($temporary.'/trust.json', json_encode(['keys' => ['smoke-test-only' => base64_encode(sodium_crypto_sign_publickey($pair))]], JSON_THROW_ON_ERROR));
    sodium_memzero($secret);
    sodium_memzero($pair);
    $started = microtime(true);
    $build = $json($run([PHP_BINARY, '-d', 'phar.readonly=0', $root.'/scripts/build-geoflow-cli.php', '--output='.$temporary.'/build', '--signing-key-file='.$temporary.'/signing.key', '--key-id=smoke-test-only'], $root), 'Standalone build');
    $buildSeconds = round(microtime(true) - $started, 3);
    $expect($build['signed'] === true, 'The smoke build must be signed with its temporary test key.');
    $manifest = json_decode(StandaloneFiles::read($build['bundle'].'/manifest.json', 65536), true, flags: JSON_THROW_ON_ERROR);
    $install = [PHP_BINARY, __DIR__.'/install.php', '--bundle='.$temporary.'/build', '--trusted-keys='.$temporary.'/trust.json', '--bin-dir='.$temporary.'/bin'];
    $installed = $json($run($install, $temporary), 'Signed installation');
    $expect($installed['signature_verified'] === true, 'Installer did not verify the release signature.');
    $empty = StandaloneFiles::directory($temporary.'/empty');
    $cli = $temporary.'/bin/geoflow';
    $version = $json($run([$cli, '--version'], $empty), 'Isolated PHAR version');
    $expect($version['version'] === $manifest['version'], 'Installed CLI version differs from its manifest.');
    $help = $run([$cli, '--help'], $empty);
    $expect($help['code'] === 0 && str_contains($help['output'], 'Usage:'), 'Installed PHAR help is unavailable.');
    $updated = $json($run([...$install, '--update'], $temporary), 'Signed update');
    $expect($updated['signature_verified'] === true && hash_file('sha256', $cli.'.previous') === $manifest['sha256'], 'Update did not preserve the previous verified executable.');

    $router = <<<'PHP'
    <?php
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $identity = ['instance_id' => '00000000-0000-4000-8000-000000000001', 'protocol_version' => '1.0', 'core_version' => '3.1.0', 'api_version' => 'v1', 'admin' => ['id' => 777, 'username' => 'smoke', 'role' => 'admin'], 'token_id' => 1, 'expires_at' => null, 'scopes' => ['sites:read']];
    header('Content-Type: application/json');
    if ($path === '/api/v1/auth/login' && ($body['password'] ?? '') === 'fixture-password') {
        $data = $identity + ['token' => 'standalone-smoke-token-test-only'];
        $data['scopes'] = $body['requested_scopes'] ?? [];
    } elseif (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer standalone-smoke-token-test-only') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => ['code' => 'unauthorized', 'message' => 'Invalid fixture token']]);
        exit;
    } elseif ($path === '/api/v1/auth/session') {
        $data = $identity;
    } elseif ($path === '/api/v1/capabilities') {
        $data = $identity + ['operations' => [['name' => 'sites.list'], ['name' => 'auth.session']], 'contract_hash' => 'fixture-contract'];
    } elseif ($path === '/api/v1/management/sites') {
        $data = ['items' => [['site_key' => 'primary', 'kind' => 'primary', 'name' => 'Standalone fixture']]];
    } elseif ($path === '/api/v1/auth/logout') {
        $data = ['revoked' => true];
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'not_found', 'message' => 'Unknown fixture path']]);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $data, 'meta' => ['request_id' => 'standalone-fixture-request']]);
    PHP;
    StandaloneFiles::writeNew($temporary.'/router.php', $router);
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Loopback networking is required for the standalone connection smoke test.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, $temporary.'/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary.'/http.log', 'a'], 2 => ['file', $temporary.'/http.log', 'a']], $serverPipes, $empty);
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start loopback API fixture.');
    }
    $deadline = microtime(true) + 5;
    do {
        $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            break;
        }
        if (microtime(true) >= $deadline || ! proc_get_status($server)['running']) {
            throw new RuntimeException('Loopback API fixture did not become ready.');
        }
        usleep(10000);
    } while (true);

    $profile = $empty.'/profile.json';
    $login = $json($run([$cli, 'login', '--base-url=http://'.$address, '--config='.$profile, '--username=smoke', '--scopes=sites:read', '--password-stdin'], $empty, "fixture-password\n"), 'Isolated login');
    $expect(($login['logged_in'] ?? false) === true && $login['scopes'] === ['sites:read'], 'Login did not grant exactly the requested scopes.');
    $tokenOnly = json_decode(StandaloneFiles::read($profile, 65536), true, flags: JSON_THROW_ON_ERROR);
    unset($tokenOnly['instance_id'], $tokenOnly['admin_id']);
    StandaloneFiles::replace($profile, json_encode($tokenOnly, JSON_THROW_ON_ERROR));
    $binding = $json($run([$cli, 'profile', 'bind', '--config='.$profile, '--instance-id=00000000-0000-4000-8000-000000000001', '--admin-id=777'], $empty), 'Explicit token profile binding');
    $expect($binding['bound'] === true && $binding['admin_id'] === '777', 'Existing token profile was not explicitly bound.');
    $identity = $json($run([$cli, 'whoami', '--config='.$profile], $empty), 'Isolated identity');
    $expect($identity['data']['admin']['id'] === 777, 'Isolated identity returned an unexpected actor.');
    $sites = $json($run([$cli, 'site', 'list', '--config='.$profile], $empty), 'Isolated site management');
    $expect($sites['data']['items'][0]['site_key'] === 'primary', 'Isolated site query did not reach the fixture.');
    $logout = $json($run([$cli, 'logout', '--config='.$profile], $empty), 'Isolated logout');
    $expect($logout['revoked'] === true && $logout['local_credentials_cleared'] === true, 'Logout did not confirm remote and local credential removal.');
    $saved = json_decode(StandaloneFiles::read($profile, 65536), true, flags: JSON_THROW_ON_ERROR);
    $expect($saved['token'] === null && ((fileperms($profile) & 0077) === 0), 'Profile credentials were retained or have unsafe permissions.');
    $report = [
        'success' => true, 'version' => $version['version'], 'php_version' => PHP_VERSION,
        'build_seconds' => $buildSeconds, 'archive_sha256' => $manifest['sha256'], 'archive_bytes' => $manifest['size'],
        'signature' => 'temporary-test-key', 'source_free_working_directory' => true, 'api_target' => 'loopback-fixture',
        'checks' => ['signed-build', 'verified-install', 'version', 'help', 'verified-update', 'login-scopes', 'profile-bind', 'whoami', 'site-list', 'logout', 'credential-cleanup'],
    ];
} catch (Throwable $exception) {
    fwrite(STDERR, 'Standalone smoke failed: '.$exception->getMessage()."\n");
    $status = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_string($secret)) {
        sodium_memzero($secret);
    }
    StandaloneFiles::removeTree($temporary);
}
if ($status === 0) {
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
exit($status);
