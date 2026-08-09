<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\CommandDispatcher;
use App\Console\GeoFlowCli\ConfigurationRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandDispatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/geoflow-dispatch-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/home', 0700, true);
        mkdir($this->root.'/cwd', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    #[Test]
    public function catalog_accepts_global_options_on_both_sides_and_emits_api_json(): void
    {
        $factory = $this->successfulFactory('{"success":true,"data":{"catalog":1}}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            '--base-url', 'https://api.example.com',
            'catalog',
            '--token', 'secret-token',
        ]);

        $status = $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $this->assertSame(0, $status);
        $this->assertSame("{\"success\":true,\"data\":{\"catalog\":1}}\n", $stdout->fetch());
        $diagnostic = $stderr->fetch();
        $this->assertSame(1, substr_count($diagnostic, '下一主版本'));
        $this->assertStringNotContainsString('secret-token', $diagnostic);
        $this->assertSame('https://api.example.com/api/v1/catalog', $factory->recorded()[0][0]->url());
    }

    #[Test]
    public function article_review_sends_the_risk_override_reason(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'article', 'review', '42', '--status', 'approved', '--note', 'checked',
            '--risk-override-reason', 'legal approval',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $request = $factory->recorded()[0][0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('legal approval', $request->data()['risk_override_reason']);
    }

    #[Test]
    #[DataProvider('articleUpdateWorkflowOptions')]
    public function article_update_rejects_workflow_only_direct_options(array $options): void
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness(
            $factory,
            array_merge(['article', 'update', '42'], $options),
        );

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected the unsupported article update option to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('不支持选项', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    /** @return iterable<string,array{list<string>}> */
    public static function articleUpdateWorkflowOptions(): iterable
    {
        yield 'status' => [['--status', 'published']];
        yield 'review status' => [['--review-status', 'approved']];
        yield 'AI generated' => [['--ai-generated']];
    }

    #[Test]
    public function non_interactive_delete_requires_yes_before_any_request(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'task', 'delete', '7', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $input->setInteractive(false);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected confirmation failure.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--yes', $exception->getMessage());
        }
        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function confirmed_item_delete_uses_json_body_without_idempotency_header(): void
    {
        $jsonPath = $this->root.'/ids.json';
        file_put_contents($jsonPath, '{"ids":[3,4]}');
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-delete', 'keywords', '9', '--json', $jsonPath, '--yes',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $input->setInteractive(false);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $request = $factory->recorded()[0][0];
        $this->assertSame('DELETE', $request->method());
        $this->assertSame(['ids' => [3, 4]], $request->data());
        $this->assertFalse($request->hasHeader('X-Idempotency-Key'));
        $this->assertStringContainsString('/materials/keyword-libraries/9/items', $request->url());
    }

    #[Test]
    #[DataProvider('deleteCommands')]
    public function delete_commands_reject_idempotency_keys_before_http(array $command): void
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, array_merge(
            $command,
            ['--yes', '--idempotency-key', 'unsupported-delete-key'],
        ));

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected DELETE idempotency keys to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('不支持选项 --idempotency-key', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    /** @return iterable<string,array{list<string>}> */
    public static function deleteCommands(): iterable
    {
        yield 'task' => [['task', 'delete', '7']];
        yield 'material' => [['material', 'delete', 'keywords', '9']];
        yield 'material item' => [['material', 'item-delete', 'keywords', '9', '--ids', '3']];
    }

    #[Test]
    public function item_delete_accepts_a_comma_separated_ids_list(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-delete', 'keywords', '9', '--ids', '3, 4,3', '--yes',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $input->setInteractive(false);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $this->assertSame(['ids' => [3, 4]], $factory->recorded()[0][0]->data());
    }

    #[Test]
    public function item_delete_rejects_conflicting_ids_and_json_inputs(): void
    {
        $jsonPath = $this->root.'/conflicting-ids.json';
        file_put_contents($jsonPath, '{"ids":[3]}');
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-delete', 'keywords', '9', '--ids', '3', '--json', $jsonPath, '--yes',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('不能同时');

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
    }

    #[Test]
    #[DataProvider('invalidItemIds')]
    public function item_delete_rejects_invalid_ids(string $ids): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-delete', 'keywords', '9', '--ids='.$ids, '--yes',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('正整数');

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidItemIds(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['1,two'];
        yield 'empty segment' => ['1,,2'];
    }

    #[Test]
    public function knowledge_chunks_are_read_only_in_the_cli(): void
    {
        $jsonPath = $this->root.'/item.json';
        file_put_contents($jsonPath, '{"content":"x"}');
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-create', 'knowledge', '2', '--json', $jsonPath,
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('只读');

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
    }

    #[Test]
    public function config_init_and_show_keep_the_token_private(): void
    {
        $path = $this->root.'/config/config.json';
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'config', 'init', '--base-url', 'http://api.example.com', '--token', 'very-secret-token',
            '--allow-insecure-http', '--file', $path,
        ]);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertStringNotContainsString('very-secret-token', $stdout->fetch());

        [$dispatcher, $showInput, $showOutput, $showError] = $this->harness($factory, [
            'config', 'show', '--config', $path,
        ]);
        $dispatcher->dispatch($showInput->getRawTokens(), $showInput, $showOutput, $showError);
        $shown = $showOutput->fetch();
        $this->assertStringContainsString('token_masked', $shown);
        $this->assertStringNotContainsString('very-secret-token', $shown);
    }

    #[Test]
    public function config_show_reports_default_directory_permission_repairs_to_stderr(): void
    {
        $directory = $this->root.'/home/.config/geoflow';
        mkdir($directory, 0755, true);
        file_put_contents($directory.'/config.json', '{"base_url":"https://api.example.com","token":"secret-token"}');
        chmod($directory.'/config.json', 0600);
        chmod($directory, 0755);
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, ['config', 'show']);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertStringContainsString('0700', $stderr->fetch());
    }

    #[Test]
    public function login_checks_config_overwrite_before_requesting_a_token(): void
    {
        $path = $this->root.'/existing.json';
        file_put_contents($path, '{"base_url":"https://old.example.com","token":"old-token"}');
        $factory = $this->successfulFactory('{"success":true,"data":{"token":"new-token"}}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'login', '--config', $path, '--base-url', 'https://api.example.com',
            '--username', 'admin', '--password', 'password',
        ]);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected overwrite protection.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function login_signing_and_save_run_inside_one_sidecar_lock(): void
    {
        $repository = new class($this->root.'/cwd', $this->root.'/home') extends ConfigurationRepository
        {
            public bool $insideLock = false;

            public function withLock(string $path, callable $callback): mixed
            {
                return parent::withLock($path, function (string $lockedPath) use ($callback): mixed {
                    $this->insideLock = true;
                    try {
                        return $callback($lockedPath);
                    } finally {
                        $this->insideLock = false;
                    }
                });
            }
        };
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $factory->fake(function () use ($factory, $repository) {
            $this->assertTrue($repository->insideLock);

            return $factory->response([
                'success' => true,
                'data' => ['token' => 'new-secret-token'],
            ]);
        });
        $tokens = [
            'login', '--base-url', 'https://api.example.com', '--username', 'admin',
            '--password-stdin', '--config', $this->root.'/login.json',
        ];
        $dispatcher = new CommandDispatcher($factory, $repository);
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "password-from-stdin\n");
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(false);

        $status = $dispatcher->dispatch($tokens, $input, new BufferedOutput, new BufferedOutput);

        fclose($stream);
        $this->assertSame(0, $status);
        $this->assertFalse($repository->insideLock);
        $this->assertSame('password-from-stdin', $factory->recorded()[0][0]->data()['password']);
        $this->assertFileExists($this->root.'/login.json.lock');
    }

    #[Test]
    public function non_interactive_login_rejects_an_implicit_local_target_before_http(): void
    {
        file_put_contents($this->root.'/cwd/.geoflow.json', '{"base_url":"https://local-target.example.com"}');
        $factory = $this->successfulFactory('{"success":true,"data":{"token":"new-token"}}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'login', '--username', 'admin', '--password', 'password',
        ]);
        $input->setInteractive(false);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--base-url');

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
        } finally {
            $this->assertCount(0, $factory->recorded());
        }
    }

    #[Test]
    public function interactive_login_confirms_the_exact_implicit_local_target(): void
    {
        file_put_contents($this->root.'/cwd/.geoflow.json', '{"base_url":"https://local-target.example.com"}');
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => true,
            'data' => ['token' => 'new-token'],
        ], 200));
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'login', '--username', 'admin', '--password', 'password',
        ]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "y\n");
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(true);

        $status = $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        fclose($stream);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('https://local-target.example.com', $stderr->fetch());
        $this->assertSame('https://local-target.example.com/api/v1/auth/login', $factory->recorded()[0][0]->url());
    }

    #[Test]
    public function interactive_delete_accepts_tty_confirmation(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'task', 'delete', '7', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);
        $input->setInteractive(true);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "y\n");
        rewind($stream);
        $input->setStream($stream);

        $status = $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        fclose($stream);
        $this->assertSame(0, $status);
        $this->assertSame('DELETE', $factory->recorded()[0][0]->method());
    }

    #[Test]
    public function extra_positionals_are_rejected_before_any_request(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'task', 'get', '7', 'unexpected', '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('位置参数');

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
        } finally {
            $this->assertCount(0, $factory->recorded());
        }
    }

    #[Test]
    public function no_interaction_disables_delete_prompts_without_swallowing_the_command(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            '--no-interaction', 'task', 'delete', '7',
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--yes');

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
    }

    #[Test]
    public function oversized_json_input_is_rejected_before_http(): void
    {
        $path = $this->root.'/oversized.json';
        file_put_contents($path, '{"value":"'.str_repeat('x', 5 * 1024 * 1024).'"}');
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'task', 'create', '--json', $path,
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('5 MiB');

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
        } finally {
            $this->assertCount(0, $factory->recorded());
        }
    }

    #[Test]
    public function json_input_symlinks_are_rejected_before_http(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Native Windows symlink behavior is outside the supported CLI surface.');
        }

        $target = $this->root.'/outside-payload.json';
        $link = $this->root.'/cwd/payload.json';
        file_put_contents($target, '{"secret":"outside-workspace"}');
        symlink($target, $link);
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'task', 'create', '--json', $link,
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected the payload symlink to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('符号链接', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function image_input_symlinks_are_rejected_before_http(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Native Windows symlink behavior is outside the supported CLI surface.');
        }

        $target = $this->root.'/outside-image.png';
        $link = $this->root.'/cwd/image.png';
        file_put_contents($target, 'outside-workspace-image');
        symlink($target, $link);
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'material', 'item-upload', 'images', '9', '--image', $link,
            '--base-url', 'https://api.example.com', '--token', 'secret-token',
        ]);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected the image symlink to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('符号链接', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function token_stdin_supplies_the_bearer_without_argv_secret(): void
    {
        $factory = $this->successfulFactory('{"success":true}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'catalog', '--base-url', 'https://api.example.com', '--token-stdin',
        ]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "stdin-secret-token\n");
        rewind($stream);
        $input->setStream($stream);

        $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);

        fclose($stream);
        $this->assertTrue($factory->recorded()[0][0]->hasHeader('Authorization', 'Bearer stdin-secret-token'));
    }

    #[Test]
    public function an_implicit_local_endpoint_rejects_an_environment_token_before_http(): void
    {
        file_put_contents($this->root.'/cwd/.geoflow.json', '{"base_url":"https://local.example.com"}');
        $previous = getenv('GEOFLOW_TOKEN');
        putenv('GEOFLOW_TOKEN=environment-secret-token');
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, ['catalog']);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected endpoint and credential binding to reject the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--base-url', $exception->getMessage());
            $this->assertStringNotContainsString('environment-secret-token', $exception->getMessage());
        } finally {
            $previous === false ? putenv('GEOFLOW_TOKEN') : putenv('GEOFLOW_TOKEN='.$previous);
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function an_implicit_local_endpoint_rejects_a_stdin_token_before_http(): void
    {
        file_put_contents($this->root.'/cwd/.geoflow.json', '{"base_url":"https://local.example.com"}');
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, ['catalog', '--token-stdin']);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "stdin-secret-token\n");
        rewind($stream);
        $input->setStream($stream);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected endpoint and credential binding to reject the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--config', $exception->getMessage());
            $this->assertStringNotContainsString('stdin-secret-token', $exception->getMessage());
        } finally {
            fclose($stream);
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function a_cli_endpoint_rejects_a_file_token_before_http(): void
    {
        mkdir($this->root.'/home/.config/geoflow', 0700, true);
        file_put_contents(
            $this->root.'/home/.config/geoflow/config.json',
            '{"base_url":"https://home.example.com","token":"home-file-secret"}',
        );
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'catalog', '--base-url', 'https://cli.example.com',
        ]);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected endpoint and credential binding to reject the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--token-stdin', $exception->getMessage());
            $this->assertStringNotContainsString('home-file-secret', $exception->getMessage());
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function a_cli_endpoint_rejects_an_environment_token_before_http(): void
    {
        $previous = getenv('GEOFLOW_TOKEN');
        putenv('GEOFLOW_TOKEN=environment-secret-token');
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'catalog', '--base-url', 'https://cli.example.com',
        ]);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected endpoint and credential binding to reject the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('--token-stdin', $exception->getMessage());
            $this->assertStringNotContainsString('environment-secret-token', $exception->getMessage());
        } finally {
            $previous === false ? putenv('GEOFLOW_TOKEN') : putenv('GEOFLOW_TOKEN='.$previous);
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function an_environment_endpoint_rejects_a_file_token_before_http(): void
    {
        mkdir($this->root.'/home/.config/geoflow', 0700, true);
        file_put_contents(
            $this->root.'/home/.config/geoflow/config.json',
            '{"base_url":"https://home.example.com","token":"home-file-secret"}',
        );
        $previous = getenv('GEOFLOW_BASE_URL');
        putenv('GEOFLOW_BASE_URL=https://environment.example.com');
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, ['catalog']);

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
            $this->fail('Expected endpoint and credential binding to reject the request.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('GEOFLOW_TOKEN', $exception->getMessage());
            $this->assertStringNotContainsString('home-file-secret', $exception->getMessage());
        } finally {
            $previous === false ? putenv('GEOFLOW_BASE_URL') : putenv('GEOFLOW_BASE_URL='.$previous);
        }

        $this->assertCount(0, $factory->recorded());
    }

    #[Test]
    public function argv_and_stdin_password_sources_are_mutually_exclusive(): void
    {
        $factory = $this->successfulFactory('{"success":true,"data":{"token":"new-token"}}');
        [$dispatcher, $input, $stdout, $stderr] = $this->harness($factory, [
            'login', '--base-url', 'https://api.example.com', '--username', 'admin',
            '--password', 'argv-password', '--password-stdin',
        ]);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('不能同时');

        try {
            $dispatcher->dispatch($input->getRawTokens(), $input, $stdout, $stderr);
        } finally {
            $this->assertCount(0, $factory->recorded());
        }
    }

    private function successfulFactory(string $body): HttpFactory
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $factory->fake(fn () => $factory->response($body, 200, ['Content-Type' => 'application/json']));

        return $factory;
    }

    /**
     * @param  list<string>  $tokens
     * @return array{CommandDispatcher,ArgvInput,BufferedOutput,BufferedOutput}
     */
    private function harness(HttpFactory $factory, array $tokens): array
    {
        $repository = new ConfigurationRepository($this->root.'/cwd', $this->root.'/home');
        $dispatcher = new CommandDispatcher($factory, $repository);
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));

        return [$dispatcher, $input, new BufferedOutput, new BufferedOutput];
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
