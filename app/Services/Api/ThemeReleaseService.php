<?php

namespace App\Services\Api;

use App\Models\Admin;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ThemeReleaseService
{
    public function __construct(private readonly LinkPoolThemeBindingRelease $linkPool) {}

    /** Remote theme publication stays closed until its full release contract is implemented. */
    public static function availability(): array
    {
        return [
            'available' => false,
            'reason' => 'release_rollback_upgrade_integration_pending',
            'missing' => ['publish_plan', 'atomic_binding_and_receipt', 'field_rollback', 'readback', 'upgrade_interlock', 'backup_restore_validation'],
        ];
    }

    /** The link pool uses a separate, narrowly scoped release path. */
    public static function linkPoolAdaptationAvailability(): array
    {
        $missing = [];
        if (! config('geoflow.link_pool_theme_shared_storage')) {
            $missing[] = 'shared_theme_storage_and_upgrade_lock';
        }
        if (! app()->environment('testing') && ! self::trustedReadbackBase()) {
            $missing[] = 'http_readback_endpoint';
        }
        if (! Schema::hasTable('link_pool_theme_adaptations')) {
            $missing[] = 'theme_adaptation_migration';
        }
        $localRoot = rtrim(Storage::disk('local')->path(''), '/');
        if (! is_dir($localRoot) || ! is_writable($localRoot)
            || ! is_dir(storage_path('framework')) || ! is_writable(storage_path('framework'))) {
            $missing[] = 'theme_storage_write_access';
        }

        return [
            'available' => $missing === [],
            'reason' => $missing === [] ? null : 'link_pool_theme_deployment_not_ready',
            'ready' => [
                'hosted_revision_binding',
                'site_scoped_revision_assets',
                'atomic_binding_settings_and_receipt',
                'field_rollback',
                'validated_theme_patch_plan',
                'site_scoped_render_validation',
                'http_kernel_readback',
                'upgrade_interlock',
                'backup_restore_validation',
            ],
            'missing' => $missing,
        ];
    }

    public static function trustedReadbackBase(): ?string
    {
        $url = (string) config('geoflow.link_pool_theme_readback_base_url');
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            return null;
        }

        return $url;
    }

    /** @param array<string, mixed> $validatedProof */
    public function publishLinkPoolAdaptation(ThemeRevision $candidate, Admin $admin, array $validatedProof): ThemeRelease
    {
        return $this->linkPool->publish($candidate, $admin, $validatedProof);
    }

    public function rollbackLinkPoolAdaptation(ThemeRelease $release, Admin $admin): ThemeRelease
    {
        return $this->linkPool->rollback($release, $admin);
    }
}
