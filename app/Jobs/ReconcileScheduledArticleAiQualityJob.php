<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;

class ReconcileScheduledArticleAiQualityJob extends ReconcileArticleAiQualityJob implements ShouldBeUnique
{
    public int $uniqueFor = 1200;

    public function uniqueId(): string
    {
        return 'full-backfill';
    }
}
