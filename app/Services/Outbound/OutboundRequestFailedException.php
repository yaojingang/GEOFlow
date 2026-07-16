<?php

namespace App\Services\Outbound;

use RuntimeException;

final class OutboundRequestFailedException extends RuntimeException
{
    public readonly string $reasonCode;

    public function __construct()
    {
        $this->reasonCode = 'outbound_request_failed';

        parent::__construct('Outbound request failed.');
    }
}
