<?php

namespace App\Console\GeoFlowCli;

use RuntimeException;

class CliException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 1)
    {
        parent::__construct($message);
    }
}
