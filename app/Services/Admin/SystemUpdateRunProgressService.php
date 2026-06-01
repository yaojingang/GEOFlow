<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateRun;

class SystemUpdateRunProgressService
{
    public function record(SystemUpdateRun $run, string $key, int $percent, string $status = 'running'): void
    {
        $payload = is_array($run->plan_json) ? $run->plan_json : [];
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];

        $progress[] = [
            'key' => $key,
            'percent' => max(0, min(100, $percent)),
            'status' => $status,
            'at' => now()->toDateTimeString(),
        ];

        $payload['progress'] = $progress;
        $payload['progress_percent'] = max(0, min(100, $percent));
        $payload['progress_status'] = $status;

        $run->forceFill(['plan_json' => $payload])->save();
        $run->setAttribute('plan_json', $payload);
    }
}
