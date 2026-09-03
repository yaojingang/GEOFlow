<?php

namespace App\Services\AiWorkspace;

use Illuminate\Contracts\Cache\Lock;

final class AiModelMutationLock implements Lock
{
    /** @param list<Lock> $locks */
    public function __construct(private readonly array $locks) {}

    public function get($callback = null): mixed
    {
        return $callback === null ? true : $callback();
    }

    public function block($seconds, $callback = null): mixed
    {
        return $this->get($callback);
    }

    public function release(): bool
    {
        $released = true;
        foreach (array_reverse($this->locks) as $lock) {
            $released = $lock->release() && $released;
        }

        return $released;
    }

    public function owner(): string
    {
        return hash('sha256', implode(':', array_map(
            static fn (Lock $lock): string => $lock->owner(),
            $this->locks,
        )));
    }

    public function forceRelease(): void
    {
        foreach (array_reverse($this->locks) as $lock) {
            $lock->forceRelease();
        }
    }
}
