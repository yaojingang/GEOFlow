<?php

namespace App\Console\GeoFlowCli;

use App\Support\Api\ManagementOperationRegistry;

final class ManagementHandler
{
    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(): int
    {
        $command = $this->runtime->context->positionals[0];
        if ($command === 'profile') {
            return $this->profile();
        }
        if ($command === 'logout') {
            return $this->logout();
        }
        if ($command === 'api') {
            return $this->api();
        }
        if ($command === 'site' || $command === 'operation') {
            return $this->semantic($command);
        }

        try {
            $result = $this->runtime->apiClient()->send($command === 'whoami' ? 'auth.session' : 'capabilities');
        } catch (ApiException $exception) {
            if ($command !== 'doctor' || $exception->httpStatus !== 404) {
                throw $exception;
            }
            $catalog = $this->runtime->apiClient()->send('catalog')->payload['data'] ?? [];
            if (! is_array($catalog) || ! isset($catalog['models'], $catalog['categories'])) {
                throw new CliException('无法确认目标是兼容的 GEOFlow 实例，请检查地址与部署前缀');
            }
            $this->runtime->writeJson(['success' => true, 'data' => ['protocol' => 'legacy-v1', 'management_supported' => false, 'upgrade_required' => true]]);

            return 0;
        }
        $this->assertIdentity($result->payload['data'] ?? []);
        $this->runtime->writeJson($result->payload);

        return 0;
    }

    private function profile(): int
    {
        if ($this->runtime->context->positionals[1] === 'bind') {
            return $this->bindProfile();
        }
        if ($this->runtime->context->positionals[1] === 'list') {
            $this->runtime->writeJson(['profiles' => $this->runtime->configuration->profileNames()]);
        } else {
            $path = $this->runtime->configuration->profilePath($this->runtime->context->positionals[2]);
            $config = $this->runtime->configuration->load($path);
            unset($config['token']);
            $this->runtime->writeJson(['profile' => $this->runtime->context->positionals[2], 'config_file' => $path, 'config' => $config]);
        }

        return 0;
    }

    private function bindProfile(): int
    {
        $options = $this->runtime->context->options;
        if (! isset($options['profile']) && ! isset($options['config'])) {
            throw new CliException('身份绑定必须显式选择 --profile 或 --config');
        }
        $instanceId = $this->runtime->requiredOption('instance-id');
        $adminId = (string) $this->runtime->positiveId($this->runtime->requiredOption('admin-id'), '账号 ID');
        if (strlen($instanceId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $instanceId) === 1) {
            throw new CliException('预期实例身份格式无效');
        }
        $config = $this->runtime->configuration->resolve($options, true);
        $path = $config['profile_path'];
        if ($config['base_url_source'] !== 'file:'.$path || $config['token_source'] !== 'file:'.$path) {
            throw new CliException('身份绑定必须使用同一配置文件中的地址和 token，请移除环境或命令行凭据覆盖');
        }
        $this->runtime->deferConfigWarnings($config);
        $this->runtime->configuration->withLock($path, function (string $lockedPath) use ($config, $instanceId, $adminId): void {
            if (is_link($lockedPath) || ! is_file($lockedPath)) {
                throw new CliException('身份绑定要求现有普通配置文件');
            }
            $saved = $this->runtime->configuration->load($lockedPath);
            if ($saved['token'] !== $config['token'] || ! is_string($saved['base_url'])
                || BaseUrlPolicy::validate($saved['base_url'], $config['allow_insecure_http']) !== $config['base_url']
                || $saved['instance_id'] !== $config['instance_id'] || $saved['admin_id'] !== $config['admin_id']) {
                throw new CliException('配置已经变化，请重新读取目标身份后绑定');
            }
            $session = $this->runtime->client($config['base_url'], $saved['token'], $config['timeout'])->send('auth.session')->payload['data'] ?? [];
            if (! is_array($session)) {
                throw new CliException('目标未提供兼容的管理身份');
            }
            $this->assertCompatibleIdentity($session);
            if ($session['instance_id'] !== $instanceId || (string) $session['admin']['id'] !== $adminId) {
                throw new CliException('目标实例或账号与预期身份不一致，未修改配置');
            }
            if (is_link($lockedPath) || ! is_file($lockedPath) || $this->runtime->configuration->load($lockedPath) !== $saved) {
                throw new CliException('身份查询期间配置已经变化，未覆盖新的配置或凭据');
            }
            $warnings = $this->runtime->configuration->saveLocked($lockedPath, array_replace($saved, ['instance_id' => $instanceId, 'admin_id' => $adminId]));
            $this->runtime->context->deferWarnings($warnings);
        });
        $this->runtime->writeJson(['bound' => true, 'config_file' => $path, 'base_url' => $config['base_url'], 'instance_id' => $instanceId, 'admin_id' => $adminId]);

        return 0;
    }

    /** @param array<string, mixed> $data */
    private function assertIdentity(array $data, bool $requiresBinding = false): void
    {
        $config = $this->runtime->configuration->resolve($this->runtime->context->options, true);
        $this->assertCompatibleIdentity($data);
        if ($requiresBinding && (! is_string($config['instance_id']) || $config['instance_id'] === '' || ! is_string($config['admin_id']) || $config['admin_id'] === '')) {
            throw new CliException('管理写入需要实例和账号身份绑定，请先使用 login 或显式 profile bind 保存目标身份');
        }
        if (is_string($config['instance_id']) && $config['instance_id'] !== $data['instance_id']) {
            throw new CliException('实例身份已变化，请重新核对地址并登录或显式绑定；未执行管理写入');
        }
        if (is_string($config['admin_id']) && $config['admin_id'] !== (string) ($data['admin']['id'] ?? '')) {
            throw new CliException('当前凭据所属账号与 profile 不一致，请重新登录或显式绑定');
        }
    }

    private function assertCompatibleIdentity(array $data): void
    {
        if (! is_string($data['instance_id'] ?? null) || trim($data['instance_id']) === ''
            || ! is_array($data['admin'] ?? null)
            || (! is_int($data['admin']['id'] ?? null) && ! is_string($data['admin']['id'] ?? null))
            || filter_var($data['admin']['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ($data['protocol_version'] ?? null) !== ManagementOperationRegistry::PROTOCOL_VERSION) {
            throw new CliException('目标未提供兼容的管理契约，请检查实例和 CLI 版本');
        }
    }

    private function api(): int
    {
        $name = $this->runtime->context->positionals[1];
        if (! isset(ManagementOperationRegistry::all()[$name])) {
            throw new CliException('未知管理 operation ID，请先读取 capabilities');
        }
        $operation = ManagementOperationRegistry::get($name);
        if (($operation['receipt'] ?? false) && array_key_exists('idempotency-key', $this->runtime->context->options)) {
            throw new CliException('管理收据操作不支持 --idempotency-key，请使用 --client-request-id；未发送请求');
        }
        foreach (['client-request-id' => 'receipt', 'idempotency-key' => 'idempotent'] as $option => $capability) {
            if (array_key_exists($option, $this->runtime->context->options) && ! ($operation[$capability] ?? false)) {
                throw new CliException('此 operation 不支持 --'.$option.'，未发送请求；请按其能力契约查询结果');
            }
        }
        if ($name === 'auth.logout') {
            return $this->logout();
        }
        $input = isset($this->runtime->context->options['input']) ? $this->runtime->jsonBody('input') : [];
        foreach ($input as $key => $value) {
            if (! in_array($key, ['path', 'query', 'body'], true) || ! is_array($value)) {
                throw new CliException('管理输入只接受 path、query、body 对象');
            }
        }
        $capabilities = $this->runtime->apiClient()->send('capabilities')->payload['data'] ?? [];
        $this->assertIdentity($capabilities, ! in_array($operation['method'], ['GET', 'HEAD'], true));
        if (! in_array($name, array_column($capabilities['operations'] ?? [], 'name'), true)) {
            throw new CliException('目标实例或当前权限未开放此 operation');
        }

        $requestId = null;
        if ($operation['receipt'] ?? false) {
            $prepared = OperationJournal::prepare($this->runtime->configuration, $capabilities, $name, $input, $this->runtime->context->options['client-request-id'] ?? null);
            $requestId = $prepared['client_request_id'];
            $this->runtime->context->errorOutput->writeln('client_request_id='.$requestId.'；可使用 operation lookup 查询结果');
            if ($prepared['repeated']) {
                try {
                    $result = $this->runtime->apiClient()->send('operations.lookup', query: ['client_request_id' => $requestId]);
                    OperationJournal::recordResponse($this->runtime->configuration, $capabilities, $result->payload['data'] ?? [], $requestId);
                    $this->runtime->writeJson($result->payload);

                    return 0;
                } catch (ApiException $exception) {
                    if ($exception->httpStatus !== 404 || ($exception->payload['error']['code'] ?? null) !== 'operation_not_found') {
                        throw $exception;
                    }
                    if ($prepared['operation_id'] !== null) {
                        throw new CliException('本地已有操作收据，远端记录暂不可用；未重新执行，请核对实例恢复或收据保留状态');
                    }
                }
            }
        }
        $result = $this->runtime->apiClient()->send($name, $input['path'] ?? [], $input['query'] ?? [], $input['body'] ?? null, $this->runtime->idempotencyKey(), clientRequestId: $requestId);
        if ($requestId !== null) {
            OperationJournal::recordResponse($this->runtime->configuration, $capabilities, $result->payload['data'] ?? [], $requestId);
        } elseif (in_array($name, ['operations.lookup', 'operations.show'], true)) {
            OperationJournal::recordResponse($this->runtime->configuration, $capabilities, $result->payload['data'] ?? [], $input['query']['client_request_id'] ?? null);
        }
        $this->runtime->writeJson($result->payload);

        return 0;
    }

    private function semantic(string $command): int
    {
        $args = $this->runtime->context->positionals;
        $session = $this->runtime->apiClient()->send('capabilities')->payload['data'] ?? [];
        $this->assertIdentity($session);
        $name = match ($command.'.'.$args[1]) {
            'site.list' => 'sites.list', 'site.show' => 'sites.show',
            'operation.get', 'operation.wait' => 'operations.show', 'operation.lookup' => 'operations.lookup',
        };
        if (! in_array($name, array_column($session['operations'] ?? [], 'name'), true)) {
            throw new CliException('实例或当前凭据未开放此操作');
        }
        $path = match ($name) {
            'sites.show' => ['site' => $args[2]], 'operations.show' => ['operation' => $args[2]], default => [],
        };
        $query = $name === 'operations.lookup' ? ['client_request_id' => $args[2]] : [];
        $wait = $command === 'operation' && $args[1] === 'wait';
        $seconds = $wait ? filter_var($this->runtime->context->options['wait-seconds'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]) : 0;
        if ($seconds === false) {
            throw new CliException('--wait-seconds 应在 0 至 60 秒之间');
        }
        $deadline = microtime(true) + $seconds;
        $delay = 1;
        do {
            $result = $this->runtime->apiClient()->send($name, $path, $query, timeoutSeconds: $wait && $seconds > 0 ? max(0.001, $deadline - microtime(true)) : null);
            if ($command === 'operation') {
                OperationJournal::recordResponse($this->runtime->configuration, $session, $result->payload['data'] ?? [], $name === 'operations.lookup' ? $args[2] : null);
            }
            $state = $result->payload['data']['state'] ?? null;
            if (! $wait || ! in_array($state, ['queued', 'running'], true) || microtime(true) >= $deadline) {
                $this->runtime->writeJson($result->payload);

                return $wait ? (match ($state) {
                    'succeeded' => 0, 'queued', 'running' => 2, default => 1
                }) : 0;
            }
            usleep((int) (min($delay, max(0, $deadline - microtime(true))) * 1000000));
            if (microtime(true) >= $deadline) {
                $this->runtime->writeJson($result->payload);

                return 2;
            }
            $delay = min(5, $delay * 2);
        } while (true);
    }

    private function logout(): int
    {
        $config = $this->runtime->configuration->resolve($this->runtime->context->options, true);
        $path = $config['profile_path'];
        $localCleared = false;
        $revoked = false;
        $this->runtime->configuration->withLock($path, function (string $lockedPath) use ($config, &$localCleared, &$revoked): void {
            if (is_link($lockedPath)) {
                throw new CliException('退出时拒绝修改符号链接配置');
            }
            $saved = is_file($lockedPath) ? $this->runtime->configuration->load($lockedPath) : null;
            if ($saved !== null && $saved['token'] !== $config['token']) {
                throw new CliException('配置凭据已经变化，未撤销或清除其他登录');
            }
            try {
                $session = $this->runtime->apiClient()->send('auth.session')->payload['data'] ?? [];
                $this->assertIdentity($session);
                $revoked = ($this->runtime->apiClient()->send('auth.logout')->payload['data']['revoked'] ?? false) === true;
            } catch (ApiException|CliException $exception) {
                $this->runtime->context->deferWarnings(['远端令牌撤销未确认: '.SecretRedactor::text($exception->getMessage())]);
            }
            if ($saved !== null) {
                $saved['token'] = null;
                $saved['admin_id'] = null;
                $this->runtime->configuration->saveLocked($lockedPath, $saved);
                $localCleared = true;
            }
        });
        $this->runtime->writeJson(['revoked' => $revoked, 'local_credentials_cleared' => $localCleared]);

        return $revoked ? 0 : 1;
    }
}
