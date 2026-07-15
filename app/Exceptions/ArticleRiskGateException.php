<?php

namespace App\Exceptions;

use App\Models\ArticleRiskScan;
use RuntimeException;

class ArticleRiskGateException extends RuntimeException
{
    public readonly string $riskStatus;

    public function __construct(public readonly ArticleRiskScan $scan)
    {
        $this->riskStatus = (string) $scan->status;

        parent::__construct("Article risk gate rejected status [{$this->riskStatus}].");
    }
}
