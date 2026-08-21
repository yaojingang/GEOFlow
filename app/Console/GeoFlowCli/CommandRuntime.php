<?php

namespace App\Console\GeoFlowCli;

use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class CommandRuntime
{
    public const MAX_INPUT_BYTES = 5 * 1024 * 1024;

    private ?ApiClient $resolvedApiClient = null;

    public function __construct(
        private readonly HttpFactory $httpFactory,
        public readonly ConfigurationRepository $configuration,
        public readonly CommandContext $context,
    ) {}

    public function resolveTokenStdin(): void
    {
        if (! $this->flag('token-stdin')) {
            return;
        }
        if (array_key_exists('token', $this->context->options)) {
            throw new CliException('--token 和 --token-stdin 不能同时使用');
        }

        $this->context->options['token'] = $this->readSecretLine('token');
        $this->context->options['_token_source'] = 'cli:stdin';
    }

    /**
     * @param  array<string,int|string>  $pathParameters
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     */
    public function send(
        string $operation,
        array $pathParameters = [],
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?string $uploadPath = null,
    ): int {
        $result = $this->apiClient()->send(
            $operation,
            $pathParameters,
            $query,
            $body,
            $idempotencyKey,
            $uploadPath,
        );
        $this->context->flushWarnings();
        $this->context->output->write($result->raw);
        if (! str_ends_with($result->raw, "\n")) {
            $this->context->output->writeln('');
        }

        return 0;
    }

    public function apiClient(): ApiClient
    {
        if ($this->resolvedApiClient !== null) {
            return $this->resolvedApiClient;
        }

        $config = $this->configuration->resolve($this->context->options, true);
        $this->deferConfigWarnings($config);

        return $this->resolvedApiClient = new ApiClient(
            $this->httpFactory,
            (string) $config['base_url'],
            (string) $config['token'],
            $config['timeout'],
        );
    }

    public function primeApiClient(): void
    {
        $this->apiClient();
    }

    public function client(string $baseUrl, ?string $token, int $timeout): ApiClient
    {
        return new ApiClient($this->httpFactory, $baseUrl, $token, $timeout);
    }

    /** @param array<string,mixed> $config */
    public function deferConfigWarnings(array $config): void
    {
        $this->context->deferWarnings($config['warnings'] ?? []);
    }

    /** @param array<string,mixed> $config */
    public function writeConfigWarnings(array $config): void
    {
        foreach ($config['warnings'] ?? [] as $warning) {
            $this->context->errorOutput->writeln(SecretRedactor::text($warning));
        }
    }

    public function targetConfigPath(): string
    {
        $path = $this->context->options['file']
            ?? $this->context->options['config']
            ?? $this->configuration->defaultPath();

        return $this->configuration->expandPath((string) $path);
    }

    public function requiredOption(string $name): string
    {
        $value = trim((string) ($this->context->options[$name] ?? ''));
        if ($value === '') {
            throw new CliException("缺少必填参数 --{$name}");
        }

        return $value;
    }

    public function integerOption(string $name, int $default): int
    {
        if (! isset($this->context->options[$name]) || $this->context->options[$name] === '') {
            return $default;
        }

        $value = filter_var((string) $this->context->options[$name], FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw new CliException("--{$name} 必须是正整数");
        }

        return $value;
    }

    public function optionalInteger(string $name): ?int
    {
        if (! isset($this->context->options[$name]) || $this->context->options[$name] === '') {
            return null;
        }

        return $this->integerOption($name, 1);
    }

    public function positiveId(?string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new CliException("缺少有效的{$label}");
        }

        return $id;
    }

    public function flag(string $name): bool
    {
        if (! array_key_exists($name, $this->context->options)) {
            return false;
        }
        if (is_bool($this->context->options[$name])) {
            return $this->context->options[$name];
        }

        return filter_var($this->context->options[$name], FILTER_VALIDATE_BOOLEAN) === true;
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) ($this->context->options['idempotency-key'] ?? ''));

        return $key === '' ? null : $key;
    }

    /** @return array<string,mixed> */
    public function jsonBody(string $option = 'json'): array
    {
        return $this->loadJson($this->requiredOption($option));
    }

    /** @return array<string,mixed> */
    public function loadJson(string $path): array
    {
        if ($path === '-') {
            $raw = $this->readInputStream(self::MAX_INPUT_BYTES + 1);
        } else {
            $expandedPath = $this->configuration->expandPath($path);
            $raw = $this->readRegularFile($expandedPath, 'JSON 输入', self::MAX_INPUT_BYTES + 1);
        }
        if (is_string($raw) && strlen($raw) > self::MAX_INPUT_BYTES) {
            throw new CliException("JSON 输入超过 5 MiB 安全上限: {$path}");
        }
        if (! is_string($raw) || trim($raw) === '') {
            throw new CliException("无法读取 JSON 输入: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CliException("JSON 输入格式无效: {$path}");
        }
        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{')) {
            throw new CliException("JSON 输入必须是对象: {$path}");
        }

        return $decoded;
    }

    public function readInputStream(?int $length = null): string
    {
        $stream = $this->context->input instanceof StreamableInputInterface
            ? $this->context->input->getStream()
            : null;
        $raw = $length === null
            ? stream_get_contents($stream ?? STDIN)
            : stream_get_contents($stream ?? STDIN, $length);

        return $raw === false ? '' : $raw;
    }

    public function readSecretLine(string $label): string
    {
        $stream = $this->context->input instanceof StreamableInputInterface
            ? $this->context->input->getStream()
            : null;
        $value = fgets($stream ?? STDIN, 65537);
        if (! is_string($value) || (! str_ends_with($value, "\n") && strlen($value) >= 65536)) {
            throw new CliException("{$label} stdin 输入无效或超过 64 KiB");
        }
        $value = rtrim($value, "\r\n");
        if ($value === '') {
            throw new CliException("{$label} stdin 输入不能为空");
        }

        return $value;
    }

    public function confirmDeletion(string $target): void
    {
        if ($this->flag('yes')) {
            return;
        }
        if (! $this->context->input->isInteractive()) {
            throw new CliException("非交互环境删除 {$target} 必须传入 --yes");
        }

        $question = new ConfirmationQuestion("确认删除 {$target}？ [y/N] ", false);
        if (! (new QuestionHelper)->ask($this->context->input, $this->context->errorOutput, $question)) {
            throw new CliException('删除操作已取消');
        }
    }

    public function confirm(string $question): bool
    {
        return (bool) (new QuestionHelper)->ask(
            $this->context->input,
            $this->context->errorOutput,
            new ConfirmationQuestion($question, false),
        );
    }

    public function prompt(string $label, bool $hidden = false): string
    {
        if (! $this->context->input->isInteractive()) {
            throw new CliException("非交互环境必须通过参数提供 {$label}");
        }

        $question = new Question($label);
        if ($hidden) {
            $question->setHidden(true);
        }

        return trim((string) (new QuestionHelper)->ask(
            $this->context->input,
            $this->context->errorOutput,
            $question,
        ));
    }

    /** @param array<string,mixed> $payload */
    public function writeJson(array $payload): void
    {
        $this->context->output->writeln(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    public function loadText(string $path): string
    {
        if ($path === '-') {
            $raw = $this->readInputStream(self::MAX_INPUT_BYTES + 1);
        } else {
            $expandedPath = $this->configuration->expandPath($path);
            $raw = $this->readRegularFile($expandedPath, '文本输入', self::MAX_INPUT_BYTES + 1);
        }
        if (! is_string($raw)) {
            throw new CliException("无法读取文本输入: {$path}");
        }
        if (strlen($raw) > self::MAX_INPUT_BYTES) {
            throw new CliException("文本输入超过 5 MiB 安全上限: {$path}");
        }

        return $raw;
    }

    private function readRegularFile(string $path, string $label, int $length): string
    {
        if (is_link($path)) {
            throw new CliException("{$label}不能是符号链接: {$path}");
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new CliException("无法读取{$label}: {$path}");
        }

        try {
            $handleStat = fstat($stream);
            $pathStat = @lstat($path);
            if ($handleStat === false
                || $pathStat === false
                || ($handleStat['mode'] & 0170000) !== 0100000
                || ($pathStat['mode'] & 0170000) !== 0100000
                || $handleStat['dev'] !== $pathStat['dev']
                || $handleStat['ino'] !== $pathStat['ino']) {
                throw new CliException("{$label}路径在打开时发生变化或不是普通文件: {$path}");
            }

            $raw = stream_get_contents($stream, $length);

            return $raw === false ? '' : $raw;
        } finally {
            fclose($stream);
        }
    }
}
