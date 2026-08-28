<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Exceptions\TaskTitleReadinessException;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;

class TaskTitleReadinessService
{
    /** @param array<string,mixed> $report */
    public function assertCanActivate(array $report, int $httpStatus = 422): void
    {
        if (($report['can_activate'] ?? false) !== true) {
            throw new TaskTitleReadinessException($report, $httpStatus);
        }
    }

    /** @return array<string,mixed> */
    public function inspectTask(Task $task, string $status = 'active'): array
    {
        return $this->inspect(
            (int) ($task->title_library_id ?? 0),
            max(1, (int) ($task->article_limit ?? 1)),
            (bool) ($task->is_loop ?? false),
            $status,
            (int) $task->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(
        int $titleLibraryId,
        int $articleLimit,
        bool $isLoop,
        string $status,
        ?int $taskId = null,
    ): array {
        $articleLimit = max(1, $articleLimit);
        $createdCount = 0;
        if ($taskId !== null) {
            $task = Task::query()->find($taskId, ['id', 'created_count']);
            if (! $task) {
                throw new ApiException('task_not_found', '任务不存在', 404);
            }
            $createdCount = max(0, (int) ($task->created_count ?? 0));
        }

        $library = $titleLibraryId > 0
            ? TitleLibrary::query()->find($titleLibraryId, ['id', 'name'])
            : null;
        if (! $library) {
            return $this->missingLibraryReport($articleLimit, $isLoop, $status, $taskId, $createdCount);
        }

        $counts = Title::query()
            ->where('library_id', $titleLibraryId)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN used_count IS NULL OR used_count <= 0 THEN 1 ELSE 0 END) AS available_count')
            ->first();
        $total = (int) ($counts?->total_count ?? 0);
        $available = (int) ($counts?->available_count ?? 0);
        $used = max(0, $total - $available);
        $remaining = max(0, $articleLimit - $createdCount);
        $shortage = $isLoop ? 0 : max(0, $remaining - $available);

        $issues = [];
        if ($total === 0) {
            $issues[] = $this->issue('title_library_empty', 'blocking');
        } elseif (! $isLoop && $remaining > $available) {
            $issues[] = $this->issue(
                $available === 0 ? 'title_library_exhausted' : 'title_library_shortage',
                'blocking',
            );
        }

        $conflictQuery = Task::query()
            ->where('title_library_id', $titleLibraryId)
            ->where('status', 'active')
            ->where('schedule_enabled', 1)
            ->when($taskId !== null, fn ($query) => $query->whereKeyNot($taskId))
            ->whereColumn('created_count', '<', 'article_limit');
        $conflictCount = (clone $conflictQuery)->count();
        $conflicts = $conflictQuery
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'article_limit', 'created_count', 'is_loop'])
            ->map(static fn (Task $task): array => [
                'id' => (int) $task->id,
                'name' => (string) $task->name,
                'remaining' => max(0, (int) $task->article_limit - (int) $task->created_count),
                'is_loop' => (bool) $task->is_loop,
            ])
            ->values()
            ->all();

        if ($conflicts !== []) {
            $issues[] = $this->issue('title_library_shared', 'warning');
        }
        if ($isLoop && $total > 0 && ($used > 0 || $remaining > $available)) {
            $issues[] = $this->issue('loop_reuses_titles', 'warning');
        }

        $hasBlocker = collect($issues)->contains(
            static fn (array $issue): bool => $issue['severity'] === 'blocking'
        );
        $requiresAcknowledgement = $issues !== [];
        $canActivate = ! $hasBlocker;
        $canSave = $status === 'paused' || $canActivate;

        return [
            'status' => $hasBlocker ? 'blocked' : ($requiresAcknowledgement ? 'warning' : 'ready'),
            'can_save' => $canSave,
            'can_activate' => $canActivate,
            'requires_acknowledgement' => $requiresAcknowledgement,
            'library' => [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'total' => $total,
                'used' => $used,
                'available' => $available,
            ],
            'task' => [
                'id' => $taskId,
                'status' => $status,
                'article_limit' => max(1, $articleLimit),
                'created_count' => $createdCount,
                'remaining' => $remaining,
                'is_loop' => $isLoop,
            ],
            'shortage' => $shortage,
            'suggested_article_limit' => max($createdCount, $createdCount + $available),
            'conflict_count' => $conflictCount,
            'conflicts' => $conflicts,
            'issues' => $issues,
        ];
    }

    /** @return array<string,mixed> */
    private function missingLibraryReport(
        int $articleLimit,
        bool $isLoop,
        string $status,
        ?int $taskId,
        int $createdCount,
    ): array {
        $remaining = max(0, $articleLimit - $createdCount);

        return [
            'status' => 'blocked',
            'can_save' => $status === 'paused',
            'can_activate' => false,
            'requires_acknowledgement' => true,
            'library' => ['id' => null, 'name' => '', 'total' => 0, 'used' => 0, 'available' => 0],
            'task' => [
                'id' => $taskId,
                'status' => $status,
                'article_limit' => $articleLimit,
                'created_count' => $createdCount,
                'remaining' => $remaining,
                'is_loop' => $isLoop,
            ],
            'shortage' => $remaining,
            'suggested_article_limit' => $createdCount,
            'conflict_count' => 0,
            'conflicts' => [],
            'issues' => [$this->issue('title_library_missing', 'blocking')],
        ];
    }

    /** @return array{code:string,severity:string} */
    private function issue(string $code, string $severity): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
        ];
    }
}
