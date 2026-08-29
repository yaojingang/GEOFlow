<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

class TaskTitleReadinessException extends ApiException implements ShouldntReport
{
    /** @param array<string,mixed> $report */
    public function __construct(array $report, int $httpStatus = 422)
    {
        parent::__construct(
            'task_title_library_not_ready',
            $this->messageFor($report),
            $httpStatus,
            ['title_readiness' => $report],
        );
    }

    /** @param array<string,mixed> $report */
    private function messageFor(array $report): string
    {
        $code = (string) ($report['issues'][0]['code'] ?? 'title_library_not_ready');

        return match ($code) {
            'title_library_empty' => '当前标题库中还没有标题',
            'title_library_exhausted' => '当前标题库的可用标题已耗尽',
            'title_library_shortage' => '当前标题库的可用标题不足以完成任务计划',
            default => '当前标题库配置尚未就绪',
        };
    }
}
