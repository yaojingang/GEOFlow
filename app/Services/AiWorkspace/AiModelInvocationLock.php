<?php

namespace App\Services\AiWorkspace;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class AiModelInvocationLock
{
    private const INVOCATION_SLOTS = 32;

    public function acquireForInvocation(int $modelId, ?int $leaseSeconds = null): Lock
    {
        $deadline = microtime(true) + 5;
        $start = random_int(0, self::INVOCATION_SLOTS - 1);

        do {
            for ($offset = 0; $offset < self::INVOCATION_SLOTS; $offset++) {
                $slot = ($start + $offset) % self::INVOCATION_SLOTS;
                $lock = $this->lock($modelId, $leaseSeconds, $slot);
                if ($lock->get()) {
                    return $lock;
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new AiWorkspaceModelUnavailableException('AI 模型配置正在更新，请稍后重试。');
    }

    public function acquireForMutation(int $modelId): ?Lock
    {
        $locks = [];
        for ($slot = 0; $slot < self::INVOCATION_SLOTS; $slot++) {
            $lock = $this->lock($modelId, null, $slot);
            if (! $lock->get()) {
                foreach (array_reverse($locks) as $acquired) {
                    $acquired->release();
                }

                return null;
            }
            $locks[] = $lock;
        }

        return new AiModelMutationLock($locks);
    }

    public function release(?Lock $lock): void
    {
        $lock?->release();
    }

    private function lock(int $modelId, ?int $leaseSeconds, int $slot): Lock
    {
        $store = app()->environment('testing')
            ? (string) config('cache.default')
            : 'redis';
        $seconds = $leaseSeconds === null
            ? max(
                120,
                (int) config('ai-workspace.model_total_timeout_seconds', 90) + 60,
                (int) config('ai-workspace.conversation_generation_lease_seconds', 180) + 30,
            )
            : max(120, $leaseSeconds);

        return Cache::store($store)->lock('geoflow:ai-model-invocation:'.$modelId.':'.$slot, $seconds);
    }
}
