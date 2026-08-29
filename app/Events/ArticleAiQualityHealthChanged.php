<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArticleAiQualityHealthChanged
{
    use Dispatchable;
    use SerializesModels;

    /** @param array<string, mixed> $snapshot */
    public function __construct(public readonly array $snapshot) {}
}
