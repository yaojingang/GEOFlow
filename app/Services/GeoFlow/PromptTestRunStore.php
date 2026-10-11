<?php

namespace App\Services\GeoFlow;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

final class PromptTestRunStore
{
    public const TTL = 7200;

    /** @return array<string,mixed>|null */
    public function find(int $adminId, int $promptId, string $runId): ?array
    {
        $run = Cache::get($this->key($adminId, $promptId, $runId));

        return is_array($run) ? $run : null;
    }

    /** @param array<string,mixed> $run */
    public function save(array $run): void
    {
        $remaining = (int) $run['expires_at'] - now()->timestamp;
        abort_if($remaining <= 0, 410, __('prompt_test.expired'));
        Cache::put($this->key($run['admin_id'], $run['prompt_id'], $run['id']), $run, $remaining);
    }

    public function acquire(int $adminId, int $seconds): ?Lock
    {
        $lock = Cache::lock('prompt-test:active:'.$adminId, $seconds);

        return $lock->get() ? $lock : null;
    }

    /** @param array<string,mixed> $run */
    public function cancel(array $run): void
    {
        Cache::put($this->key($run['admin_id'], $run['prompt_id'], $run['id']).':cancel', true, max(1, $run['expires_at'] - now()->timestamp));
    }

    /** @param array<string,mixed> $run */
    public function cancelled(array $run): bool
    {
        return Cache::has($this->key($run['admin_id'], $run['prompt_id'], $run['id']).':cancel');
    }

    /** @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public function present(array $run): array
    {
        $result = Arr::only($run, [
            'id', 'status', 'created_at', 'expires_at', 'title', 'keyword', 'knowledge_bases',
            'generation_model', 'diagnostic_model', 'prompt_content', 'prompt_hash',
            'content', 'html', 'diagnosis', 'error_code', 'diagnosis_request_id', 'phase', 'deadline',
        ]);
        $active = in_array($run['status'], ['retrieving', 'generating', 'diagnosing'], true);
        $timedOut = now()->timestamp > ($run['deadline'] ?? 0);
        $result['processing'] = $active && ! $timedOut;
        if ($active && $timedOut) {
            $result['status'] = $run['status'] === 'diagnosing' ? 'diagnosis_failed' : 'generation_failed';
            $result['error_code'] = 'interrupted';
        }
        $result['cancel_requested'] = $this->cancelled($run);
        if ($result['cancel_requested'] && ($active || $run['status'] === 'article_ready')) {
            $result['status'] = 'cancelled';
            $result['error_code'] = null;
        }
        $result['message'] = isset($result['error_code']) && $result['error_code'] !== null
            ? __('prompt_test.errors.'.$result['error_code']) : null;

        return $result;
    }

    private function key(int $adminId, int $promptId, string $runId): string
    {
        return 'prompt-test:'.$adminId.':'.$promptId.':'.$runId;
    }
}
