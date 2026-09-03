<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiSourceProviderTestSnapshot;
use App\Models\Admin;

class AdminAiSystemConfigurationBoundaryHook
{
    public function beforeMutation(Admin $actor): void {}

    public function afterModelMutationBeforeBinding(Admin $actor): void {}

    public function beforeProviderOutbound(AdminAiSourceProviderTestSnapshot $snapshot): void {}

    public function afterProviderOutbound(AdminAiSourceProviderTestSnapshot $snapshot): void {}
}
