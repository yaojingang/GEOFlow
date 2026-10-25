<?php

namespace Tests\Unit\GeoFlowCli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StandaloneInstallerTest extends TestCase
{
    private string $directory;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-installer-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/bundle', 0700);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->directory.'/trust.json', json_encode(['keys' => ['test-key' => base64_encode(sodium_crypto_sign_publickey($pair))]], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        sodium_memzero($this->secret);
        parent::tearDown();
    }

    private function bundle(string $version = '0.3.0'): string
    {
        $bytes = "#!/usr/bin/env php\n<?php echo 'verified fixture {$version}';\n";
        file_put_contents($this->directory.'/bundle/geoflow.phar', $bytes);
        $manifest = json_encode(['schema_version' => 1, 'protocol_version' => '1.0', 'version' => $version, 'file' => 'geoflow.phar', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR);
        file_put_contents($this->directory.'/bundle/manifest.json', $manifest);
        file_put_contents($this->directory.'/bundle/manifest.sig', json_encode(['key_id' => 'test-key', 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secret))], JSON_THROW_ON_ERROR));

        return $bytes;
    }

    private function install(bool $update = false, bool $recover = false): Process
    {
        $arguments = [PHP_BINARY, dirname(__DIR__, 3).'/packages/geoflow-cli/install.php', $recover ? '--recover' : '--bundle='.$this->directory.'/bundle', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin'];
        if ($update) {
            $arguments[] = '--update';
        }
        $process = new Process($arguments, $this->directory);
        $process->run();

        return $process;
    }

    public function test_signed_install_executes_in_a_directory_without_core_source(): void
    {
        $bytes = $this->bundle();
        $process = $this->install();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($bytes, file_get_contents($this->directory.'/bin/geoflow'));
        $run = new Process([PHP_BINARY, $this->directory.'/bin/geoflow'], $this->directory);
        $run->run();
        $this->assertSame('verified fixture 0.3.0', $run->getOutput());
    }

    public function test_tampered_executable_is_rejected_before_install(): void
    {
        $this->bundle();
        file_put_contents($this->directory.'/bundle/geoflow.phar', 'tampered');
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertFileDoesNotExist($this->directory.'/bin/geoflow');
    }

    public function test_tampered_manifest_and_unknown_key_are_rejected(): void
    {
        $this->bundle();
        file_put_contents($this->directory.'/bundle/manifest.json', '{}');
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->bundle();
        file_put_contents($this->directory.'/trust.json', '{"keys":{}}');
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertFileDoesNotExist($this->directory.'/bin/geoflow');
    }

    public function test_update_preserves_previous_pair_and_rejects_downgrade_or_mismatch(): void
    {
        $this->bundle('0.3.0');
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame(0, $this->install(true)->getExitCode());
        $this->assertFileExists($this->directory.'/bin/geoflow.previous');
        $this->assertSame('0.3.0', json_decode(file_get_contents($this->directory.'/bin/geoflow.manifest.json.previous'), true)['version']);
        $this->bundle('0.3.0');
        $this->assertNotSame(0, $this->install(true)->getExitCode());
        file_put_contents($this->directory.'/bin/geoflow', 'unexpected executable');
        $this->bundle('0.5.0');
        $this->assertNotSame(0, $this->install(true)->getExitCode());
    }

    public function test_install_does_not_follow_a_target_symlink(): void
    {
        $this->bundle();
        mkdir($this->directory.'/bin', 0700);
        file_put_contents($this->directory.'/untouched', 'keep');
        symlink($this->directory.'/untouched', $this->directory.'/bin/geoflow');
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertSame('keep', file_get_contents($this->directory.'/untouched'));
    }

    #[DataProvider('invalidInstallOptions')]
    public function test_invalid_options_cannot_authorize_replacement_of_an_existing_installation(array $options): void
    {
        $previous = $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        $process = new Process(array_merge([PHP_BINARY, dirname(__DIR__, 3).'/packages/geoflow-cli/install.php', '--bundle='.$this->directory.'/bundle', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin'], $options), $this->directory);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertFileDoesNotExist($this->directory.'/bin/.geoflow-install.json');
    }

    public static function invalidInstallOptions(): array
    {
        return [
            'false flag' => [['--update=false']],
            'empty flag' => [['--update=']],
            'duplicate flag' => [['--update', '--update']],
            'unknown option' => [['--update', '--unknown-install-option']],
            'unexpected positional value' => [['--update', 'ignored-value']],
            'missing option value' => [['--update', '--bin-dir']],
        ];
    }

    #[DataProvider('invalidRecoveryOptions')]
    public function test_invalid_recovery_options_leave_pending_installations_untouched(array $options): void
    {
        $previous = $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        $this->assertSame(87, $this->interrupt('prepared')->getExitCode());
        $before = file_get_contents($this->directory.'/bin/.geoflow-install.json');
        $process = new Process(array_merge([PHP_BINARY, dirname(__DIR__, 3).'/packages/geoflow-cli/install.php', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin'], $options), $this->directory);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertSame($before, file_get_contents($this->directory.'/bin/.geoflow-install.json'));
    }

    public static function invalidRecoveryOptions(): array
    {
        return [
            'false recovery' => [['--recover=false']],
            'duplicate recovery' => [['--recover', '--recover']],
            'conflicting recovery mode' => [['--recover', '--update']],
            'unknown recovery option' => [['--recover', '--unknown-recovery-option']],
        ];
    }

    #[DataProvider('interruptionPhases')]
    public function test_interrupted_update_recovers_a_complete_verified_pair(string $phase): void
    {
        $previous = $this->bundle('0.3.0');
        $this->assertSame(0, $this->install()->getExitCode());
        $candidate = $this->bundle('0.4.0');
        $this->assertSame(87, $this->interrupt($phase)->getExitCode());
        $this->assertFileExists($this->directory.'/bin/.geoflow-install.json');
        $recovery = $this->install(recover: true);
        $this->assertSame(0, $recovery->getExitCode(), $recovery->getErrorOutput());
        $this->assertSame($candidate, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertSame('0.4.0', json_decode(file_get_contents($this->directory.'/bin/geoflow.manifest.json'), true)['version']);
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow.previous'));
        $this->assertSame('0.3.0', json_decode(file_get_contents($this->directory.'/bin/geoflow.manifest.json.previous'), true)['version']);
        $this->assertFileDoesNotExist($this->directory.'/bin/.geoflow-install.json');
        $this->assertSame([], glob($this->directory.'/bin/.geoflow-transaction-*'));
        $this->assertSame(0, $this->install(recover: true)->getExitCode());
    }

    public static function interruptionPhases(): array
    {
        return array_map(static fn (string $phase): array => [$phase], ['prepared', 'previous_preserved', 'executable_activated', 'receipt_activated', 'signature_activated']);
    }

    public function test_first_install_resumes_after_executable_activation_without_a_receipt(): void
    {
        $candidate = $this->bundle();
        $this->assertSame(87, $this->interrupt('executable_activated')->getExitCode());
        $this->assertFileDoesNotExist($this->directory.'/bin/geoflow.manifest.json');
        $retry = $this->install();
        $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
        $this->assertTrue(json_decode($retry->getOutput(), true)['recovered']);
        $this->assertSame($candidate, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertSame('0.3.0', json_decode(file_get_contents($this->directory.'/bin/geoflow.manifest.json'), true)['version']);
    }

    public function test_recovery_reverifies_signature_and_rejects_a_tampered_candidate(): void
    {
        $previous = $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        $this->assertSame(87, $this->interrupt('prepared')->getExitCode());
        $journal = json_decode(file_get_contents($this->directory.'/bin/.geoflow-install.json'), true);
        file_put_contents($this->directory.'/bin/'.$journal['transaction'].'/candidate/geoflow.phar', 'tampered');
        $this->assertNotSame(0, $this->install(recover: true)->getExitCode());
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertFileExists($this->directory.'/bin/.geoflow-install.json');
    }

    public function test_recovery_honors_revoked_trust_and_preserves_external_changes(): void
    {
        $previous = $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        $this->assertSame(87, $this->interrupt('prepared')->getExitCode());
        $trust = file_get_contents($this->directory.'/trust.json');
        file_put_contents($this->directory.'/trust.json', '{"keys":{}}');
        $this->assertNotSame(0, $this->install(recover: true)->getExitCode());
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow'));
        file_put_contents($this->directory.'/trust.json', $trust);
        file_put_contents($this->directory.'/bin/geoflow', 'external change');
        $this->assertNotSame(0, $this->install(recover: true)->getExitCode());
        $this->assertSame('external change', file_get_contents($this->directory.'/bin/geoflow'));
    }

    public function test_special_lock_and_backup_targets_are_rejected(): void
    {
        $previous = $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0');
        file_put_contents($this->directory.'/untouched', 'keep');
        symlink($this->directory.'/untouched', $this->directory.'/bin/geoflow.previous');
        $this->assertNotSame(0, $this->install(true)->getExitCode());
        $this->assertSame('keep', file_get_contents($this->directory.'/untouched'));
        $this->assertSame($previous, file_get_contents($this->directory.'/bin/geoflow'));
        unlink($this->directory.'/bin/geoflow.previous');
        unlink($this->directory.'/bin/.geoflow-install.lock');
        link($this->directory.'/untouched', $this->directory.'/bin/.geoflow-install.lock');
        $this->assertNotSame(0, $this->install(true)->getExitCode());
        $this->assertSame('keep', file_get_contents($this->directory.'/untouched'));
    }

    public function test_concurrent_updates_are_serialized_and_keep_the_immediate_previous_version(): void
    {
        $this->bundle();
        $this->assertSame(0, $this->install()->getExitCode());
        $middle = $this->bundle('0.4.0');
        $first = $this->interrupt('hold', start: true);
        $deadline = microtime(true) + 5;
        while (! is_file($this->directory.'/ready') && microtime(true) < $deadline && $first->isRunning()) {
            usleep(10000);
        }
        $this->assertFileExists($this->directory.'/ready', $first->getErrorOutput());
        $latest = $this->bundle('0.5.0');
        $second = new Process([PHP_BINARY, dirname(__DIR__, 3).'/packages/geoflow-cli/install.php', '--bundle='.$this->directory.'/bundle', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin', '--update'], $this->directory);
        $second->start();
        try {
            usleep(50000);
            $this->assertTrue($second->isRunning());
        } finally {
            file_put_contents($this->directory.'/release', 'continue');
        }
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame($latest, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertSame($middle, file_get_contents($this->directory.'/bin/geoflow.previous'));
    }

    private function interrupt(string $phase, bool $start = false): Process
    {
        $code = <<<'PHP'
        foreach (['StandaloneFiles', 'StandaloneBundle', 'StandaloneInstaller'] as $class) {
            require $argv[1].'/'.$class.'.php';
        }
        $directory = $argv[2];
        $keys = json_decode(file_get_contents($directory.'/trust.json'), true)['keys'];
        (new GeoFlow\Distribution\StandaloneInstaller($directory.'/bin', $keys))->run($directory.'/bundle', true, static function (string $phase) use ($argv, $directory): void {
            if ($argv[3] === 'hold' && $phase === 'prepared') {
                file_put_contents($directory.'/ready', 'ready');
                $deadline = microtime(true) + 5;
                while (! is_file($directory.'/release') && microtime(true) < $deadline) {
                    usleep(10000);
                }
            } elseif ($phase === $argv[3]) {
                exit(87);
            }
        });
        PHP;
        $process = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 3).'/packages/geoflow-cli', $this->directory, $phase], $this->directory);
        $start ? $process->start() : $process->run();

        return $process;
    }
}
