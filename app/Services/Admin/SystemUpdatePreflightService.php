<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\Schema;

class SystemUpdatePreflightService
{
    public function __construct(
        private readonly SystemUpdateArchiveValidator $archiveValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $deployment
     * @return array{status: string, pass: int, warn: int, fail: int, info: int, items: array<int, array{key: string, status: string, title: string, message: string}>}
     */
    public function build(array $state, array $deployment, ?SystemUpdateRun $latestPlan): array
    {
        $items = [
            $this->deploymentItem($deployment),
            $this->writableItem(),
            $this->diskSpaceItem(),
            $this->gitWorktreeItem($deployment),
            $this->repositoryItem($state),
            $this->planRiskItem($latestPlan),
            $this->manualStepsItem($latestPlan),
            $this->backupItem($latestPlan),
            $this->executionSwitchItem(),
        ];

        $counts = [
            'pass' => $this->countStatus($items, 'pass'),
            'warn' => $this->countStatus($items, 'warn'),
            'fail' => $this->countStatus($items, 'fail'),
            'info' => $this->countStatus($items, 'info'),
        ];

        return [
            'status' => $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass'),
            ...$counts,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function deploymentItem(array $deployment): array
    {
        $supported = (bool) ($deployment['supported'] ?? false);

        return $this->item(
            'deployment',
            $supported ? 'pass' : 'warn',
            __('admin.system_updates.preflight.deployment'),
            $supported
                ? __('admin.system_updates.preflight.deployment_pass')
                : __('admin.system_updates.preflight.deployment_warn')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function writableItem(): array
    {
        $writable = is_writable(base_path()) && is_writable(storage_path('app'));

        return $this->item(
            'writable',
            $writable ? 'pass' : 'fail',
            __('admin.system_updates.preflight.writable'),
            $writable
                ? __('admin.system_updates.preflight.writable_pass')
                : __('admin.system_updates.preflight.writable_fail')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function diskSpaceItem(): array
    {
        $path = storage_path('app');
        $freeBytes = @disk_free_space($path);
        $minBytes = max(1, (int) config('geoflow.update_min_free_disk_bytes', 200 * 1024 * 1024));

        if (! is_int($freeBytes) && ! is_float($freeBytes)) {
            return $this->item(
                'disk_space',
                'warn',
                __('admin.system_updates.preflight.disk_space'),
                __('admin.system_updates.preflight.disk_space_unknown')
            );
        }

        $freeBytes = (int) $freeBytes;

        return $this->item(
            'disk_space',
            $freeBytes >= $minBytes ? 'pass' : 'fail',
            __('admin.system_updates.preflight.disk_space'),
            $freeBytes >= $minBytes
                ? __('admin.system_updates.preflight.disk_space_pass', ['free' => $this->formatBytes($freeBytes)])
                : __('admin.system_updates.preflight.disk_space_fail', [
                    'free' => $this->formatBytes($freeBytes),
                    'min' => $this->formatBytes($minBytes),
                ])
        );
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function gitWorktreeItem(array $deployment): array
    {
        if (empty($deployment['git_available'])) {
            return $this->item(
                'git_worktree',
                'info',
                __('admin.system_updates.preflight.git_worktree'),
                __('admin.system_updates.preflight.git_worktree_skip')
            );
        }

        if (! (bool) config('geoflow.update_preflight_check_git_dirty', true)) {
            return $this->item(
                'git_worktree',
                'info',
                __('admin.system_updates.preflight.git_worktree'),
                __('admin.system_updates.preflight.git_worktree_disabled')
            );
        }

        $basePath = base_path();
        $command = 'git -C '.escapeshellarg($basePath).' status --porcelain --untracked-files=no 2>/dev/null';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return $this->item(
                'git_worktree',
                'warn',
                __('admin.system_updates.preflight.git_worktree'),
                __('admin.system_updates.preflight.git_worktree_unknown')
            );
        }

        return $this->item(
            'git_worktree',
            $output === [] ? 'pass' : 'warn',
            __('admin.system_updates.preflight.git_worktree'),
            $output === []
                ? __('admin.system_updates.preflight.git_worktree_pass')
                : __('admin.system_updates.preflight.git_worktree_warn', ['count' => count($output)])
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function repositoryItem(array $state): array
    {
        $allowedRepository = trim((string) config('geoflow.update_allowed_repository', ''));
        $archiveUrl = trim((string) ($state['archive_url'] ?? ''));

        if ($allowedRepository === '') {
            return $this->item(
                'repository',
                'fail',
                __('admin.system_updates.preflight.repository'),
                __('admin.system_updates.preflight.repository_fail')
            );
        }

        if ($archiveUrl !== '') {
            try {
                $this->archiveValidator->assertAllowedArchiveUrl($archiveUrl);
            } catch (\Throwable) {
                return $this->item(
                    'repository',
                    'fail',
                    __('admin.system_updates.preflight.repository'),
                    __('admin.system_updates.preflight.repository_archive_fail')
                );
            }
        }

        return $this->item(
            'repository',
            'pass',
            __('admin.system_updates.preflight.repository'),
            $archiveUrl !== ''
                ? __('admin.system_updates.preflight.repository_pass_with_archive')
                : __('admin.system_updates.preflight.repository_pass')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function planRiskItem(?SystemUpdateRun $latestPlan): array
    {
        if (! $latestPlan) {
            return $this->item(
                'plan_risk',
                'info',
                __('admin.system_updates.preflight.plan_risk'),
                __('admin.system_updates.preflight.plan_risk_empty')
            );
        }

        $risk = (string) ($latestPlan->risk_level ?? 'low');

        return $this->item(
            'plan_risk',
            $risk === 'low' ? 'pass' : 'warn',
            __('admin.system_updates.preflight.plan_risk'),
            __('admin.system_updates.preflight.plan_risk_'.$risk)
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function manualStepsItem(?SystemUpdateRun $latestPlan): array
    {
        if (! $latestPlan || ! is_array($latestPlan->plan_json)) {
            return $this->item(
                'manual_steps',
                'info',
                __('admin.system_updates.preflight.manual_steps'),
                __('admin.system_updates.preflight.manual_steps_empty')
            );
        }

        $flags = is_array($latestPlan->plan_json['flags'] ?? null) ? $latestPlan->plan_json['flags'] : [];
        $required = array_keys(array_filter($flags, static fn ($value): bool => (bool) $value));

        return $this->item(
            'manual_steps',
            $required === [] ? 'pass' : 'warn',
            __('admin.system_updates.preflight.manual_steps'),
            $required === []
                ? __('admin.system_updates.preflight.manual_steps_pass')
                : __('admin.system_updates.preflight.manual_steps_warn', ['count' => count($required)])
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function backupItem(?SystemUpdateRun $latestPlan): array
    {
        if (! $latestPlan) {
            return $this->item(
                'backup',
                'info',
                __('admin.system_updates.preflight.backup'),
                __('admin.system_updates.preflight.backup_empty')
            );
        }

        $hasBackup = Schema::hasTable('system_update_backups')
            && SystemUpdateBackup::query()->where('run_id', $latestPlan->id)->exists();

        return $this->item(
            'backup',
            $hasBackup ? 'pass' : 'warn',
            __('admin.system_updates.preflight.backup'),
            $hasBackup
                ? __('admin.system_updates.preflight.backup_pass')
                : __('admin.system_updates.preflight.backup_warn')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function executionSwitchItem(): array
    {
        $enabled = (bool) config('geoflow.update_execution_enabled', false);

        return $this->item(
            'execution_switch',
            $enabled ? 'warn' : 'pass',
            __('admin.system_updates.preflight.execution_switch'),
            $enabled
                ? __('admin.system_updates.preflight.execution_switch_warn')
                : __('admin.system_updates.preflight.execution_switch_pass')
        );
    }

    /**
     * @param  array<int, array{status: string}>  $items
     */
    private function countStatus(array $items, string $status): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === $status));
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function item(string $key, string $status, string $title, string $message): array
    {
        return compact('key', 'status', 'title', 'message');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
