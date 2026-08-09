<?php

namespace App\Console\GeoFlowCli;

use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GeoFlowApplication extends Application
{
    public const VERSION = CliVersion::VALUE;

    private readonly CommandDispatcher $dispatcher;

    public function __construct(
        HttpFactory $httpFactory,
        ?ConfigurationRepository $configuration = null,
    ) {
        parent::__construct('geoflow', self::VERSION);

        $this->dispatcher = new CommandDispatcher(
            $httpFactory,
            $configuration ?? new ConfigurationRepository,
        );
        $this->setCatchExceptions(false);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if (! $input instanceof ArgvInput) {
            throw new CliException('GEOFlow CLI 需要 ArgvInput');
        }

        $errorOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        return $this->dispatcher->dispatch($input->getRawTokens(), $input, $output, $errorOutput);
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        // CommandDispatcher validates global options before applying their IO effects.
    }

    /** @return list<string> */
    public function takeDeferredWarnings(): array
    {
        return $this->dispatcher->takeDeferredWarnings();
    }
}
