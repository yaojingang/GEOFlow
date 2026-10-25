<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\CommandDispatcher;
use App\Console\GeoFlowCli\ConfigurationRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class UpdaterCommandTest extends TestCase
{
    private string $root;

    private ConfigurationRepository $configuration;

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/geoflow-updater-cli-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->configuration = new ConfigurationRepository($this->root, $this->root);
        $this->configuration->save($this->configuration->profilePath('test'), ['base_url' => 'https://example.com', 'token' => 'updater-test-token', 'instance_id' => 'instance-test', 'admin_id' => '7']);
        file_put_contents($this->root.'/plan.json', json_encode(['schema_version' => 2, 'action' => 'backup', 'management_instance_id' => 'instance-test',
            'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64), 'expected_epoch' => str_repeat('a', 32), 'maintenance_required' => true, 'continuation' => 'remote']));
        file_put_contents($this->root.'/credentials.json', json_encode(['password' => 'hidden-admin-password', 'authorization_code' => '654321']));
        chmod($this->root.'/credentials.json', 0600);
        foreach (['GEOFLOW_BASE_URL', 'GEOFLOW_TOKEN', 'GEOFLOW_API_TOKEN', 'GEOFLOW_TIMEOUT', 'GEOFLOW_ALLOW_INSECURE_HTTP'] as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            $value === false ? putenv($key) : putenv($key.'='.$value);
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function runCommand(Factory $http, array $command, ?string $answers = null): array
    {
        $tokens = array_merge(['--profile', 'test'], $command);
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));
        $input->setInteractive($answers !== null);
        if ($answers !== null) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $answers);
            rewind($stream);
            $input->setStream($stream);
        }
        $output = new BufferedOutput;
        $error = new BufferedOutput;
        try {
            $code = (new CommandDispatcher($http, $this->configuration))->dispatch($tokens, $input, $output, $error);
        } finally {
            if (isset($stream)) {
                fclose($stream);
            }
        }

        return [$code, $output->fetch(), $error->fetch()];
    }

    private function mutation(): array
    {
        return ['updater', 'backup', '--plan', $this->root.'/plan.json', '--client-request-id', 'request-before-restore', '--allow-maintenance', '--credentials-file', $this->root.'/credentials.json'];
    }

    private function session(string $epoch = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        return ['success' => true, 'data' => ['instance_id' => 'instance-test', 'protocol_version' => '1.0', 'admin' => ['id' => 7], 'recovery' => ['supported' => true, 'epoch' => $epoch]]];
    }

    private function receipt(string $state = 'succeeded'): array
    {
        return ['success' => true, 'data' => ['operation_id' => '20260916T120000.000000001Z-'.str_repeat('f', 16), 'client_request_id' => 'request-before-restore', 'operation' => 'updater.backup', 'state' => $state]];
    }

    public function test_first_request_submits_once_and_secret_inputs_never_enter_the_journal_or_output(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => Factory::response($this->receipt())]);
        [$code, $out, $error] = $this->runCommand($http, $this->mutation());
        $this->assertSame(0, $code);
        $this->assertCount(2, $http->recorded());
        $request = $http->recorded()[1][0];
        $this->assertSame('654321', $request['authorization_code']);
        $this->assertSame(str_repeat('a', 32), $request['expected_epoch']);
        $this->assertTrue($request->hasHeader('X-Client-Request-Id', 'request-before-restore'));
        $journals = glob(dirname($this->configuration->defaultPath()).'/operations/*.json');
        $this->assertCount(1, $journals);
        foreach (['hidden-admin-password', '654321'] as $secret) {
            $this->assertStringNotContainsString($secret, file_get_contents($journals[0]).$out.$error);
        }
    }

    public function test_lost_response_followed_by_new_epoch_and_404_never_resubmits_or_reads_secrets_again(): void
    {
        $executions = 0;
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => function () use (&$executions) {
            $executions++;
            throw new ConnectionException('response lost');
        }]);
        try {
            $this->runCommand($http, $this->mutation());
            $this->fail('Lost response should fail.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('response lost', $exception->getMessage());
        }
        unlink($this->root.'/credentials.json');
        $retry = new Factory;
        $retry->preventStrayRequests();
        $retry->fake(['*/auth/session' => Factory::response($this->session(str_repeat('b', 32))),
            '*/management/updater/requests/*' => Factory::response(['success' => false, 'error' => ['code' => 'request_not_found', 'message' => 'absent']], 404)]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->runCommand($retry, $this->mutation());
                $this->fail('Uncertain request must not be resent.');
            } catch (CliException $exception) {
                $this->assertStringContainsString('未重发', $exception->getMessage());
            }
        }
        $this->assertSame(1, $executions);
        $retry->assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_repeat_can_return_original_receipt_without_the_credentials_file(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => Factory::response($this->receipt()), '*/management/updater/requests/*' => Factory::response($this->receipt())]);
        $this->runCommand($http, $this->mutation());
        unlink($this->root.'/credentials.json');
        [$code] = $this->runCommand($http, $this->mutation());
        $this->assertSame(0, $code);
        $this->assertCount(1, $http->recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_world_readable_credentials_are_rejected_before_any_host_mutation(): void
    {
        chmod($this->root.'/credentials.json', 0644);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*/auth/session' => Factory::response($this->session())]);
        try {
            $this->runCommand($http, $this->mutation());
            $this->fail('Unsafe credential permissions accepted.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('0600', $exception->getMessage());
        }
        $http->assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_wait_timeout_is_read_only_and_keeps_the_request_id(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/requests/*' => Factory::response($this->receipt('running'))]);
        [$code, $out] = $this->runCommand($http, ['updater', 'operation', 'wait', 'request-before-restore', '--wait-seconds', '0']);
        $this->assertSame(2, $code);
        $this->assertSame('request-before-restore', json_decode($out, true)['data']['client_request_id']);
        $http->assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_local_validation_failure_does_not_reserve_a_request_that_was_never_sent(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => Factory::response($this->receipt())]);
        chmod($this->root.'/credentials.json', 0644);
        try {
            $this->runCommand($http, $this->mutation());
            $this->fail('Unprotected credentials accepted.');
        } catch (CliException) {
            $http->assertNotSent(fn ($request) => $request->method() === 'POST');
        }
        chmod($this->root.'/credentials.json', 0600);
        clearstatcache();
        [$code] = $this->runCommand($http, $this->mutation());
        $this->assertSame(0, $code);
        $this->assertCount(1, $http->recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_invalid_hidden_input_is_rejected_before_journal_and_same_id_remains_available(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => Factory::response($this->receipt())]);
        $command = array_slice($this->mutation(), 0, -2);
        foreach (["fixture-password\n123\n", str_repeat('x', 4097)."\n654321\n"] as $answers) {
            try {
                $this->runCommand($http, $command, $answers);
                $this->fail('Invalid hidden credentials must not be sent.');
            } catch (CliException) {
                $http->assertNotSent(fn ($request) => $request->method() === 'POST');
                $this->assertSame([], glob(dirname($this->configuration->defaultPath()).'/operations/*.json'));
            }
        }
        [$code] = $this->runCommand($http, $command, "fixture-password\n654321\n");
        $this->assertSame(0, $code);
        $this->assertCount(1, $http->recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_hidden_password_preserves_leading_and_trailing_spaces(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/operations' => Factory::response($this->receipt())]);
        [$code] = $this->runCommand($http, array_slice($this->mutation(), 0, -2), " fixture-password \n654321\n");

        $this->assertSame(0, $code);
        $http->assertSent(fn ($request) => $request->method() === 'POST' && $request['password'] === ' fixture-password ');
    }

    public function test_wait_returns_manual_attention_without_polling_for_ten_minutes(): void
    {
        $http = new Factory;
        $http->fake(['*/auth/session' => Factory::response($this->session()), '*/management/updater/requests/*' => Factory::response($this->receipt('recovery_required'))]);
        [$code, $out] = $this->runCommand($http, ['updater', 'operation', 'wait', 'request-before-restore']);
        $this->assertSame(2, $code);
        $this->assertSame('recovery_required', json_decode($out, true)['data']['state']);
        $this->assertCount(2, $http->recorded());
    }

    public function test_updater_refuses_argv_password_and_generic_api_submission(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        foreach ([['updater', 'backup', '--password', 'secret'], ['api', 'updater.operations.create']] as $command) {
            try {
                $this->runCommand($http, $command);
                $this->fail('Unsafe entry point accepted.');
            } catch (CliException) {
                $this->assertTrue(true);
            }
        }
        $http->assertNothingSent();
    }
}
