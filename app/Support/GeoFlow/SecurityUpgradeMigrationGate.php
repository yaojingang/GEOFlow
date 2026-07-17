<?php

namespace App\Support\GeoFlow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SecurityUpgradeMigrationGate
{
    public static function assertReady(): void
    {
        if (self::environmentFlagIsTrue('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED')
            || (self::environmentFlagIsTrue('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED') && self::isPristineInstallation())) {
            return;
        }

        throw new RuntimeException(
            'Security upgrade blocked before schema changes. Run php artisan down, stop and drain all old processes '
            .'(web, queue workers, scheduler, Reverb) and every in-flight request, then run this migration with '
            .'GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true. Rolling, migration-first, and one-command upgrades '
            .'are unsupported for an existing deployment. A new empty installation may instead use '
            .'GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true for this migration run. Remove either one-time '
            .'confirmation immediately after migration.',
        );
    }

    private static function isPristineInstallation(): bool
    {
        if (self::hasMultipleMigrationBatches()) {
            return false;
        }

        foreach (self::businessTables() as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function businessTables(): array
    {
        return [
            'admins',
            'users',
            'personal_access_tokens',
            'api_idempotency_keys',
            'ai_models',
            'keyword_libraries',
            'keywords',
            'title_libraries',
            'titles',
            'image_libraries',
            'images',
            'knowledge_bases',
            'knowledge_chunks',
            'authors',
            'tasks',
            'categories',
            'articles',
            'article_images',
            'sensitive_words',
            'task_runs',
            'system_states',
            'system_update_runs',
            'system_update_backups',
            'distribution_channels',
            'distribution_channel_secrets',
            'article_distributions',
            'distribution_logs',
            'task_distribution_channels',
            'enterprise_knowledge_projects',
            'enterprise_knowledge_sources',
            'enterprise_knowledge_revisions',
            'lead_forms',
            'lead_submissions',
            'site_theme_replications',
            'site_theme_replication_versions',
            'site_theme_replication_logs',
            'url_import_jobs',
            'url_import_job_logs',
            'article_risk_scans',
            'view_logs',
        ];
    }

    private static function hasMultipleMigrationBatches(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->distinct()
            ->limit(2)
            ->pluck('batch')
            ->count() > 1;
    }

    private static function environmentFlagIsTrue(string $key): bool
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
