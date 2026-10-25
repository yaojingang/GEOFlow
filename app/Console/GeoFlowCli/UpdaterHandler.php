<?php

namespace App\Console\GeoFlowCli;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;

final class UpdaterHandler
{
    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(): int
    {
        $args = $this->runtime->context->positionals;
        if ($args[1] === 'operation' && $args[2] === 'wait') {
            return $this->wait($args[3]);
        }
        $session = $this->session(in_array($args[1], ['plan', 'update', 'backup', 'restore', 'switch-back'], true));
        if ($args[1] === 'operation') {
            $byRequest = $args[2] === 'lookup';
            $result = $this->runtime->apiClient()->send($byRequest ? 'updater.requests.lookup' : 'updater.operations.show', [$byRequest ? 'requestId' : 'operationId' => $args[3]]);
            OperationJournal::recordResponse($this->runtime->configuration, $session, $result->payload['data'] ?? [], $byRequest ? $args[3] : null);
            $this->runtime->writeJson($result->payload);

            return 0;
        }
        if (in_array($args[1], ['status', 'recovery-points'], true)) {
            $this->runtime->writeJson($this->runtime->apiClient()->send('updater.'.$args[1])->payload);

            return 0;
        }
        if ($args[1] === 'plan') {
            $action = $this->runtime->requiredOption('action');
            if (! in_array($action, ['update', 'backup', 'restore', 'switch-back'], true)) {
                throw new CliException('计划动作必须是 update、backup、restore 或 switch-back');
            }
            $body = ['action' => $action, 'instance_id' => $session['instance_id']];
            if ($action === 'restore') {
                $body['recovery_point_id'] = $this->runtime->requiredOption('recovery-point');
            } elseif (isset($this->runtime->context->options['recovery-point'])) {
                throw new CliException('--recovery-point 仅用于 restore 计划');
            }
            $result = $this->runtime->apiClient()->send('updater.plan', body: $body);
            $payload = $result->payload;
            $payload['data']['management_instance_id'] = $session['instance_id'];
            $this->runtime->writeJson($payload);

            return 0;
        }

        return $this->submit($args[1], $session);
    }

    private function submit(string $action, array $session): int
    {
        $requestId = $this->runtime->requiredOption('client-request-id');
        $planInput = $this->runtime->jsonBody('plan');
        $plan = $planInput['data'] ?? $planInput;
        if (! is_array($plan) || ($plan['schema_version'] ?? null) !== 2 || ($plan['action'] ?? null) !== $action
            || ($plan['management_instance_id'] ?? null) !== $session['instance_id']) {
            throw new CliException('计划与目标实例或动作不匹配，请读取此实例生成的计划文件');
        }
        foreach (['plan_id' => 32, 'plan_sha256' => 64, 'expected_epoch' => 32] as $field => $length) {
            if (! is_string($plan[$field] ?? null) || preg_match('/\A[a-f0-9]{'.$length.'}\z/', $plan[$field]) !== 1) {
                throw new CliException('计划标识或恢复代次无效');
            }
        }
        $body = ['action' => $action, 'plan_id' => $plan['plan_id'], 'plan_sha256' => $plan['plan_sha256'], 'expected_epoch' => $plan['expected_epoch'],
            'allow_maintenance' => $this->runtime->flag('allow-maintenance'), 'confirm_host_access' => $this->runtime->flag('confirm-host-access')];
        $credentials = [];
        if (! OperationJournal::isPrepared($this->runtime->configuration, $session, $requestId)) {
            if (($plan['maintenance_required'] ?? null) === true && ! $body['allow_maintenance']) {
                throw new CliException('此计划需要 --allow-maintenance；保留请求 ID，先核对计划和维护窗口');
            }
            if (($plan['continuation'] ?? null) === 'host_only' && ! $body['confirm_host_access']) {
                throw new CliException('此计划需要 --confirm-host-access 确认宿主机续接条件');
            }
            $credentials = $this->credentials();
        }
        $prepared = OperationJournal::prepare($this->runtime->configuration, $session, 'updater.'.$action, $body, $requestId);
        $this->runtime->context->errorOutput->writeln('client_request_id='.$requestId.'；续接：geoflow updater operation lookup '.$requestId);
        if (($plan['continuation'] ?? null) === 'host_only') {
            $this->runtime->context->errorOutput->writeln('目标版本仅支持宿主机续接，请保存请求 ID 并使用 geoflow-updater request '.$requestId.' 查询。');
        }
        if ($prepared['repeated']) {
            try {
                $result = $this->runtime->apiClient()->send('updater.requests.lookup', ['requestId' => $requestId]);
            } catch (\Throwable) {
                throw new CliException('原请求 '.$requestId.' 的结果尚无法确认；未重发。请恢复连接后查询，或请服务器管理员核对宿主机接纳日志。');
            }
            OperationJournal::recordResponse($this->runtime->configuration, $session, $result->payload['data'] ?? [], $requestId);
            $this->runtime->writeJson($result->payload);

            return 0;
        }
        $result = $this->runtime->apiClient()->send('updater.operations.create', body: $body + [
            'client_request_id' => $requestId, 'instance_id' => $session['instance_id'],
        ] + $credentials, clientRequestId: $requestId);
        OperationJournal::recordResponse($this->runtime->configuration, $session, $result->payload['data'] ?? [], $requestId);
        $this->runtime->writeJson(SecretRedactor::payload($result->payload, SecretRedactor::sensitiveValues($credentials)));

        return 0;
    }

    private function session(bool $bound, ?float $timeoutSeconds = null): array
    {
        $session = $this->runtime->apiClient()->send('auth.session', timeoutSeconds: $timeoutSeconds)->payload['data'] ?? [];
        $config = $this->runtime->configuration->resolve($this->runtime->context->options, true);
        if (($session['protocol_version'] ?? null) !== '1.0' || ! is_string($session['instance_id'] ?? null)
            || ! is_array($session['admin'] ?? null) || ! isset($session['admin']['id'])
            || ($bound && (empty($config['instance_id']) || empty($config['admin_id'])))
            || (isset($config['instance_id']) && $config['instance_id'] !== $session['instance_id'])
            || (isset($config['admin_id']) && (string) $config['admin_id'] !== (string) $session['admin']['id'])) {
            throw new CliException('管理实例或账号身份未绑定或已改变，请重新登录核验目标');
        }

        return $session;
    }

    private function credentials(): array
    {
        $file = $this->runtime->context->options['credentials-file'] ?? null;
        if ($file === null) {
            if (! $this->runtime->context->input->isInteractive()) {
                throw new CliException('非交互运维需要 --credentials-file，文件仅当前用户可读，包含 password 与 authorization_code');
            }
            $values = [];
            foreach (['password' => '当前管理员密码：', 'authorization_code' => '宿主机动态授权码：'] as $key => $label) {
                $question = (new Question($label))->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
                $values[$key] = rtrim((string) (new QuestionHelper)->ask($this->runtime->context->input, $this->runtime->context->errorOutput, $question), "\r\n");
            }

            return $this->validateCredentials($values);
        }
        $path = $this->runtime->configuration->expandPath((string) $file);
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new CliException('运维授权输入必须是当前用户拥有且权限为 0600 的普通文件');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new CliException('无法读取受保护的运维授权输入');
        }
        try {
            $opened = fstat($stream);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0077) !== 0
                || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new CliException('运维授权输入在读取时发生变化');
            }
            $raw = stream_get_contents($stream, 8193);
            if (! is_string($raw) || strlen($raw) > 8192) {
                throw new CliException('运维授权输入超过 8 KiB');
            }
            $credentials = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CliException('运维授权输入必须是 JSON 对象');
        } finally {
            fclose($stream);
        }

        return $this->validateCredentials($credentials);
    }

    private function validateCredentials(#[\SensitiveParameter] mixed $credentials): array
    {
        if (! is_array($credentials) || array_diff(array_keys($credentials), ['password', 'authorization_code']) !== []
            || ! is_string($credentials['password'] ?? null) || ! is_string($credentials['authorization_code'] ?? null)
            || ! mb_check_encoding($credentials['password'], 'UTF-8') || mb_strlen($credentials['password'], 'UTF-8') > 4096
            || preg_match('/\A[0-9]{6}\z/', $credentials['authorization_code']) !== 1) {
            throw new CliException('运维授权输入需要不超过 4096 字的 password 和六位 authorization_code');
        }

        return $credentials;
    }

    private function wait(string $requestId): int
    {
        $seconds = filter_var($this->runtime->context->options['wait-seconds'] ?? 600, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3600]]);
        if ($seconds === false || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $requestId) !== 1) {
            throw new CliException('请求 ID 无效或等待时长不在 0 至 3600 秒之间');
        }
        $deadline = microtime(true) + $seconds;
        $delay = 2;
        $last = ['success' => true, 'data' => ['client_request_id' => $requestId, 'state' => 'pending']];
        do {
            $retryAfter = null;
            try {
                $session = $this->session(false, $seconds > 0 ? max(0.001, $deadline - microtime(true)) : null);
                $result = $this->runtime->apiClient()->send('updater.requests.lookup', ['requestId' => $requestId], timeoutSeconds: $seconds > 0 ? max(0.001, $deadline - microtime(true)) : null);
                $last = $result->payload;
                OperationJournal::recordResponse($this->runtime->configuration, $session, $last['data'] ?? [], $requestId);
                $state = $last['data']['state'] ?? 'pending';
                if (in_array($state, ['succeeded', 'failed', 'rolled_back', 'recovery_required'], true)) {
                    $this->runtime->writeJson($last);

                    return $state === 'succeeded' ? 0 : ($state === 'recovery_required' ? 2 : 1);
                }
            } catch (ApiException $exception) {
                if (in_array($exception->httpStatus, [401, 403], true)) {
                    throw $exception;
                }
                if ($exception->httpStatus === 404) {
                    $last['data']['continuation'] = 'host_only_or_request_pending';
                }
                $retryAfter = $exception->retryAfterSeconds;
            } catch (CliException) {
                $last['data']['connection'] = 'unavailable_or_identity_changed';
                // A read failure never authorizes a mutation or invalidates the original request.
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $last['data']['client_request_id'] = $requestId;
                $last['data']['resume'] = 'geoflow updater operation lookup '.$requestId;
                $this->runtime->writeJson($last);

                return 2;
            }
            usleep((int) (min($retryAfter ?? $delay, $remaining) * 1000000));
            $delay = min(30, $delay * 2);
        } while (true);
    }
}
