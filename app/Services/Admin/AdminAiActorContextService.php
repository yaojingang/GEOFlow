<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiActorContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;

final class AdminAiActorContextService
{
    public function resolve(Admin $authenticatedActor): AdminAiActorContext
    {
        $actor = Admin::query()
            ->with('sharedAiConfigOwner:id,username,display_name,role,status')
            ->whereKey($authenticatedActor->getKey())
            ->active()
            ->first();
        if (! $actor instanceof Admin) {
            throw AiModelAccessException::executionAdminInactive($authenticatedActor);
        }

        $provider = $actor->sharedAiConfigOwner;

        return new AdminAiActorContext(
            actor: $actor,
            sharedProvider: $provider instanceof Admin ? $provider : null,
        );
    }
}
