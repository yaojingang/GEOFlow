<?php

namespace App\Console\GeoFlowCli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CommandContext
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<string>  $positionals
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public readonly array $positionals,
        public array $options,
        public readonly InputInterface $input,
        public readonly OutputInterface $output,
        public readonly OutputInterface $errorOutput,
    ) {}

    /** @param list<string> $warnings */
    public function deferWarnings(array $warnings): void
    {
        $this->warnings = array_merge($this->warnings, $warnings);
    }

    public function flushWarnings(): void
    {
        foreach ($this->warnings as $warning) {
            $this->errorOutput->writeln(SecretRedactor::text($warning));
        }
        $this->warnings = [];
    }

    /** @return list<string> */
    public function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];

        return $warnings;
    }
}
