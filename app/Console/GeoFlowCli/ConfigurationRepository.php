<?php

namespace App\Console\GeoFlowCli;

use JsonException;

class ConfigurationRepository
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly ?string $homeDirectory = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array{
     *     base_url: ?string,
     *     base_url_source: ?string,
     *     token: ?string,
     *     token_source: ?string,
     *     timeout: int,
     *     timeout_source: string,
     *     allow_insecure_http: bool,
     *     allow_insecure_http_source: string,
     *     profile_source: 'explicit'|'local'|'home',
     *     profile_path: string,
     *     endpoint_source_type: string,
     *     credential_source_type: string,
     *     credential_binding: string,
     *     credential_binding_valid: bool,
     *     config_files: list<string>,
     *     warnings: list<string>
     * }
     */
    public function resolve(array $options, bool $requireApi): array
    {
        $profile = $this->candidateProfile($options);
        $config = [
            'base_url' => null,
            'base_url_source' => null,
            'token' => null,
            'token_source' => null,
            'timeout' => 30,
            'timeout_source' => 'default',
            'allow_insecure_http' => false,
            'allow_insecure_http_source' => 'default',
            'profile_source' => $profile['source'],
            'profile_path' => $profile['path'],
            'endpoint_source_type' => 'missing',
            'credential_source_type' => 'missing',
            'credential_binding' => 'not_applicable',
            'credential_binding_valid' => true,
            'config_files' => [],
            'warnings' => [],
        ];

        foreach ([$profile['path']] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $loaded = $this->load($path);
            $config['config_files'][] = $path;
            foreach (['base_url', 'token', 'timeout', 'allow_insecure_http'] as $key) {
                if (! array_key_exists($key, $loaded) || $loaded[$key] === null || $loaded[$key] === '') {
                    continue;
                }

                $config[$key] = $loaded[$key];
                $config[$key.'_source'] = 'file:'.$path;
            }

            if (($loaded['token'] ?? null) !== null) {
                $warning = $this->repairPermissions($path);
                if ($warning !== null) {
                    $config['warnings'][] = $warning;
                }
                $directoryWarning = $this->repairDefaultDirectoryPermissions($path);
                if ($directoryWarning !== null) {
                    $config['warnings'][] = $directoryWarning;
                }
            }
        }

        $this->applyEnvironment($config);
        $this->applyOptions($config, $options);

        $config['timeout'] = $this->positiveInteger($config['timeout'], 'timeout');
        $config['allow_insecure_http'] = $this->boolean($config['allow_insecure_http'], 'allow_insecure_http');

        if (is_string($config['base_url']) && trim($config['base_url']) !== '') {
            $config['base_url'] = BaseUrlPolicy::validate($config['base_url'], $config['allow_insecure_http']);
            $this->assertInsecureHttpGrantIsBound($config);
        }

        if ($requireApi && $config['base_url'] === null) {
            throw new CliException('缺少 base_url。请运行 geoflow config init、传入 --base-url 或设置 GEOFLOW_BASE_URL');
        }

        if ($requireApi && (! is_string($config['token']) || trim($config['token']) === '')) {
            throw new CliException('缺少 token。请运行 geoflow login，或使用 GEOFLOW_TOKEN/GEOFLOW_API_TOKEN/--token-stdin');
        }

        $this->applyCredentialBinding($config, $requireApi);

        return $config;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    public function save(string $path, array $payload): array
    {
        return $this->withLock(
            $path,
            fn (string $lockedPath): array => $this->saveLocked($lockedPath, $payload),
        );
    }

    public function withLock(string $path, callable $callback): mixed
    {
        $path = $this->expandPath($path);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new CliException("无法创建配置目录: {$directory}");
        }

        $lockPath = $path.'.lock';
        if (is_link($lockPath)) {
            throw new CliException("配置锁不能是符号链接: {$lockPath}");
        }
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new CliException("无法创建配置锁: {$lockPath}");
        }

        try {
            $handleStat = fstat($lock);
            $pathStat = @lstat($lockPath);
            if (
                $handleStat === false
                || $pathStat === false
                || ($pathStat['mode'] & 0170000) !== 0100000
                || $handleStat['dev'] !== $pathStat['dev']
                || $handleStat['ino'] !== $pathStat['ino']
            ) {
                throw new CliException("配置锁路径在打开时发生变化: {$lockPath}");
            }
            if (PHP_OS_FAMILY !== 'Windows' && ! chmod($lockPath, 0600)) {
                throw new CliException("无法限制配置锁权限: {$lockPath}");
            }
            $securedPathStat = @lstat($lockPath);
            if (
                $securedPathStat === false
                || ($securedPathStat['mode'] & 0170000) !== 0100000
                || $handleStat['dev'] !== $securedPathStat['dev']
                || $handleStat['ino'] !== $securedPathStat['ino']
            ) {
                throw new CliException("配置锁路径在加固时发生变化: {$lockPath}");
            }
            if (! flock($lock, LOCK_EX)) {
                throw new CliException("无法获取配置锁: {$lockPath}");
            }

            return $callback($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $payload @return list<string> */
    public function saveLocked(string $path, array $payload): array
    {
        $path = $this->expandPath($path);
        $directory = dirname($path);
        $warnings = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $warnings[] = "警告: 原生 Windows 无法验证配置文件 ACL；请使用 WSL 或手动限制访问权限: {$path}";
        }
        $directoryWarning = $this->repairDefaultDirectoryPermissions($path);
        if ($directoryWarning !== null) {
            $warnings[] = $directoryWarning;
        }

        $temporaryPath = tempnam($directory, '.geoflow.');
        if ($temporaryPath === false) {
            throw new CliException("无法创建临时配置文件: {$directory}");
        }

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
            $temporaryPermissionsReady = PHP_OS_FAMILY === 'Windows' || chmod($temporaryPath, 0600);
            if (! $temporaryPermissionsReady || file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new CliException("无法写入配置文件: {$path}");
            }
            if (! rename($temporaryPath, $path)) {
                throw new CliException("无法原子替换配置文件: {$path}");
            }
            if (PHP_OS_FAMILY !== 'Windows' && ! chmod($path, 0600)) {
                throw new CliException("无法限制配置文件权限: {$path}");
            }
        } catch (JsonException $exception) {
            throw new CliException('配置内容无法编码为 JSON: '.$exception->getMessage());
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return $warnings;
    }

    /** @return array<string,mixed> */
    public function load(string $path): array
    {
        $path = $this->expandPath($path);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new CliException("无法读取配置文件: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CliException("配置文件不是有效 JSON: {$path}");
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{')) {
            throw new CliException("配置文件必须包含 JSON 对象: {$path}");
        }

        return [
            'base_url' => $this->nullableString($decoded, 'base_url', $path),
            'token' => $this->nullableString($decoded, 'token', $path),
            'timeout' => $decoded['timeout'] ?? null,
            'allow_insecure_http' => $decoded['allow_insecure_http'] ?? null,
        ];
    }

    public function defaultPath(): string
    {
        return rtrim($this->home(), '/').'/.config/geoflow/config.json';
    }

    public function expandPath(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            return $this->home().substr($path, 1);
        }

        return $path;
    }

    /** @param array<string,mixed> $options @return array{source:'explicit'|'local'|'home',path:string} */
    private function candidateProfile(array $options): array
    {
        if (isset($options['config']) && trim((string) $options['config']) !== '') {
            return ['source' => 'explicit', 'path' => $this->expandPath((string) $options['config'])];
        }

        $localPath = rtrim($this->cwd(), '/').'/.geoflow.json';
        if (is_file($localPath)) {
            return ['source' => 'local', 'path' => $localPath];
        }

        return ['source' => 'home', 'path' => $this->defaultPath()];
    }

    /** @param array<string,mixed> $config */
    private function applyEnvironment(array &$config): void
    {
        $environment = [
            'base_url' => ['GEOFLOW_BASE_URL'],
            'token' => ['GEOFLOW_TOKEN', 'GEOFLOW_API_TOKEN'],
            'timeout' => ['GEOFLOW_TIMEOUT'],
            'allow_insecure_http' => ['GEOFLOW_ALLOW_INSECURE_HTTP'],
        ];

        foreach ($environment as $key => $names) {
            foreach ($names as $name) {
                $value = getenv($name);
                if ($value === false || $value === '') {
                    continue;
                }

                $config[$key] = $value;
                $config[$key.'_source'] = 'env:'.$name;
                break;
            }
        }
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $options */
    private function applyOptions(array &$config, array $options): void
    {
        foreach ([
            'base-url' => 'base_url',
            'token' => 'token',
            'timeout' => 'timeout',
            'allow-insecure-http' => 'allow_insecure_http',
        ] as $option => $key) {
            if (! array_key_exists($option, $options) || $options[$option] === '') {
                continue;
            }

            $config[$key] = $options[$option];
            $config[$key.'_source'] = match ($option) {
                'token' => (string) ($options['_token_source'] ?? 'cli:argv'),
                default => 'cli:argv',
            };
        }
    }

    /** @param array<string,mixed> $config */
    private function applyCredentialBinding(array &$config, bool $enforce): void
    {
        $endpointSource = (string) ($config['base_url_source'] ?? '');
        $credentialSource = (string) ($config['token_source'] ?? '');
        $config['endpoint_source_type'] = $this->sourceType($endpointSource);
        $config['credential_source_type'] = $this->sourceType($credentialSource);

        if ($endpointSource === '' || $credentialSource === '') {
            return;
        }

        $profileTokenSource = 'file:'.$config['profile_path'];
        if ($config['profile_source'] === 'local' && $endpointSource === $profileTokenSource) {
            $config['credential_binding'] = $credentialSource === $profileTokenSource
                ? 'same_local_profile'
                : 'unsafe_local_endpoint_override';
            $config['credential_binding_valid'] = $credentialSource === $profileTokenSource;
            if ($enforce && ! $config['credential_binding_valid']) {
                throw new CliException('隐式 cwd endpoint 只能使用同一 .geoflow.json 内的 token；请显式传入 --base-url 或 --config');
            }

            return;
        }

        if ($endpointSource === 'cli:argv') {
            $valid = in_array($credentialSource, ['cli:argv', 'cli:stdin'], true);
            $config['credential_binding'] = $valid ? 'explicit_cli_pair' : 'unsafe_cli_endpoint_inheritance';
            $config['credential_binding_valid'] = $valid;
            if ($enforce && ! $valid) {
                throw new CliException('CLI endpoint 需要同次显式凭证；请使用 --token-stdin，或通过 --config 选择可信 profile');
            }

            return;
        }

        if (str_starts_with($endpointSource, 'env:')) {
            $valid = ! str_starts_with($credentialSource, 'file:');
            $config['credential_binding'] = $valid ? 'explicit_environment_endpoint' : 'unsafe_environment_endpoint_inheritance';
            $config['credential_binding_valid'] = $valid;
            if ($enforce && ! $valid) {
                throw new CliException('环境 endpoint 不能继承配置文件 token；请使用 GEOFLOW_TOKEN/GEOFLOW_API_TOKEN/--token-stdin 或显式 --config');
            }

            return;
        }

        $config['credential_binding'] = match ($config['profile_source']) {
            'explicit' => 'trusted_explicit_profile',
            'home' => 'trusted_home_profile',
            default => 'same_profile',
        };
    }

    private function sourceType(string $source): string
    {
        return match (true) {
            $source === '' => 'missing',
            str_starts_with($source, 'file:') => 'file',
            str_starts_with($source, 'env:') => 'environment',
            $source === 'cli:stdin' => 'stdin',
            str_starts_with($source, 'cli:') => 'argv',
            default => 'unknown',
        };
    }

    /** @param array<string,mixed> $config */
    private function assertInsecureHttpGrantIsBound(array $config): void
    {
        if (! $config['allow_insecure_http']
            || ! BaseUrlPolicy::requiresInsecureHttpOverride((string) $config['base_url'])) {
            return;
        }

        $endpointSource = (string) ($config['base_url_source'] ?? '');
        $allowSource = (string) ($config['allow_insecure_http_source'] ?? '');
        $endpointOverridesProfile = $endpointSource === 'cli:argv' || str_starts_with($endpointSource, 'env:');
        if ($endpointOverridesProfile && str_starts_with($allowSource, 'file:')) {
            throw new CliException('远程 HTTP endpoint 不能继承配置文件的放行设置；请在同次调用传入 --allow-insecure-http 或设置 GEOFLOW_ALLOW_INSECURE_HTTP');
        }
    }

    private function repairPermissions(string $path): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return "警告: 原生 Windows 无法验证配置文件 ACL；请使用 WSL 或手动限制访问权限: {$path}";
        }

        $permissions = @fileperms($path);
        if ($permissions === false || ($permissions & 0077) === 0) {
            return null;
        }

        if (! @chmod($path, 0600)) {
            throw new CliException("包含 token 的配置文件权限过宽且无法修复: {$path}");
        }
        clearstatcache(true, $path);

        return "警告: 已将包含 token 的配置文件权限修复为 0600: {$path}";
    }

    private function repairDefaultDirectoryPermissions(string $path): ?string
    {
        if (PHP_OS_FAMILY === 'Windows' || $path !== $this->defaultPath()) {
            return null;
        }

        $directory = dirname($path);
        $permissions = @fileperms($directory);
        if ($permissions === false || ($permissions & 0077) === 0) {
            return null;
        }
        if (! @chmod($directory, 0700)) {
            throw new CliException("配置目录权限过宽且无法修复: {$directory}");
        }
        clearstatcache(true, $directory);

        return "警告: 已将配置目录权限修复为 0700: {$directory}";
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new CliException("{$name} 必须是正整数");
        }
        $string = (string) $value;
        if (filter_var($string, FILTER_VALIDATE_INT) === false || (int) $string <= 0) {
            throw new CliException("{$name} 必须是正整数");
        }

        return (int) $string;
    }

    private function boolean(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_int($value) && ! is_string($value)) {
            throw new CliException("{$name} 必须是布尔值");
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new CliException("{$name} 必须是布尔值");
        }

        return $parsed;
    }

    /** @param array<string,mixed> $config */
    private function nullableString(array $config, string $key, string $path): ?string
    {
        if (! array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }
        if (! is_string($config[$key])) {
            throw new CliException("配置字段 {$key} 必须是字符串: {$path}");
        }

        return $config[$key];
    }

    private function cwd(): string
    {
        if ($this->workingDirectory !== null) {
            return $this->workingDirectory;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new CliException('无法解析当前工作目录');
        }

        return $cwd;
    }

    private function home(): string
    {
        if ($this->homeDirectory !== null) {
            return $this->homeDirectory;
        }

        foreach (['HOME', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA'] as $variable) {
            $home = getenv($variable);
            if (is_string($home) && $home !== '') {
                return $home;
            }
        }

        throw new CliException('无法解析用户配置目录（HOME/USERPROFILE/LOCALAPPDATA/APPDATA）');
    }
}
