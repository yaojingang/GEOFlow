<?php

namespace App\Data\Admin;

final readonly class AdminAiSharingImpact
{
    /**
     * @param  list<int>  $sharedDefaultModelIds
     * @param  array{queued: int, active: int, total: int}  $pendingTaskCounts
     */
    public function __construct(
        public array $sharedDefaultModelIds,
        public array $pendingTaskCounts,
    ) {}

    public function sharedDefaultCount(): int
    {
        return count($this->sharedDefaultModelIds);
    }
}
