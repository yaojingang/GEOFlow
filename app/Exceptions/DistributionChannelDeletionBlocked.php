<?php

namespace App\Exceptions;

use RuntimeException;

class DistributionChannelDeletionBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
