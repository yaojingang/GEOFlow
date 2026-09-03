<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;

class AdminAiModelTestBoundaryHook
{
    public function beforeRevalidation(AdminAiModelTestSnapshot $snapshot): void {}

    public function afterOutboundBeforePersist(AdminAiModelTestSnapshot $snapshot): void {}
}
