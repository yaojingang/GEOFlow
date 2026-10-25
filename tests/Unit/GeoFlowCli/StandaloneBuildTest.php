<?php

namespace Tests\Unit\GeoFlowCli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StandaloneBuildTest extends TestCase
{
    #[DataProvider('invalidBuildOptions')]
    public function test_invalid_options_stop_builds_before_dependency_installation_or_pointer_changes(array $options): void
    {
        $directory = sys_get_temp_dir().'/geoflow-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/bin', 0700);
        mkdir($directory.'/output', 0700);
        $pointer = '{"schema_version":1,"bundle":"bundles/previous"}';
        file_put_contents($directory.'/output/current.json', $pointer);
        file_put_contents($directory.'/bin/composer', "#!/bin/sh\nmkdir -p vendor\nprintf '%s\\n' '<?php' > vendor/autoload.php\n");
        chmod($directory.'/bin/composer', 0755);
        try {
            $process = new Process(array_merge([PHP_BINARY, '-d', 'phar.readonly=0', dirname(__DIR__, 3).'/scripts/build-geoflow-cli.php', '--output='.$directory.'/output'], $options), $directory, ['PATH' => $directory.'/bin'.PATH_SEPARATOR.getenv('PATH')]);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertSame($pointer, file_get_contents($directory.'/output/current.json'));
            $this->assertFileDoesNotExist($directory.'/output/.build.lock');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public static function invalidBuildOptions(): array
    {
        return [
            'unknown signing flag' => [['--signing-key-flie=typo.key']],
            'unexpected positional value' => [['ignored-build-value']],
            'missing option value' => [['--signing-key-file']],
            'duplicate value option' => [['--key-id=first', '--key-id=second']],
        ];
    }

    public function test_an_unsigned_development_build_remains_available_with_valid_options(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/bin', 0700);
        file_put_contents($directory.'/bin/composer', "#!/bin/sh\nmkdir -p vendor\nprintf '%s\\n' '<?php' > vendor/autoload.php\n");
        chmod($directory.'/bin/composer', 0755);
        try {
            $process = new Process([PHP_BINARY, '-d', 'phar.readonly=0', dirname(__DIR__, 3).'/scripts/build-geoflow-cli.php', '--output', $directory.'/output'], $directory, ['PATH' => $directory.'/bin'.PATH_SEPARATOR.getenv('PATH')]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true);
            $this->assertFalse($result['signed']);
            $this->assertFileExists($result['archive']);
            $this->assertGreaterThan(0, filesize($result['archive']));
            $this->assertFileDoesNotExist($result['bundle'].'/manifest.sig');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_build_publishes_an_immutable_signed_bundle_before_updating_its_pointer(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/bin', 0700);
        file_put_contents($directory.'/bin/composer', "#!/bin/sh\nmkdir -p vendor\nprintf '%s\\n' '<?php' > vendor/autoload.php\n");
        chmod($directory.'/bin/composer', 0755);
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        file_put_contents($directory.'/signing.key', base64_encode($secret));
        chmod($directory.'/signing.key', 0600);
        try {
            $process = new Process([PHP_BINARY, '-d', 'phar.readonly=0', dirname(__DIR__, 3).'/scripts/build-geoflow-cli.php', '--output='.$directory.'/output', '--signing-key-file='.$directory.'/signing.key', '--key-id=test-only'], $directory, ['PATH' => $directory.'/bin'.PATH_SEPARATOR.getenv('PATH')]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true);
            $this->assertTrue($result['signed']);
            $pointer = json_decode(file_get_contents($directory.'/output/current.json'), true);
            $this->assertSame(realpath($directory).'/output/'.$pointer['bundle'], $result['bundle']);
            $manifest = file_get_contents($result['bundle'].'/manifest.json');
            $signature = json_decode(file_get_contents($result['bundle'].'/manifest.sig'), true);
            $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($signature['signature'], true), $manifest, sodium_crypto_sign_publickey($pair)));
            $metadata = json_decode($manifest, true);
            $this->assertSame($metadata['sha256'], hash_file('sha256', $result['archive']));
            $phar = new \Phar($result['archive']);
            $this->assertTrue(isset($phar['src/Entrypoint.php']));
            $this->assertTrue(isset($phar['contracts/ManagementOperationRegistry.php']));
            $this->assertFalse(isset($phar['artisan']));
            $this->assertFalse(isset($phar['bootstrap/app.php']));
            unset($phar);
            $this->assertSame([], glob($directory.'/output/.build-*'));
        } finally {
            sodium_memzero($secret);
            $this->removeDirectory($directory);
        }
    }

    public function test_dependency_failure_preserves_previous_bundle_and_cleans_staging(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/bin', 0700);
        mkdir($directory.'/output', 0700);
        $pointer = '{"schema_version":1,"bundle":"bundles/previous"}';
        file_put_contents($directory.'/output/current.json', $pointer);
        file_put_contents($directory.'/bin/composer', "#!/bin/sh\nexit 42\n");
        chmod($directory.'/bin/composer', 0755);
        try {
            $process = new Process([PHP_BINARY, '-d', 'phar.readonly=0', dirname(__DIR__, 3).'/scripts/build-geoflow-cli.php', '--output='.$directory.'/output'], $directory, ['PATH' => $directory.'/bin'.PATH_SEPARATOR.getenv('PATH')]);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Standalone Composer install failed.', $process->getErrorOutput());
            $this->assertSame($pointer, file_get_contents($directory.'/output/current.json'));
            $this->assertSame([], glob($directory.'/output/.build-*'));
            $this->assertFileDoesNotExist($directory.'/output/bundles');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_build_rejects_an_invalid_signing_key_before_running_composer(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-build-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/invalid-key', 'invalid');
        try {
            $process = new Process([PHP_BINARY, '-d', 'phar.readonly=0', dirname(__DIR__, 3).'/scripts/build-geoflow-cli.php', '--output='.$directory.'/output', '--signing-key-file='.$directory.'/invalid-key', '--key-id=test-only'], $directory);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Invalid Ed25519 signing key.', $process->getErrorOutput());
            $this->assertDirectoryDoesNotExist($directory.'/output');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
