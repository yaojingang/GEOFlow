<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\ConfigurationRepository;
use App\Console\GeoFlowCli\Entrypoint;
use App\Console\GeoFlowCli\GeoFlowApplication;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class EntrypointTest extends TestCase
{
    #[Test]
    public function rate_limit_errors_are_redacted_and_include_retry_guidance(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => false,
            'error' => [
                'code' => 'too_many_requests',
                'details' => ['retry_after' => 23, 'api_key' => 'must-not-appear'],
            ],
        ], 429));
        $application = new GeoFlowApplication($factory);
        $application->setAutoExit(false);
        $input = new ArgvInput([
            'geoflow', 'catalog', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $output = new ConsoleOutput(decorated: false);
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $status = Entrypoint::run($application, $input, $output);

        $diagnostic = $errorOutput->fetch();
        $payload = json_decode($diagnostic, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $status);
        $this->assertSame('too_many_requests', $payload['error']['code']);
        $this->assertSame(23, $payload['retry_after']);
        $this->assertStringContainsString('23', $payload['cli_hint']);
        $this->assertStringNotContainsString('must-not-appear', $diagnostic);
        $this->assertStringNotContainsString('secret-token', $diagnostic);
        $this->assertCount(1, $payload['cli_warnings']);
        $this->assertStringContainsString('下一主版本', $payload['cli_warnings'][0]);
        $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
    }

    #[Test]
    public function malformed_retry_after_data_keeps_api_errors_as_one_json_document(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => false,
            'error' => [
                'code' => 'too_many_requests',
                'details' => ['retry_after' => ['unexpected' => 'shape']],
            ],
        ], 429));
        $application = new GeoFlowApplication($factory);
        $application->setAutoExit(false);
        $input = new ArgvInput([
            'geoflow', 'catalog', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $output = new ConsoleOutput(decorated: false);
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $status = Entrypoint::run($application, $input, $output);

        $payload = json_decode($errorOutput->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $status);
        $this->assertSame('too_many_requests', $payload['error']['code']);
        $this->assertArrayNotHasKey('retry_after', $payload);
        $this->assertSame('请求过于频繁，请稍后重试。', $payload['cli_hint']);
    }

    #[Test]
    public function argv_credential_deprecation_warning_preserves_success_json(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response(['success' => true, 'data' => ['id' => 1]], 200));
        $application = new GeoFlowApplication($factory);
        $application->setAutoExit(false);
        $input = new ArgvInput([
            'geoflow', 'catalog', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $output = new CapturingConsoleOutput;
        $errorOutput = $output->getErrorOutput();

        $this->assertSame(0, Entrypoint::run($application, $input, $output));

        $this->assertSame(
            ['success' => true, 'data' => ['id' => 1]],
            json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR),
        );
        $diagnostic = $errorOutput->fetch();
        $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
        $this->assertStringNotContainsString('secret-token', $diagnostic);
    }

    #[Test]
    public function argv_password_deprecation_warning_preserves_login_success_json(): void
    {
        $root = sys_get_temp_dir().'/geoflow-login-warning-'.bin2hex(random_bytes(6));
        mkdir($root.'/home', 0700, true);
        mkdir($root.'/cwd', 0700, true);
        $password = 'argv-password-secret';

        try {
            $factory = new HttpFactory;
            $factory->fake(fn () => $factory->response([
                'success' => true,
                'data' => ['token' => 'new-token'],
            ], 200));
            $application = new GeoFlowApplication(
                $factory,
                new ConfigurationRepository($root.'/cwd', $root.'/home'),
            );
            $application->setAutoExit(false);
            $input = new ArgvInput([
                'geoflow', 'login', '--base-url', 'https://api.example.com',
                '--username', 'admin', '--password', $password,
            ]);
            $output = new CapturingConsoleOutput;

            $this->assertSame(0, Entrypoint::run($application, $input, $output));

            $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
            $diagnostic = $output->getErrorOutput()->fetch();
            $this->assertTrue($payload['logged_in']);
            $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
            $this->assertStringNotContainsString($password, $diagnostic);
        } finally {
            $this->deleteTree($root);
        }
    }

    #[Test]
    public function invalid_remote_http_is_a_local_error_and_never_sends_a_request(): void
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $application = new GeoFlowApplication(
            $factory,
            new ConfigurationRepository(sys_get_temp_dir(), sys_get_temp_dir()),
        );
        $application->setAutoExit(false);
        $input = new ArgvInput([
            'geoflow', 'catalog', '--base-url', 'http://api.example.com', '--token', 'secret-token',
        ]);
        $output = new ConsoleOutput(decorated: false);
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $status = Entrypoint::run($application, $input, $output);

        $this->assertSame(1, $status);
        $diagnostic = $errorOutput->fetch();
        $this->assertStringContainsString('--allow-insecure-http', $diagnostic);
        $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
        $this->assertStringNotContainsString('secret-token', $diagnostic);
        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function missing_token_guidance_only_names_safe_credential_sources(): void
    {
        $root = sys_get_temp_dir().'/geoflow-missing-token-'.bin2hex(random_bytes(6));
        mkdir($root.'/home', 0700, true);
        mkdir($root.'/cwd', 0700, true);

        try {
            $factory = new HttpFactory;
            $factory->preventStrayRequests();
            $application = new GeoFlowApplication(
                $factory,
                new ConfigurationRepository($root.'/cwd', $root.'/home'),
            );
            $application->setAutoExit(false);
            $input = new ArgvInput(['geoflow', 'catalog', '--base-url', 'https://api.example.com']);
            $input->setInteractive(false);
            $output = new ConsoleOutput(decorated: false);
            $errorOutput = new BufferedOutput;
            $output->setErrorOutput($errorOutput);

            $this->assertSame(1, Entrypoint::run($application, $input, $output));

            $diagnostic = $errorOutput->fetch();
            $this->assertStringContainsString('GEOFLOW_TOKEN', $diagnostic);
            $this->assertStringContainsString('GEOFLOW_API_TOKEN', $diagnostic);
            $this->assertStringContainsString('--token-stdin', $diagnostic);
            $this->assertStringNotContainsString('传入 --token 或', $diagnostic);
            $this->assertCount(0, $factory->recorded());
        } finally {
            $this->deleteTree($root);
        }
    }

    #[Test]
    #[DataProvider('hintedStatuses')]
    public function authorization_and_lock_errors_remain_one_json_document(int $status): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => false,
            'error' => ['code' => 'api_error'],
        ], $status));
        $application = new GeoFlowApplication($factory);
        $application->setAutoExit(false);
        $input = new ArgvInput([
            'geoflow', 'catalog', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $output = new ConsoleOutput(decorated: false);
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $this->assertSame(1, Entrypoint::run($application, $input, $output));

        $payload = json_decode($errorOutput->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('api_error', $payload['error']['code']);
        $this->assertIsString($payload['cli_hint']);
    }

    /** @return iterable<string,array{int}> */
    public static function hintedStatuses(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
        yield 'locked' => [423];
    }

    #[Test]
    public function repaired_config_warning_is_rendered_before_a_local_validation_error(): void
    {
        $root = sys_get_temp_dir().'/geoflow-entrypoint-warning-'.bin2hex(random_bytes(6));
        $directory = $root.'/home/.config/geoflow';
        mkdir($directory, 0700, true);
        mkdir($root.'/cwd', 0700, true);
        $configPath = $directory.'/config.json';
        file_put_contents($configPath, '{"base_url":"https://api.example.com","token":"secret-token"}');
        chmod($configPath, 0644);

        try {
            $factory = new HttpFactory;
            $factory->preventStrayRequests();
            $application = new GeoFlowApplication(
                $factory,
                new ConfigurationRepository($root.'/cwd', $root.'/home'),
            );
            $application->setAutoExit(false);
            $input = new ArgvInput(['geoflow', 'task', 'get', '0']);
            $output = new ConsoleOutput(decorated: false);
            $errorOutput = new BufferedOutput;
            $output->setErrorOutput($errorOutput);

            $status = Entrypoint::run($application, $input, $output);

            $diagnostic = $errorOutput->fetch();
            $this->assertSame(1, $status);
            $this->assertSame(0600, fileperms($configPath) & 0777);
            $this->assertStringContainsString('0600', $diagnostic);
            $this->assertStringContainsString('任务 ID', $diagnostic);
            $this->assertLessThan(strpos($diagnostic, '任务 ID'), strpos($diagnostic, '0600'));
            $this->assertCount(0, $factory->recorded());
        } finally {
            $this->deleteTree($root);
        }
    }

    #[Test]
    public function login_api_error_cannot_echo_the_submitted_password(): void
    {
        $root = sys_get_temp_dir().'/geoflow-login-redaction-'.bin2hex(random_bytes(6));
        mkdir($root.'/home', 0700, true);
        mkdir($root.'/cwd', 0700, true);
        $password = 'submitted-password-secret';

        try {
            $factory = new HttpFactory;
            $factory->fake(fn () => $factory->response([
                'success' => false,
                'error' => [
                    'message' => "Invalid password {$password}",
                    'details' => ['echo' => $password],
                ],
            ], 401));
            $application = new GeoFlowApplication(
                $factory,
                new ConfigurationRepository($root.'/cwd', $root.'/home'),
            );
            $application->setAutoExit(false);
            $input = new ArgvInput([
                'geoflow', 'login', '--base-url', 'https://api.example.com',
                '--username', 'admin', '--password', $password,
            ]);
            $output = new ConsoleOutput(decorated: false);
            $errorOutput = new BufferedOutput;
            $output->setErrorOutput($errorOutput);

            $this->assertSame(1, Entrypoint::run($application, $input, $output));

            $diagnostic = $errorOutput->fetch();
            $payload = json_decode($diagnostic, true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($password, $diagnostic);
            $this->assertCount(1, $payload['cli_warnings']);
            $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
        } finally {
            $this->deleteTree($root);
        }
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

final class CapturingConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    public function __construct()
    {
        parent::__construct();
        $this->errorOutput = new BufferedOutput;
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('Sections are not used by this test output.');
    }
}
