<?php

namespace App\Services\Outbound;

use RuntimeException;

final class OutboundRequestBlockedException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Outbound request blocked by security policy.');
    }
}
