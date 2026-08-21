<?php

namespace App\Exceptions;

use RuntimeException;

class ManualPublicationConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('admin.manual_publications.error.revision_conflict'));
    }
}
