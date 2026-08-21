<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\ConfigurationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigurationRepositoryTest extends TestCase
{
    private string $root;

    /** @var array<string,string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/geoflow-cli-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/home/.config/geoflow', 0700, true);
        mkdir($this->root.'/cwd', 0700, true);

        foreach (['GEOFLOW_BASE_URL', 'GEOFLOW_TOKEN', 'GEOFLOW_API_TOKEN', 'GEOFLOW_TIMEOUT', 'GEOFLOW_ALLOW_INSECURE_HTTP'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv($name.'='.$value);
        }

        $this->deleteTree($this->root);
        parent::tearDown();
    }

    #[Test]
    public function values_follow_cli_env_local_and_home_precedence(): void
    {
        $this->writeJson($this->root.'/home/.config/geoflow/config.json', [
            'base_url' => 'https://home.example.com',
            'token' => 'home-token',
            'timeout' => 10,
        ]);
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'base_url' => 'https://local.example.com',
            'token' => 'local-token',
            'timeout' => 20,
        ]);
        putenv('GEOFLOW_BASE_URL=https://env.example.com');
        putenv('GEOFLOW_TOKEN=env-token');
        putenv('GEOFLOW_TIMEOUT=30');

        $config = $this->repository()->resolve([
            'base-url' => 'https://cli.example.com',
            'token' => 'cli-token',
            'timeout' => '40',
        ], true);

        $this->assertSame('https://cli.example.com', $config['base_url']);
        $this->assertSame('cli-token', $config['token']);
        $this->assertSame(40, $config['timeout']);
        $this->assertSame('cli:argv', $config['token_source']);
        $this->assertSame('argv', $config['endpoint_source_type']);
        $this->assertSame('argv', $config['credential_source_type']);
        $this->assertSame('explicit_cli_pair', $config['credential_binding']);
        $this->assertTrue($config['credential_binding_valid']);
    }

    #[Test]
    public function legacy_api_token_is_only_used_when_primary_token_is_absent(): void
    {
        $this->writeJson($this->root.'/home/.config/geoflow/config.json', [
            'base_url' => 'https://api.example.com',
        ]);
        putenv('GEOFLOW_API_TOKEN=legacy-token');
        $legacy = $this->repository()->resolve([], true);
        $this->assertSame('legacy-token', $legacy['token']);
        $this->assertSame('env:GEOFLOW_API_TOKEN', $legacy['token_source']);
        $this->assertSame('trusted_home_profile', $legacy['credential_binding']);

        putenv('GEOFLOW_TOKEN=primary-token');
        $primary = $this->repository()->resolve([], true);
        $this->assertSame('primary-token', $primary['token']);
        $this->assertSame('env:GEOFLOW_TOKEN', $primary['token_source']);
    }

    #[Test]
    public function local_profile_never_inherits_a_token_from_the_home_profile(): void
    {
        $this->writeJson($this->root.'/home/.config/geoflow/config.json', [
            'base_url' => 'https://trusted.example.com',
            'token' => 'home-secret-token',
        ]);
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'base_url' => 'https://attacker.example.com',
        ]);

        try {
            $this->repository()->resolve([], true);
            $this->fail('Expected the local profile to remain tokenless.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('token', $exception->getMessage());
        }

        $config = $this->repository()->resolve([], false);
        $this->assertSame('https://attacker.example.com', $config['base_url']);
        $this->assertNull($config['token']);
        $this->assertSame([$this->root.'/cwd/.geoflow.json'], $config['config_files']);
        $this->assertSame('local', $config['profile_source']);
    }

    #[Test]
    public function an_explicit_missing_profile_never_falls_back_to_local_or_home(): void
    {
        $this->writeJson($this->root.'/home/.config/geoflow/config.json', [
            'base_url' => 'https://trusted.example.com',
            'token' => 'home-secret-token',
        ]);
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'base_url' => 'https://local.example.com',
            'token' => 'local-secret-token',
        ]);
        $missing = $this->root.'/missing.json';

        $config = $this->repository()->resolve(['config' => $missing], false);

        $this->assertNull($config['base_url']);
        $this->assertNull($config['token']);
        $this->assertSame('explicit', $config['profile_source']);
        $this->assertSame($missing, $config['profile_path']);
        $this->assertSame([], $config['config_files']);
    }

    #[Test]
    public function an_explicit_profile_accepts_an_environment_token_override(): void
    {
        $path = $this->root.'/cwd/trusted.json';
        $this->writeJson($path, [
            'base_url' => 'https://trusted.example.com',
            'token' => 'file-token',
        ]);
        putenv('GEOFLOW_TOKEN=environment-token');

        $config = $this->repository()->resolve(['config' => $path], true);

        $this->assertSame('https://trusted.example.com', $config['base_url']);
        $this->assertSame('environment-token', $config['token']);
        $this->assertSame('env:GEOFLOW_TOKEN', $config['token_source']);
        $this->assertSame('trusted_explicit_profile', $config['credential_binding']);
        $this->assertTrue($config['credential_binding_valid']);
    }

    #[Test]
    public function a_cli_remote_http_endpoint_cannot_inherit_a_profile_exception(): void
    {
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'allow_insecure_http' => true,
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--allow-insecure-http');

        $this->repository()->resolve([
            'base-url' => 'http://remote.example.com',
            'token' => 'cli-token',
        ], true);
    }

    #[Test]
    public function an_environment_remote_http_endpoint_cannot_inherit_a_profile_exception(): void
    {
        $this->writeJson($this->root.'/home/.config/geoflow/config.json', [
            'allow_insecure_http' => true,
        ]);
        putenv('GEOFLOW_BASE_URL=http://remote.example.com');
        putenv('GEOFLOW_TOKEN=environment-token');

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('GEOFLOW_ALLOW_INSECURE_HTTP');

        $this->repository()->resolve([], true);
    }

    #[Test]
    public function remote_http_keeps_same_profile_and_explicit_environment_exceptions(): void
    {
        $profile = $this->root.'/cwd/trusted-http.json';
        $this->writeJson($profile, [
            'base_url' => 'http://profile.example.com',
            'token' => 'profile-token',
            'allow_insecure_http' => true,
        ]);

        $sameProfile = $this->repository()->resolve(['config' => $profile], true);
        $this->assertSame('http://profile.example.com', $sameProfile['base_url']);

        putenv('GEOFLOW_ALLOW_INSECURE_HTTP=true');
        $explicitEnvironment = $this->repository()->resolve([
            'base-url' => 'http://cli.example.com',
            'token' => 'cli-token',
        ], true);
        $this->assertSame('http://cli.example.com', $explicitEnvironment['base_url']);
    }

    #[Test]
    public function config_file_url_whitespace_is_not_trimmed_away_before_policy_validation(): void
    {
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'base_url' => ' https://api.example.com',
            'token' => 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('空白');

        $this->repository()->resolve([], true);
    }

    #[Test]
    public function config_file_must_be_a_json_object(): void
    {
        file_put_contents($this->root.'/cwd/.geoflow.json', '[]');

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('JSON 对象');

        $this->repository()->resolve([], false);
    }

    #[Test]
    public function config_string_fields_reject_structured_values_without_php_warnings(): void
    {
        $this->writeJson($this->root.'/cwd/.geoflow.json', [
            'base_url' => ['https://api.example.com'],
            'token' => ['secret-token'],
        ]);

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->repository()->resolve([], false);
            $this->fail('Expected structured config values to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('base_url', $exception->getMessage());
            $this->assertStringContainsString('字符串', $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function structured_timeout_and_http_policy_values_are_rejected_cleanly(): void
    {
        foreach (['timeout', 'allow_insecure_http'] as $field) {
            $this->writeJson($this->root.'/cwd/.geoflow.json', [
                'base_url' => 'https://api.example.com',
                'token' => 'secret-token',
                $field => ['unexpected'],
            ]);

            set_error_handler(static function (int $severity, string $message): never {
                throw new \ErrorException($message, 0, $severity);
            });
            try {
                $this->repository()->resolve([], false);
                $this->fail("Expected {$field} to reject a structured value.");
            } catch (CliException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            } finally {
                restore_error_handler();
            }
        }
    }

    #[Test]
    public function token_file_permissions_are_repaired_and_reported(): void
    {
        $path = $this->root.'/cwd/.geoflow.json';
        $this->writeJson($path, [
            'base_url' => 'https://api.example.com',
            'token' => 'secret-token',
        ]);
        chmod($path, 0644);

        $config = $this->repository()->resolve([], true);

        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertCount(1, $config['warnings']);
        $this->assertStringContainsString('0600', $config['warnings'][0]);
    }

    #[Test]
    public function existing_default_config_directory_permissions_are_repaired_and_reported(): void
    {
        $directory = $this->root.'/home/.config/geoflow';
        $path = $directory.'/config.json';
        $this->writeJson($path, [
            'base_url' => 'https://api.example.com',
            'token' => 'secret-token',
        ]);
        chmod($path, 0600);
        chmod($directory, 0755);

        $config = $this->repository()->resolve([], true);

        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertCount(1, $config['warnings']);
        $this->assertStringContainsString('0700', $config['warnings'][0]);
    }

    #[Test]
    public function save_is_private_and_creates_private_directories(): void
    {
        $path = $this->root.'/new/config/config.json';

        $this->repository()->save($path, [
            'base_url' => 'https://api.example.com',
            'token' => 'secret-token',
            'timeout' => 30,
            'allow_insecure_http' => false,
        ]);

        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->assertSame('secret-token', json_decode((string) file_get_contents($path), true)['token']);
    }

    #[Test]
    public function sidecar_lock_covers_the_locked_save_callback(): void
    {
        $path = $this->root.'/locked/config.json';
        $repository = $this->repository();

        $observedLock = $repository->withLock($path, function (string $lockedPath) use ($repository): bool {
            $lockPath = $lockedPath.'.lock';
            $this->assertFileExists($lockPath);
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($lockPath) & 0777);
            }

            $repository->saveLocked($lockedPath, [
                'base_url' => 'https://api.example.com',
                'token' => 'secret-token',
            ]);

            return is_file($lockedPath);
        });

        $this->assertTrue($observedLock);
        $this->assertSame('secret-token', json_decode((string) file_get_contents($path), true)['token']);
    }

    #[Test]
    public function a_sidecar_lock_symlink_is_rejected_without_changing_its_target(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Native Windows symlink behavior is outside the supported CLI surface.');
        }

        $path = $this->root.'/cwd/config.json';
        $target = $this->root.'/lock-target';
        file_put_contents($target, 'do not touch');
        chmod($target, 0644);
        symlink($target, $path.'.lock');

        try {
            $this->repository()->save($path, ['token' => 'secret-token']);
            $this->fail('Expected the lock symlink to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('符号链接', $exception->getMessage());
        }

        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertSame('do not touch', file_get_contents($target));
    }

    private function repository(): ConfigurationRepository
    {
        return new ConfigurationRepository($this->root.'/cwd', $this->root.'/home');
    }

    /** @param array<string,mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->deleteTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
