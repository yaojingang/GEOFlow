<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** A remote write succeeded before the publisher could persist its local bookkeeping. */
class DistributionAcknowledgedResponseException extends RuntimeException
{
    public function __construct(public readonly array $response, Throwable $previous)
    {
        parent::__construct('distribution_acknowledged_local_write_failed', 0, $previous);
    }
}
