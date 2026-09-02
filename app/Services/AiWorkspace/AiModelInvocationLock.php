<?php

namespace App\Services\AiWorkspace;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class AiModelInvocationLock
{
    public function acquireForInvocation(int $modelId, ?int $leaseSeconds = null): Lock
    {
        $lock = $this->lock($modelId, $leaseSeconds);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new AiWorkspaceModelUnavailableException('AI 模型配置正在更新，请稍后重试。');
        }

        return $lock;
    }

    public function acquireForMutation(int $modelId): ?Lock
    {
        $lock = $this->lock($modelId);

        return $lock->get() ? $lock : null;
    }

    public function release(?Lock $lock): void
    {
        $lock?->release();
    }

    private function lock(int $modelId, ?int $leaseSeconds = null): Lock
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

        return Cache::store($store)->lock('geoflow:ai-model-invocation:'.$modelId, $seconds);
    }
}
