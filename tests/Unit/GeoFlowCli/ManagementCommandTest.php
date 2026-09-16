<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\ApiException;
use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\CommandDispatcher;
use App\Console\GeoFlowCli\ConfigurationRepository;
use App\Console\GeoFlowCli\OperationJournal;
use App\Support\Api\ManagementOperationRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ManagementCommandTest extends TestCase
{
    private string $root;

    private ConfigurationRepository $configuration;

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/geoflow-management-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/cwd', 0700, true);
        mkdir($this->root.'/home', 0700, true);
        $this->configuration = new ConfigurationRepository($this->root.'/cwd', $this->root.'/home');
        foreach (['GEOFLOW_BASE_URL', 'GEOFLOW_TOKEN', 'GEOFLOW_API_TOKEN', 'GEOFLOW_TIMEOUT', 'GEOFLOW_ALLOW_INSECURE_HTTP'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? putenv($name) : putenv($name.'='.$value);
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function session(): array
    {
        return ['success' => true, 'data' => ['instance_id' => 'instance-test', 'protocol_version' => '1.0', 'admin' => ['id' => 7], 'operations' => array_values(ManagementOperationRegistry::all())]];
    }

    private function profile(string $name = 'production'): string
    {
        $path = $this->configuration->profilePath($name);
        $this->configuration->save($path, ['base_url' => 'https://example.com/geoflow', 'token' => 'profile-secret', 'instance_id' => 'instance-test', 'admin_id' => '7']);

        return $path;
    }

    /** @return array{int, string, string} */
    private function runCommand(Factory $factory, array $tokens, ?string $stdin = null): array
    {
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));
        $input->setInteractive(false);
        if ($stdin !== null) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $stdin);
            rewind($stream);
            $input->setStream($stream);
        }
        $output = new BufferedOutput;
        $error = new BufferedOutput;
        $status = (new CommandDispatcher($factory, $this->configuration))->dispatch($input->getRawTokens(), $input, $output, $error);

        return [$status, $output->fetch(), $error->fetch()];
    }

    public function test_login_preserves_epoch_for_legacy_commands_and_journals_keep_original_epoch_after_relogin(): void
    {
        $factory = new Factory;
        $factory->fake(['*' => Factory::response(['success' => true, 'data' => ['token' => 'new-token', 'instance_id' => 'instance-test', 'admin' => ['id' => 7], 'recovery' => ['supported' => true, 'epoch' => str_repeat('a', 32)]]])]);
        $this->runCommand($factory, ['login', '--profile', 'production', '--base-url', 'https://example.com', '--username', 'admin', '--password-stdin'], "password\n");
        $saved = $this->configuration->load($this->configuration->profilePath('production'));
        $this->assertSame(str_repeat('a', 32), $saved['recovery_epoch']);
        $this->runCommand($factory, ['--profile', 'production', 'task', 'delete', '4', '--yes']);
        $this->assertTrue($factory->recorded()[1][0]->hasHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32)));
        $session = $this->session()['data'];
        $session['recovery'] = ['supported' => true, 'epoch' => str_repeat('a', 32)];
        $first = OperationJournal::prepare($this->configuration, $session, 'tasks.enqueue', [], 'recovery-request-1');
        $session['recovery']['epoch'] = str_repeat('b', 32);
        $second = OperationJournal::prepare($this->configuration, $session, 'tasks.enqueue', [], 'recovery-request-1');
        $this->assertFalse($first['repeated']);
        $this->assertTrue($second['repeated']);
        $this->assertSame(str_repeat('a', 32), $second['recovery_epoch']);
    }

    public function test_malformed_login_epoch_is_revoked_using_a_fresh_session_before_failing(): void
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake([
            '*/auth/login' => Factory::response(['success' => true, 'data' => ['token' => 'orphan-risk-token', 'instance_id' => 'instance-test', 'admin' => ['id' => 7], 'recovery' => ['supported' => true]]]),
            '*/auth/session' => Factory::response(['success' => true, 'data' => ['recovery' => ['supported' => true, 'epoch' => str_repeat('a', 32)]]]),
            '*/auth/logout' => Factory::response(['success' => true, 'data' => ['revoked' => true]]),
        ]);
        try {
            $this->runCommand($factory, ['login', '--profile', 'production', '--base-url', 'https://example.com', '--username', 'admin', '--password-stdin'], "password\n");
            $this->fail('Invalid identity must not be saved.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('恢复代次', $exception->getMessage());
        }
        $this->assertCount(3, $factory->recorded());
        $this->assertTrue($factory->recorded()[2][0]->hasHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32)));
        $this->assertFileDoesNotExist($this->configuration->profilePath('production'));
    }

    public function test_legacy_write_with_environment_credentials_discovers_the_current_epoch_before_sending(): void
    {
        putenv('GEOFLOW_BASE_URL=https://example.com');
        putenv('GEOFLOW_TOKEN=current-ephemeral-token');
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake([
            '*/auth/session' => Factory::response(['success' => true, 'data' => ['recovery' => ['supported' => true, 'epoch' => str_repeat('b', 32)]]]),
            '*/tasks/4' => Factory::response(['success' => true]),
        ]);
        $this->runCommand($factory, ['task', 'delete', '4', '--yes']);
        $this->assertCount(2, $factory->recorded());
        $this->assertSame('GET', $factory->recorded()[0][0]->method());
        $this->assertTrue($factory->recorded()[1][0]->hasHeader('X-GEOFlow-Recovery-Epoch', str_repeat('b', 32)));
    }

    public function test_named_profile_ignores_unknown_working_directory_configuration(): void
    {
        $this->profile();
        file_put_contents($this->root.'/cwd/.geoflow.json', '{"base_url":"https://untrusted.example","token":"untrusted"}');
        $factory = new Factory;
        $factory->fake(['*' => Factory::response($this->session())]);
        [$status, $output] = $this->runCommand($factory, ['--profile', 'production', 'whoami']);
        $this->assertSame(0, $status);
        $this->assertSame('instance-test', json_decode($output, true)['data']['instance_id']);
        $this->assertSame('https://example.com/geoflow/api/v1/auth/session', $factory->recorded()[0][0]->url());
        $this->assertStringNotContainsString('profile-secret', $output);
    }

    public function test_login_sends_selected_scopes_and_saves_instance_binding_without_printing_secrets(): void
    {
        $factory = new Factory;
        $factory->fake(['*' => Factory::response(['success' => true, 'data' => ['token' => 'new-token-secret', 'scopes' => ['sites:read'], 'instance_id' => 'instance-test', 'admin' => ['id' => 7]]])]);
        [$status, $output] = $this->runCommand($factory, ['login', '--profile', 'production', '--base-url', 'https://example.com', '--username', 'admin', '--password-stdin', '--scopes', 'sites:read'], "typed-password\n");
        $this->assertSame(0, $status);
        $this->assertSame(['sites:read'], $factory->recorded()[0][0]->data()['requested_scopes']);
        $this->assertSame('instance-test', $this->configuration->load($this->configuration->profilePath('production'))['instance_id']);
        $this->assertStringNotContainsString('new-token-secret', $output);
        $this->assertStringNotContainsString('typed-password', $output);
    }

    public function test_generic_operation_stops_on_instance_identity_change_before_the_requested_call(): void
    {
        $this->profile();
        $factory = new Factory;
        $session = $this->session();
        $session['data']['instance_id'] = 'replacement';
        $factory->fake(['*' => Factory::response($session)]);
        try {
            $this->runCommand($factory, ['--profile', 'production', 'api', 'auth.session']);
            $this->fail('A replaced instance must not continue.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('实例身份已变化', $exception->getMessage());
        }
        $this->assertCount(1, $factory->recorded());
    }

    public function test_legacy_profile_can_discover_but_cannot_send_management_writes_before_binding(): void
    {
        $path = $this->configuration->profilePath('production');
        $this->configuration->save($path, ['base_url' => 'https://example.com/geoflow', 'token' => 'legacy-secret']);
        $factory = new Factory;
        $factory->fake(['*' => Factory::response($this->session())]);
        $this->assertSame(0, $this->runCommand($factory, ['--profile', 'production', 'capabilities'])[0]);
        try {
            $this->runCommand($factory, ['--profile', 'production', 'api', 'theme-workspaces.create']);
            $this->fail('An unbound profile must not send management writes.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('身份绑定', $exception->getMessage());
        }
        foreach ($factory->recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_explicit_token_profile_binding_enables_management_writes_without_password_login(): void
    {
        $path = $this->configuration->profilePath('production');
        $this->configuration->save($path, ['base_url' => 'https://example.com/geoflow', 'token' => 'existing-token']);
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake(['*/auth/session' => Factory::response($this->session()), '*/capabilities' => Factory::response($this->session()), '*/management/theme-workspaces' => Factory::response(['success' => true, 'data' => ['id' => 'workspace-created']], 201)]);
        [$status, $output] = $this->runCommand($factory, ['--profile', 'production', 'profile', 'bind', '--instance-id', 'instance-test', '--admin-id', '7']);
        $this->assertSame(0, $status);
        $this->assertTrue(json_decode($output, true)['bound']);
        $this->assertStringNotContainsString('existing-token', $output);
        $bound = $this->configuration->load($path);
        $this->assertSame('existing-token', $bound['token']);
        $this->assertSame('instance-test', $bound['instance_id']);
        $this->assertSame('7', $bound['admin_id']);
        [$status] = $this->runCommand($factory, ['--profile', 'production', 'api', 'theme-workspaces.create', '--input', '-'], '{"body":{"site":"primary","theme":"default"}}');
        $this->assertSame(0, $status);
        $this->assertCount(3, $factory->recorded());
        $this->assertSame('POST', $factory->recorded()[2][0]->method());
        $this->assertSame('Bearer existing-token', $factory->recorded()[0][0]->header('Authorization')[0]);
    }

    public function test_profile_binding_rejects_wrong_expected_instance_or_account(): void
    {
        foreach ([['another-instance', '7'], ['instance-test', '8']] as [$instance, $admin]) {
            $path = $this->configuration->profilePath('production');
            $this->configuration->save($path, ['base_url' => 'https://example.com/geoflow', 'token' => 'existing-token']);
            $before = file_get_contents($path);
            $factory = new Factory;
            $factory->fake(['*/auth/session' => Factory::response($this->session())]);
            try {
                $this->runCommand($factory, ['--profile', 'production', 'profile', 'bind', '--instance-id', $instance, '--admin-id', $admin]);
                $this->fail('The expected remote identity must match.');
            } catch (CliException $exception) {
                $this->assertStringContainsString('预期身份', $exception->getMessage());
            }
            $this->assertSame($before, file_get_contents($path));
            $this->assertCount(1, $factory->recorded());
        }
    }

    public function test_profile_binding_rejects_token_changes_during_identity_request(): void
    {
        $path = $this->configuration->profilePath('production');
        $this->configuration->save($path, ['base_url' => 'https://example.com/geoflow', 'token' => 'existing-token']);
        $factory = new Factory;
        $factory->fake(function () use ($path) {
            file_put_contents($path, json_encode(['base_url' => 'https://example.com/geoflow', 'token' => 'new-concurrent-token']));

            return Factory::response($this->session());
        });
        try {
            $this->runCommand($factory, ['--profile', 'production', 'profile', 'bind', '--instance-id', 'instance-test', '--admin-id', '7']);
            $this->fail('A concurrent credential replacement must remain untouched.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('配置已经变化', $exception->getMessage());
        }
        $current = $this->configuration->load($path);
        $this->assertSame('new-concurrent-token', $current['token']);
        $this->assertNull($current['instance_id']);
        $this->assertCount(1, $factory->recorded());
    }

    public function test_profile_binding_rejects_environment_credentials_before_network_access(): void
    {
        $path = $this->profile();
        $before = file_get_contents($path);
        putenv('GEOFLOW_TOKEN=environment-token');
        $factory = new Factory;
        $factory->preventStrayRequests();
        try {
            $this->runCommand($factory, ['--profile', 'production', 'profile', 'bind', '--instance-id', 'instance-test', '--admin-id', '7']);
            $this->fail('Binding must use credentials from the selected profile.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('同一配置文件', $exception->getMessage());
        }
        $this->assertSame($before, file_get_contents($path));
        $this->assertCount(0, $factory->recorded());
    }

    public function test_profile_binding_requires_an_explicit_profile_selection(): void
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--profile 或 --config');
        $this->runCommand($factory, ['profile', 'bind', '--instance-id', 'instance-test', '--admin-id', '7']);
    }

    public function test_receipt_persists_request_before_send_and_records_response_without_body_or_token(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(function ($request) {
            if ($request->method() === 'GET') {
                return Factory::response($this->session());
            }
            $record = $this->journalRecord();
            $this->assertSame('enqueue-request-1', $record['client_request_id']);
            $this->assertArrayNotHasKey('idempotency_key', $record);
            $this->assertSame('enqueue-request-1', $request->header('X-Client-Request-Id')[0]);
            $this->assertSame([], $request->header('X-Idempotency-Key'));

            return Factory::response($this->receipt(), 202);
        });
        [$status] = $this->runCommand($factory, ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'], '{"path":{"task":7},"body":{"job_type":"generate_article"}}');
        $this->assertSame(0, $status);
        $record = $this->journalRecord();
        $this->assertSame('3d08d95f-1a9d-4d90-9d6a-9ec61456cccd', $record['operation_id'] ?? null);
        $this->assertSame('queued', $record['state'] ?? null);
        $this->assertArrayNotHasKey('body', $record);
        $this->assertStringNotContainsString('profile-secret', json_encode($record));
        $file = glob(dirname($this->configuration->defaultPath()).'/operations/*.json')[0];
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function test_lost_response_is_looked_up_after_relogin_before_resending_equivalent_input(): void
    {
        $path = $this->profile();
        $factory = new Factory;
        $factory->fake(function ($request) {
            if ($request->method() === 'GET') {
                return Factory::response($this->session());
            }
            throw new ConnectionException('Response lost after enqueue');
        });
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        try {
            $this->runCommand($factory, $command, '{"path":{"task":7},"body":{"job_type":"generate_article"}}');
            $this->fail('The first connection must fail.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('Response lost', $exception->getMessage());
        }
        $saved = $this->configuration->load($path);
        $saved['token'] = 'new-login-token';
        $this->configuration->save($path, $saved);
        $resumed = new Factory;
        $resumed->preventStrayRequests();
        $resumed->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/lookup?*' => Factory::response($this->receipt())]);
        [$status, $output] = $this->runCommand($resumed, $command, '{"body":{"job_type":"generate_article"},"path":{"task":7}}');
        $this->assertSame(0, $status);
        $this->assertSame('queued', json_decode($output, true)['data']['state']);
        $this->assertCount(2, $resumed->recorded());
        foreach ($resumed->recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('Bearer new-login-token', $request->header('Authorization')[0]);
        }
        $this->assertSame($this->receipt()['data']['operation_id'], $this->journalRecord()['operation_id']);
    }

    #[DataProvider('legacyKeyOnReceiptCommands')]
    public function test_management_receipts_reject_legacy_idempotency_keys_before_network_access(array $extra): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        try {
            $this->runCommand($factory, array_merge(['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--idempotency-key', 'legacy-key'], $extra), '{"path":{"task":7}}');
            $this->fail('Receipt operations must use the client request ID protocol.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--idempotency-key', $exception->getMessage());
            $this->assertStringContainsString('--client-request-id', $exception->getMessage());
        }
        $this->assertCount(0, $factory->recorded());
    }

    public static function legacyKeyOnReceiptCommands(): array
    {
        return ['legacy key only' => [[]], 'both identifiers' => [['--client-request-id', 'enqueue-request-1']]];
    }

    public function test_legacy_task_enqueue_continues_sending_only_the_idempotency_key(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake(['*/auth/session' => Factory::response(['success' => false, 'error' => ['code' => 'not_found']], 404), '*/tasks/7/enqueue' => Factory::response(['success' => true, 'data' => ['job_id' => 17]], 201)]);
        [$status] = $this->runCommand($factory, ['--profile', 'production', 'task', 'enqueue', '7', '--idempotency-key', 'legacy-key']);
        $this->assertSame(0, $status);
        $this->assertCount(2, $factory->recorded());
        $request = $factory->recorded()[1][0];
        $this->assertSame(['legacy-key'], $request->header('X-Idempotency-Key'));
        $this->assertSame([], $request->header('X-Client-Request-Id'));
    }

    public function test_older_journals_with_a_legacy_key_remain_readable_by_client_request_id(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        $this->runCommand($factory, ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'], '{"path":{"task":7}}');
        $record = $this->journalRecord();
        $record['idempotency_key'] = 'historical-unused-key';
        $path = glob(dirname($this->configuration->defaultPath()).'/operations/*.json')[0];
        file_put_contents($path, json_encode($record));
        $lookup = new Factory;
        $lookup->preventStrayRequests();
        $lookup->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/lookup?*' => Factory::response($this->receipt())]);
        [$status, $output] = $this->runCommand($lookup, ['--profile', 'production', 'operation', 'lookup', 'enqueue-request-1']);
        $this->assertSame(0, $status);
        $this->assertSame($record['operation_id'], json_decode($output, true)['data']['operation_id']);
        $this->assertSame('historical-unused-key', $this->journalRecord()['idempotency_key']);
        $this->assertCount(2, $lookup->recorded());
    }

    public function test_wait_accepts_zero_for_a_single_status_read(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/*' => Factory::response($this->receipt())]);
        [$status, $output] = $this->runCommand($factory, ['--profile', 'production', 'operation', 'wait', $this->receipt()['data']['operation_id'], '--wait-seconds', '0']);
        $this->assertSame(2, $status);
        $this->assertSame('queued', json_decode($output, true)['data']['state']);
        $this->assertCount(2, $factory->recorded());
    }

    public function test_wait_bounds_the_http_timeout_and_does_not_poll_after_its_deadline(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(function ($request, $options) {
            if (str_ends_with($request->url(), '/capabilities')) {
                return Factory::response($this->session());
            }
            $this->assertGreaterThan(0, $options['timeout']);
            $this->assertLessThanOrEqual(1, $options['timeout']);
            $this->assertLessThanOrEqual(1, $options['connect_timeout']);

            return Factory::response($this->receipt());
        });
        [$status] = $this->runCommand($factory, ['--profile', 'production', 'operation', 'wait', $this->receipt()['data']['operation_id'], '--wait-seconds', '1']);
        $this->assertSame(2, $status);
        $this->assertCount(2, $factory->recorded());
    }

    public function test_changed_retry_input_is_rejected_before_lookup_or_write(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        $this->runCommand($factory, $command, '{"path":{"task":7}}');
        try {
            $this->runCommand($factory, $command, '{"path":{"task":8}}');
            $this->fail('A client request ID cannot name a different task.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('不同输入', $exception->getMessage());
        }
        $this->assertCount(3, $factory->recorded());
        $this->assertSame($this->receipt()['data']['operation_id'], $this->journalRecord()['operation_id']);
    }

    public function test_a_missing_remote_operation_keeps_a_legacy_prepared_request_uncertain(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        $this->runCommand($factory, $command, '{"path":{"task":7}}');
        $file = glob(dirname($this->configuration->defaultPath()).'/operations/*.json')[0];
        $record = $this->journalRecord();
        $record['operation_id'] = null;
        $record['state'] = 'prepared';
        file_put_contents($file, json_encode($record));
        $resumed = new Factory;
        $resumed->preventStrayRequests();
        $resumed->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/lookup?*' => Factory::response(['success' => false, 'error' => ['code' => 'operation_not_found']], 404), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        try {
            $this->runCommand($resumed, $command, '{"path":{"task":7}}');
            $this->fail('A prepared journal does not prove that the write was never executed.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('未重新执行', $exception->getMessage());
            $this->assertStringContainsString('enqueue-request-1', $exception->getMessage());
        }
        $this->assertCount(2, $resumed->recorded());
        $this->assertSame($record, $this->journalRecord());
    }

    public function test_a_lost_response_and_missing_receipt_after_relogin_never_enqueue_again(): void
    {
        $path = $this->profile();
        $executions = 0;
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake(function ($request) use (&$executions) {
            if ($request->method() === 'GET') {
                return Factory::response($this->session());
            }
            $executions++;
            throw new ConnectionException('Response lost after enqueue');
        });
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        try {
            $this->runCommand($factory, $command, '{"path":{"task":7}}');
            $this->fail('The first response must be lost.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('Response lost', $exception->getMessage());
        }
        $record = $this->journalRecord();
        $this->assertSame('prepared', $record['state']);
        $this->configuration->save($path, array_replace($this->configuration->load($path), ['token' => 'new-login-token']));
        $resumed = new Factory;
        $resumed->preventStrayRequests();
        $resumed->fake(function ($request) use (&$executions) {
            if (str_ends_with($request->url(), '/capabilities')) {
                return Factory::response($this->session());
            }
            if ($request->method() === 'GET') {
                return Factory::response(['success' => false, 'error' => ['code' => 'operation_not_found']], 404);
            }
            $executions++;

            return Factory::response($this->receipt(), 202);
        });

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->runCommand($resumed, $command, '{"path":{"task":7}}');
                $this->fail('A restored server losing its receipt must not replay the write.');
            } catch (CliException $exception) {
                $this->assertStringContainsString('未重新执行', $exception->getMessage());
                $this->assertStringContainsString('enqueue-request-1', $exception->getMessage());
            }
        }
        $this->assertSame(1, $executions);
        $this->assertSame($record, $this->journalRecord());
        $this->assertCount(4, $resumed->recorded());
    }

    public function test_lookup_failure_does_not_trigger_another_write(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        $this->runCommand($factory, $command, '{"path":{"task":7}}');
        $resumed = new Factory;
        $resumed->preventStrayRequests();
        $resumed->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/lookup?*' => Factory::response(['success' => false, 'error' => ['code' => 'unavailable']], 503)]);
        try {
            $this->runCommand($resumed, $command, '{"path":{"task":7}}');
            $this->fail('A failed lookup must stop recovery.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->httpStatus);
        }
        $this->assertCount(2, $resumed->recorded());
    }

    public function test_a_receipt_already_recorded_locally_is_not_reexecuted_after_remote_receipt_loss(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        $command = ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'];
        $this->runCommand($factory, $command, '{"path":{"task":7}}');
        $resumed = new Factory;
        $resumed->fake(['*/capabilities' => Factory::response($this->session()), '*/operations/lookup?*' => Factory::response(['success' => false, 'error' => ['code' => 'operation_not_found']], 404), '*/tasks/7/enqueue' => Factory::response($this->receipt(), 202)]);
        try {
            $this->runCommand($resumed, $command, '{"path":{"task":7}}');
            $this->fail('An acknowledged operation must not run again after remote receipt loss.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('本地已有', $exception->getMessage());
        }
        $this->assertCount(2, $resumed->recorded());
    }

    public function test_scope_mismatch_does_not_overwrite_existing_login_and_requires_positive_revocation_confirmation(): void
    {
        $path = $this->profile();
        $factory = new Factory;
        $factory->fake(['*/auth/login' => Factory::response(['success' => true, 'data' => ['token' => 'new-token-secret', 'scopes' => ['sites:read', 'themes:code']]]), '*/auth/logout' => Factory::response(['success' => true, 'data' => ['revoked' => false]])]);
        $tokens = ['login', '--profile', 'production', '--username', 'admin', '--password-stdin', '--scopes', 'sites:read', '--force'];
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "typed-password\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $errors = new BufferedOutput;
        try {
            (new CommandDispatcher($factory, $this->configuration))->dispatch($tokens, $input, $output, $errors);
            $this->fail('Unexpected permissions must reject the new token.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('未按请求授予权限', $exception->getMessage());
        } finally {
            fclose($stream);
        }
        $this->assertSame('profile-secret', $this->configuration->load($path)['token']);
        $this->assertSame('', $output->fetch());
        $error = $errors->fetch();
        $this->assertStringContainsString('撤销未确认', $error);
        $this->assertStringNotContainsString('typed-password', $error);
        $this->assertStringNotContainsString('new-token-secret', $error);
        $this->assertCount(2, $factory->recorded());
    }

    public function test_mismatched_receipt_does_not_replace_the_local_operation_identity(): void
    {
        $this->profile();
        $receipt = $this->receipt();
        $receipt['data']['client_request_id'] = 'different-request-id';
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response($this->session()), '*/tasks/7/enqueue' => Factory::response($receipt, 202)]);
        try {
            $this->runCommand($factory, ['--profile', 'production', 'api', 'tasks.enqueue', '--input', '-', '--client-request-id', 'enqueue-request-1'], '{"path":{"task":7}}');
            $this->fail('The returned receipt must match the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('收据无效', $exception->getMessage());
        }
        $this->assertNull($this->journalRecord()['operation_id']);
    }

    public function test_login_rejects_invalid_instance_binding_and_revokes_only_new_token(): void
    {
        $path = $this->profile();
        $factory = new Factory;
        $factory->fake(['*/auth/login' => Factory::response(['success' => true, 'data' => ['token' => 'new-token-secret', 'scopes' => ['sites:read'], 'instance_id' => ['invalid'], 'admin' => ['id' => 7]]]), '*/auth/logout' => Factory::response(['success' => true, 'data' => ['revoked' => true]])]);
        try {
            $this->runCommand($factory, ['login', '--profile', 'production', '--username', 'admin', '--password-stdin', '--scopes', 'sites:read', '--force'], "typed-password\n");
            $this->fail('Invalid identity must not overwrite the old profile.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('身份', $exception->getMessage());
        }
        $this->assertSame('profile-secret', $this->configuration->load($path)['token']);
        $this->assertCount(2, $factory->recorded());
        $this->assertSame('Bearer new-token-secret', $factory->recorded()[1][0]->header('Authorization')[0]);
    }

    private function receipt(): array
    {
        return ['success' => true, 'data' => ['operation_id' => '3d08d95f-1a9d-4d90-9d6a-9ec61456cccd', 'client_request_id' => 'enqueue-request-1', 'operation' => 'tasks.enqueue', 'state' => 'queued']];
    }

    private function journalRecord(): array
    {
        $files = glob(dirname($this->configuration->defaultPath()).'/operations/*.json');
        $this->assertCount(1, $files);

        return json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_logout_revokes_and_clears_only_the_selected_profile(): void
    {
        $path = $this->profile();
        $other = $this->profile('staging');
        $factory = new Factory;
        $factory->fake(['*/auth/session' => Factory::response($this->session()), '*/auth/logout' => Factory::response(['success' => true, 'data' => ['revoked' => true]])]);
        [$status, $output] = $this->runCommand($factory, ['--profile', 'production', 'logout']);
        $this->assertSame(0, $status);
        $this->assertTrue(json_decode($output, true)['local_credentials_cleared']);
        $this->assertNull($this->configuration->load($path)['token']);
        $this->assertSame('profile-secret', $this->configuration->load($other)['token']);
        $this->assertCount(2, $factory->recorded());
    }

    public function test_generic_operation_rejects_an_arbitrary_url_without_network_access(): void
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('未知管理 operation');
        $this->runCommand($factory, ['api', 'https://untrusted.example/run']);
    }

    #[DataProvider('unsupportedRecoveryOptions')]
    public function test_generic_operations_reject_unsupported_recovery_options_before_network_access(string $operation, string $option): void
    {
        $path = $this->profile();
        $before = file_get_contents($path);
        $factory = new Factory;
        $factory->fake(['*/auth/logout' => Factory::response(['success' => true, 'data' => ['revoked' => true]]), '*' => Factory::response($this->session())]);
        try {
            $this->runCommand($factory, ['--profile', 'production', 'api', $operation, $option, 'same-request-id']);
            $this->fail('An unsupported recovery option must not be silently ignored.');
        } catch (CliException $exception) {
            $this->assertStringContainsString($option, $exception->getMessage());
            $this->assertStringContainsString('不支持', $exception->getMessage());
        }
        $this->assertCount(0, $factory->recorded());
        $this->assertSame($before, file_get_contents($path));
    }

    public static function unsupportedRecoveryOptions(): array
    {
        $cases = [];
        foreach (['theme-workspaces.create', 'theme-workspaces.change', 'auth.logout', 'sites.list'] as $operation) {
            foreach (['--client-request-id', '--idempotency-key'] as $option) {
                $cases[$operation.' '.$option] = [$operation, $option];
            }
        }

        return $cases;
    }

    public function test_profile_names_cannot_escape_the_profile_directory(): void
    {
        $this->expectException(CliException::class);
        $this->configuration->profilePath('../../outside');
    }

    public function test_explicit_profile_and_config_cannot_be_combined(): void
    {
        $this->expectException(CliException::class);
        $this->configuration->resolve(['profile' => 'production', 'config' => '/tmp/another-config'], false);
    }

    public function test_doctor_requires_a_real_legacy_response_before_reporting_legacy_support(): void
    {
        $this->profile();
        $factory = new Factory;
        $factory->fake(['*/capabilities' => Factory::response(['success' => false], 404), '*/catalog' => Factory::response(['success' => true, 'data' => ['models' => [], 'categories' => []]])]);
        [$status, $output] = $this->runCommand($factory, ['--profile', 'production', 'doctor']);
        $this->assertSame(0, $status);
        $this->assertFalse(json_decode($output, true)['data']['management_supported']);
        $this->assertCount(2, $factory->recorded());
    }
}
