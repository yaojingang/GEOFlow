<?php

namespace App\Services\Admin;

class SystemUpdateDeploymentDetector
{
    /**
     * @return array{mode: string, label: string, supported: bool, reason: string, writable: bool, git_available: bool, current_commit: string}
     */
    public function detect(): array
    {
        $basePath = base_path();
        $writable = is_writable($basePath) && is_writable(storage_path('app'));
        $gitAvailable = is_dir($basePath.'/.git');
        $currentCommit = $gitAvailable ? $this->gitHead($basePath) : '';
        $insideDocker = file_exists('/.dockerenv') || getenv('container') !== false;

        if (! $writable) {
            return [
                'mode' => 'readonly',
                'label' => __('admin.system_updates.deployment.readonly'),
                'supported' => false,
                'reason' => __('admin.system_updates.deployment.readonly_reason'),
                'writable' => false,
                'git_available' => $gitAvailable,
                'current_commit' => $currentCommit,
            ];
        }

        if ($gitAvailable) {
            return [
                'mode' => $insideDocker ? 'docker_bind_mount' : 'git_worktree',
                'label' => $insideDocker ? __('admin.system_updates.deployment.docker_bind_mount') : __('admin.system_updates.deployment.git_worktree'),
                'supported' => true,
                'reason' => __('admin.system_updates.deployment.source_supported_reason'),
                'writable' => true,
                'git_available' => true,
                'current_commit' => $currentCommit,
            ];
        }

        if ($insideDocker) {
            return [
                'mode' => 'docker_image',
                'label' => __('admin.system_updates.deployment.docker_image'),
                'supported' => false,
                'reason' => __('admin.system_updates.deployment.docker_image_reason'),
                'writable' => true,
                'git_available' => false,
                'current_commit' => '',
            ];
        }

        return [
            'mode' => 'archive_source',
            'label' => __('admin.system_updates.deployment.archive_source'),
            'supported' => (bool) config('geoflow.update_archive_apply_enabled', false),
            'reason' => (bool) config('geoflow.update_archive_apply_enabled', false)
                ? __('admin.system_updates.deployment.archive_supported_reason')
                : __('admin.system_updates.deployment.archive_disabled_reason'),
            'writable' => true,
            'git_available' => false,
            'current_commit' => '',
        ];
    }

    private function gitHead(string $basePath): string
    {
        $command = 'git -C '.escapeshellarg($basePath).' rev-parse HEAD 2>/dev/null';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        return $exitCode === 0 ? trim((string) ($output[0] ?? '')) : '';
    }
}
