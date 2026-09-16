<?php

namespace Tests\StandaloneRelease;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ReleaseInstallerTest extends TestCase
{
    private string $directory;

    private string $secret;

    private array $trust;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/geoflow-release-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/bundle', 0700);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->trust = ['schema_version' => 1, 'version' => 1, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400), 'keys' => ['test' => ['public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'status' => 'active']]];
        $this->saveTrust();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        sodium_memzero($this->secret);
    }

    private function saveTrust(): void
    {
        file_put_contents($this->directory.'/trust.json', json_encode($this->trust));
    }

    private function bundle(string $version, int $sequence, string $content = 'verified'): string
    {
        $bytes = '<?php echo '.var_export($content, true).';';
        $manifest = json_encode(['schema_version' => 2, 'protocol_version' => '1.0', 'version' => $version, 'release_sequence' => $sequence, 'source_commit' => str_repeat('a', 40), 'file' => 'geoflow.phar', 'php' => '^8.3', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        file_put_contents($this->directory.'/bundle/geoflow.phar', $bytes);
        file_put_contents($this->directory.'/bundle/manifest.json', $manifest);
        file_put_contents($this->directory.'/bundle/manifest.sig', json_encode(['key_id' => 'test', 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secret))]));

        return $bytes;
    }

    private function install(string ...$flags): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/install.php', '--bundle='.$this->directory.'/bundle', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin', ...$flags], $this->directory);
        $process->run();

        return $process;
    }

    public function test_release_identity_is_immutable_and_repeated_installation_has_no_side_effects(): void
    {
        $bytes = $this->bundle('0.4.0', 1);
        $first = $this->install();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $state = file_get_contents($this->directory.'/bin/.geoflow-install-state.json');
        $repeat = $this->install('--update');
        $this->assertSame(0, $repeat->getExitCode(), $repeat->getErrorOutput());
        $this->assertSame($state, file_get_contents($this->directory.'/bin/.geoflow-install-state.json'));
        $this->assertFileDoesNotExist($this->directory.'/bin/geoflow.previous');
        $this->bundle('0.4.0', 1, 'changed');
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->assertSame($bytes, file_get_contents($this->directory.'/bin/geoflow'));
    }

    public function test_historical_rollback_preserves_high_water_and_rejects_unknown_or_reused_releases(): void
    {
        $old = $this->bundle('0.4.0', 1);
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.5.0', 2, 'new');
        $this->assertSame(0, $this->install('--update')->getExitCode());
        $state = file_get_contents($this->directory.'/bin/.geoflow-install-state.json');
        $this->bundle('0.4.0', 1);
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $rollback = $this->install('--rollback');
        $this->assertSame(0, $rollback->getExitCode(), $rollback->getErrorOutput());
        $this->assertSame($old, file_get_contents($this->directory.'/bin/geoflow'));
        $this->assertSame($state, file_get_contents($this->directory.'/bin/.geoflow-install-state.json'));
        $this->bundle('0.3.0', 1);
        $this->assertNotSame(0, $this->install('--rollback')->getExitCode());
        $this->bundle('0.6.0', 2);
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->bundle('0.6.0', 1);
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->bundle('0.6.0', 3);
        $this->assertSame(0, $this->install('--update')->getExitCode());
    }

    public function test_trust_revocation_expiry_and_rollback_stop_before_activation(): void
    {
        $old = $this->bundle('0.4.0', 1);
        $this->trust['version'] = 2;
        $this->saveTrust();
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.5.0', 2);
        $this->trust['version'] = 1;
        $this->saveTrust();
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->trust['version'] = 3;
        $this->trust['keys']['test']['status'] = 'revoked';
        $this->saveTrust();
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->trust['keys']['test']['status'] = 'active';
        $this->trust['expires_at'] = '2020-01-01T00:00:00Z';
        $this->saveTrust();
        $this->assertNotSame(0, $this->install('--update')->getExitCode());
        $this->assertSame($old, file_get_contents($this->directory.'/bin/geoflow'));
    }

    public function test_new_release_cannot_use_unversioned_preview_trust(): void
    {
        $this->bundle('0.4.0', 1);
        file_put_contents($this->directory.'/trust.json', json_encode(['keys' => ['test' => $this->trust['keys']['test']['public_key']]]));
        $this->assertNotSame(0, $this->install()->getExitCode());
        $this->assertFileDoesNotExist($this->directory.'/bin/geoflow');
    }

    public function test_semantic_prerelease_order_is_consistent_with_publication(): void
    {
        $this->bundle('0.4.0-alpha.1', 1);
        $this->assertSame(0, $this->install()->getExitCode());
        $this->bundle('0.4.0-preview.2', 2);
        $update = $this->install('--update');
        $this->assertSame(0, $update->getExitCode(), $update->getErrorOutput());
        $this->bundle('0.4.0', 3);
        $stable = $this->install('--update');
        $this->assertSame(0, $stable->getExitCode(), $stable->getErrorOutput());
    }

    public function test_missing_official_high_water_is_not_silently_recreated(): void
    {
        $old = $this->bundle('0.4.0', 1);
        $this->assertSame(0, $this->install()->getExitCode());
        unlink($this->directory.'/bin/.geoflow-install-state.json');
        $this->bundle('0.5.0', 2);
        $update = $this->install('--update');
        $this->assertNotSame(0, $update->getExitCode());
        $this->assertStringContainsString('high-water state is missing', $update->getErrorOutput());
        $this->assertSame($old, file_get_contents($this->directory.'/bin/geoflow'));
    }

    public function test_schema_two_activation_recovers_its_high_water_after_each_new_boundary(): void
    {
        foreach (['signature_activated', 'state_activated'] as $phase) {
            $this->bundle('0.4.0', 1);
            $this->assertSame(0, $this->install()->getExitCode());
            $this->bundle('0.5.0', 2);
            $code = <<<'SCRIPT'
            foreach (['StandaloneFiles', 'StandaloneBundle', 'StandaloneInstaller'] as $class) { require $argv[1].'/'.$class.'.php'; }
            $trust = json_decode(file_get_contents($argv[2].'/trust.json'), true);
            (new GeoFlow\Distribution\StandaloneInstaller($argv[2].'/bin', $trust))->run($argv[2].'/bundle', true, static function ($phase) use ($argv) { if ($phase === $argv[3]) { exit(87); } });
            SCRIPT;
            $interrupted = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__), $this->directory, $phase]);
            $interrupted->run();
            $this->assertSame(87, $interrupted->getExitCode(), $interrupted->getErrorOutput());
            $recovery = new Process([PHP_BINARY, dirname(__DIR__).'/install.php', '--recover', '--trusted-keys='.$this->directory.'/trust.json', '--bin-dir='.$this->directory.'/bin']);
            $recovery->run();
            $this->assertSame(0, $recovery->getExitCode(), $recovery->getErrorOutput());
            $this->assertSame(2, json_decode(file_get_contents($this->directory.'/bin/.geoflow-install-state.json'), true)['highest_sequence']);
            $this->bundle('0.4.0', 1);
            $this->assertNotSame(0, $this->install('--update')->getExitCode());
            // Each phase owns a separate installation while retaining the test key and bundle.
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory.'/bin', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory.'/bin');
        }
    }
}
