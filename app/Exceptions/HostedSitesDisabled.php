<?php

namespace App\Exceptions;

use RuntimeException;

final class HostedSitesDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Hosted sites are temporarily disabled.');
    }
}
