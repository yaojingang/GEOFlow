<?php

namespace App\Data\Admin;

use App\Models\Admin;

final readonly class AdminAiActorContext
{
    public function __construct(
        public Admin $actor,
        public ?Admin $sharedProvider,
    ) {}

    public function hasActiveSharedProvider(): bool
    {
        return $this->sharedProvider instanceof Admin
            && (string) $this->sharedProvider->status === 'active'
            && $this->sharedProvider->isSuperAdmin();
    }
}
