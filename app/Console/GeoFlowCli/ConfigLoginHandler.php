<?php

namespace App\Console\GeoFlowCli;

final class ConfigLoginHandler
{
    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(string $command): int
    {
        return $command === 'login' ? $this->login() : $this->config();
    }

    private function config(): int
    {
        $action = $this->runtime->context->positionals[1];
        if ($action === 'show') {
            $config = $this->runtime->configuration->resolve($this->runtime->context->options, false);
            $this->runtime->writeConfigWarnings($config);
            $this->runtime->writeJson([
                'config_files' => $config['config_files'],
                'base_url' => $config['base_url'],
                'base_url_source' => $config['base_url_source'],
                'token_masked' => SecretRedactor::mask(isset($config['token']) ? (string) $config['token'] : null),
                'token_source' => $config['token_source'],
                'timeout' => $config['timeout'],
                'timeout_source' => $config['timeout_source'],
                'allow_insecure_http' => $config['allow_insecure_http'],
                'allow_insecure_http_source' => $config['allow_insecure_http_source'],
                'endpoint_source_type' => $config['endpoint_source_type'],
                'credential_source_type' => $config['credential_source_type'],
                'credential_binding' => $config['credential_binding'],
                'credential_binding_valid' => $config['credential_binding_valid'],
            ]);

            return 0;
        }

        $path = $this->runtime->targetConfigPath();
        $allowInsecureHttp = $this->runtime->flag('allow-insecure-http');
        $baseUrl = BaseUrlPolicy::validate($this->runtime->requiredOption('base-url'), $allowInsecureHttp);
        $token = trim((string) ($this->runtime->context->options['token'] ?? ''));
        if ($token === '') {
            $token = $this->environmentToken();
        }
        if ($token === '' && ! $this->runtime->context->input->isInteractive()) {
            throw new CliException('缺少 token。请使用 GEOFLOW_TOKEN/GEOFLOW_API_TOKEN/--token-stdin；交互模式会使用隐藏提示');
        }
        if ($token === '') {
            $token = $this->runtime->prompt('API Token: ', true);
        }
        if ($token === '') {
            throw new CliException('token 不能为空');
        }

        $timeout = $this->runtime->integerOption('timeout', 30);
        $payload = [
            'base_url' => $baseUrl,
            'token' => $token,
            'timeout' => $timeout,
            'allow_insecure_http' => $allowInsecureHttp,
        ];
        $warnings = $this->runtime->configuration->withLock(
            $path,
            function (string $lockedPath) use ($payload): array {
                if (is_file($lockedPath) && ! $this->runtime->flag('force')) {
                    throw new CliException("配置文件已存在，使用 --force 覆盖: {$lockedPath}");
                }

                return $this->runtime->configuration->saveLocked($lockedPath, $payload);
            },
        );
        foreach ($warnings as $warning) {
            $this->runtime->context->errorOutput->writeln(SecretRedactor::text($warning));
        }
        $this->runtime->writeJson([
            'saved' => true,
            'config_file' => $path,
            'base_url' => $baseUrl,
            'token_masked' => SecretRedactor::mask($token),
            'timeout' => $timeout,
            'allow_insecure_http' => $allowInsecureHttp,
        ]);

        return 0;
    }

    private function login(): int
    {
        $path = $this->runtime->targetConfigPath();
        if (is_file($path) && ! $this->runtime->flag('force')) {
            throw new CliException("配置文件已存在，登录前请传入 --force 允许覆盖: {$path}");
        }
        $config = $this->runtime->configuration->resolve($this->runtime->context->options, false);
        $this->runtime->deferConfigWarnings($config);
        if (! is_string($config['base_url']) || $config['base_url'] === '') {
            throw new CliException('缺少系统地址，请传入 --base-url 或提供配置');
        }

        $usesImplicitLocalTarget = $config['profile_source'] === 'local'
            && ! array_key_exists('base-url', $this->runtime->context->options)
            && ! array_key_exists('config', $this->runtime->context->options);
        if ($usesImplicitLocalTarget) {
            if (! $this->runtime->context->input->isInteractive()) {
                throw new CliException('非交互登录使用 cwd 配置时必须显式传入 --base-url 或 --config');
            }
            if (! $this->runtime->confirm('即将向 '.$config['base_url'].' 发送登录凭据，确认继续？ [y/N] ')) {
                throw new CliException('登录操作已取消');
            }
        }

        $username = trim((string) ($this->runtime->context->options['username'] ?? ''));
        if ($username === '') {
            $username = $this->runtime->prompt('管理员用户名: ');
        }
        if ($this->runtime->flag('password-stdin') && array_key_exists('password', $this->runtime->context->options)) {
            throw new CliException('--password 和 --password-stdin 不能同时使用');
        }
        $password = $this->runtime->flag('password-stdin')
            ? $this->runtime->readSecretLine('password')
            : (string) ($this->runtime->context->options['password'] ?? '');
        if ($password === '' && ! $this->runtime->context->input->isInteractive()) {
            throw new CliException('缺少密码。请使用 --password-stdin；交互模式会使用隐藏提示');
        }
        if ($password === '') {
            $password = $this->runtime->prompt('管理员密码: ', true);
        }
        if ($username === '' || $password === '') {
            throw new CliException('用户名和密码不能为空');
        }

        $result = $this->runtime->configuration->withLock(
            $path,
            function (string $lockedPath) use ($config, $username, $password): array {
                if (is_file($lockedPath) && ! $this->runtime->flag('force')) {
                    throw new CliException("配置文件已存在，登录前请传入 --force 允许覆盖: {$lockedPath}");
                }

                $apiResult = $this->runtime->client(
                    $config['base_url'],
                    null,
                    $config['timeout'],
                )->send('auth.login', body: [
                    'username' => $username,
                    'password' => $password,
                ]);
                $token = trim((string) ($apiResult->payload['data']['token'] ?? ''));
                if ($token === '') {
                    throw new CliException('登录成功，但服务端没有返回 token');
                }

                $warnings = $this->runtime->configuration->saveLocked($lockedPath, [
                    'base_url' => $config['base_url'],
                    'token' => $token,
                    'timeout' => $config['timeout'],
                    'allow_insecure_http' => $config['allow_insecure_http'],
                ]);

                return ['api_result' => $apiResult, 'token' => $token, 'warnings' => $warnings];
            },
        );

        $this->runtime->context->deferWarnings($result['warnings']);
        $this->runtime->context->flushWarnings();
        $this->runtime->writeJson([
            'logged_in' => true,
            'config_file' => $path,
            'base_url' => $config['base_url'],
            'token_masked' => SecretRedactor::mask($result['token']),
            'admin' => $result['api_result']->payload['data']['admin'] ?? null,
            'expires_at' => $result['api_result']->payload['data']['expires_at'] ?? null,
        ]);

        return 0;
    }

    private function environmentToken(): string
    {
        foreach (['GEOFLOW_TOKEN', 'GEOFLOW_API_TOKEN'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
