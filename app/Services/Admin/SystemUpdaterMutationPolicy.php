<?php

namespace App\Services\Admin;

class SystemUpdaterMutationPolicy
{
    /** @return list<array<string, mixed>> */
    public function checks(array $status): array
    {
        return is_array($status['checks'] ?? null)
            ? array_values(array_filter($status['checks'], 'is_array'))
            : [];
    }

    public function authorizationReady(array $status): bool
    {
        foreach ($this->checks($status) as $check) {
            if (($check['id'] ?? null) === 'mutation-authorization'
                && ($check['status'] ?? null) === 'pass') {
                return true;
            }
        }

        return false;
    }

    public function phaseBHandoverReady(array $status): bool
    {
        $blockingChecks = array_values(array_filter(
            $this->checks($status),
            fn (array $check): bool => ($check['status'] ?? null) !== 'pass',
        ));

        return ($status['status'] ?? null) === 'fail'
            && count($blockingChecks) === 1
            && ($blockingChecks[0]['id'] ?? null) === 'retired-update-worker'
            && ($blockingChecks[0]['status'] ?? null) === 'fail';
    }

    public function allows(array $status, string $kind): bool
    {
        if (! $this->authorizationReady($status)) {
            return false;
        }

        return ($status['status'] ?? null) === 'pass'
            || ($kind === 'update' && $this->phaseBHandoverReady($status));
    }
}
