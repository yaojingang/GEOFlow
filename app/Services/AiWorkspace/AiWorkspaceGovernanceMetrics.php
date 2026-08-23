<?php

namespace App\Services\AiWorkspace;

use App\Models\AiWorkspaceRun;
use Illuminate\Support\Carbon;

final class AiWorkspaceGovernanceMetrics
{
    /** @return array<string,mixed> */
    public function snapshot(int $days = 7): array
    {
        $days = min(90, max(1, $days));
        $from = now()->subDays($days);
        $base = AiWorkspaceRun::query()->where('created_at', '>=', $from);
        $total = (clone $base)->count();
        $completed = (clone $base)->where('state', 'completed')->count();
        $partial = (clone $base)->where('state', 'partially_completed')->count();
        $failed = (clone $base)->where('state', 'failed')->count();
        $unknown = (clone $base)->where('state', 'outcome_unknown')->count();
        $terminal = (clone $base)->whereIn('state', AiWorkspaceRun::TERMINAL_STATES)->count();

        return [
            'window_days' => $days,
            'generated_at' => Carbon::now()->toISOString(),
            'runs' => [
                'total' => $total,
                'terminal' => $terminal,
                'completed' => $completed,
                'partially_completed' => $partial,
                'failed' => $failed,
                'outcome_unknown' => $unknown,
                'success_rate' => $terminal > 0 ? round(($completed / $terminal) * 100, 2) : null,
            ],
            'failure_codes' => (clone $base)
                ->whereNotNull('failure_code')
                ->selectRaw('failure_code, COUNT(*) AS aggregate')
                ->groupBy('failure_code')
                ->orderByDesc('aggregate')
                ->pluck('aggregate', 'failure_code')
                ->map(static fn ($count): int => (int) $count)
                ->all(),
            'states' => (clone $base)
                ->selectRaw('state, COUNT(*) AS aggregate')
                ->groupBy('state')
                ->pluck('aggregate', 'state')
                ->map(static fn ($count): int => (int) $count)
                ->all(),
        ];
    }
}
