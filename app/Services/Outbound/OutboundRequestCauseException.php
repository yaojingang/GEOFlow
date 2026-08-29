<?php

namespace App\Services\Outbound;

use RuntimeException;

final class OutboundRequestCauseException extends RuntimeException
{
    public function __construct(public readonly string $causeType)
    {
        parent::__construct('Outbound transport cause metadata retained.');
    }
}
