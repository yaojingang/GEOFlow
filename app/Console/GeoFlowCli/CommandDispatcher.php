<?php

namespace App\Console\GeoFlowCli;

use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CommandDispatcher
{
    private const ARGV_CREDENTIAL_WARNING = '警告: 明文 argv 凭据选项 --token/--password 已弃用，并将在下一主版本移除；Token 请使用 GEOFLOW_TOKEN/GEOFLOW_API_TOKEN/--token-stdin，密码请使用隐藏提示/--password-stdin。';

    private ?CommandContext $lastContext = null;

    /** @var list<string> */
    private array $preflightWarnings = [];

    public function __construct(
        private readonly HttpFactory $httpFactory,
        private readonly ConfigurationRepository $configuration = new ConfigurationRepository,
    ) {}

    /** @return list<string> */
    public function takeDeferredWarnings(): array
    {
        $warnings = array_merge($this->preflightWarnings, $this->lastContext?->takeWarnings() ?? []);
        $this->preflightWarnings = [];

        return $warnings;
    }

    /** @param list<string> $tokens */
    public function dispatch(
        array $tokens,
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
    ): int {
        $this->lastContext = null;
        $this->preflightWarnings = $this->usesArgvCredential($tokens)
            ? [self::ARGV_CREDENTIAL_WARNING]
            : [];
        $parsed = ArgumentParser::parse($tokens);
        $context = new CommandContext(
            $parsed->positionals,
            $parsed->options,
            $input,
            $output,
            $errorOutput,
        );
        $context->deferWarnings($this->preflightWarnings);
        $this->preflightWarnings = [];
        $this->lastContext = $context;
        $this->applyStandardOptions($parsed->options, $input, $output);

        if (($parsed->positionals[0] ?? '') === 'version' && count($parsed->positionals) !== 1) {
            throw new CliException('version 命令不接受位置参数');
        }
        if (($parsed->positionals[0] ?? '') === 'help' && count($parsed->positionals) !== 1) {
            throw new CliException('help 命令不接受位置参数');
        }
        if ($this->flag($parsed->options, 'version') || ($parsed->positionals[0] ?? '') === 'version') {
            $this->writeJson($output, ['name' => 'geoflow', 'version' => CliVersion::VALUE]);
            $context->flushWarnings();

            return 0;
        }
        if ($this->flag($parsed->options, 'help') || ($parsed->positionals[0] ?? 'help') === 'help') {
            $output->writeln(CommandSpec::usage());
            $context->flushWarnings();

            return 0;
        }
        if ($parsed->positionals === ['config', 'help']) {
            $this->writeJson($output, [
                'usage' => [
                    'geoflow config init --base-url URL [--token-stdin] [--timeout 30] [--file PATH] [--force]',
                    'geoflow config show [--config PATH]',
                ],
            ]);
            $context->flushWarnings();

            return 0;
        }

        $spec = CommandSpec::resolve($parsed->positionals);
        CommandSpec::validateOptions($spec, $parsed->options);
        $runtime = new CommandRuntime($this->httpFactory, $this->configuration, $context);
        $runtime->resolveTokenStdin();
        if ($spec['operation'] !== null && $spec['operation'] !== 'auth.login') {
            $runtime->primeApiClient();
        }

        $status = match ($parsed->positionals[0]) {
            'config', 'login' => (new ConfigLoginHandler($runtime))->handle($parsed->positionals[0]),
            'catalog' => $runtime->send('catalog'),
            'task', 'job' => (new TaskJobHandler($runtime))->handle($parsed->positionals[0]),
            'material' => (new MaterialHandler($runtime))->handle(),
            'article' => (new ArticleHandler($runtime))->handle(),
        };
        $context->flushWarnings();

        return $status;
    }

    /** @param array<string,mixed> $options */
    private function applyStandardOptions(array $options, InputInterface $input, OutputInterface $output): void
    {
        if ($this->flag($options, 'no-interaction')) {
            $input->setInteractive(false);
        }
        if ($this->flag($options, 'ansi') && $this->flag($options, 'no-ansi')) {
            throw new CliException('--ansi 和 --no-ansi 不能同时使用');
        }
        if ($this->flag($options, 'ansi')) {
            $output->setDecorated(true);
        } elseif ($this->flag($options, 'no-ansi')) {
            $output->setDecorated(false);
        }
        if ($this->flag($options, 'quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);

            return;
        }

        $verbosity = (int) ($options['verbose'] ?? 0);
        if ($verbosity > 0) {
            $output->setVerbosity(match (true) {
                $verbosity >= 3 => OutputInterface::VERBOSITY_DEBUG,
                $verbosity === 2 => OutputInterface::VERBOSITY_VERY_VERBOSE,
                default => OutputInterface::VERBOSITY_VERBOSE,
            });
        }
    }

    /** @param array<string,mixed> $options */
    private function flag(array $options, string $name): bool
    {
        if (! array_key_exists($name, $options)) {
            return false;
        }
        if (is_bool($options[$name])) {
            return $options[$name];
        }

        return filter_var($options[$name], FILTER_VALIDATE_BOOLEAN) === true;
    }

    /** @param array<string,mixed> $payload */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param list<string> $tokens */
    private function usesArgvCredential(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--') {
                return false;
            }
            if (in_array($token, ['--token', '--password'], true)
                || str_starts_with($token, '--token=')
                || str_starts_with($token, '--password=')) {
                return true;
            }
        }

        return false;
    }
}
