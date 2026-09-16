<?php

namespace App\Services\SystemUpdater;

final class AgentProtocolException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly string $errorCode, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct('Updater rejected the request ('.$errorCode.').');
    }
}
