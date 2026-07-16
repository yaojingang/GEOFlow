<?php

use App\Services\GeoFlow\ManagedImagePathHasherV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertExistingDeploymentWasDrained();

        if (! Schema::hasColumn('images', 'managed_path_hash')) {
            Schema::table('images', function (Blueprint $table) {
                $table->char('managed_path_hash', 64)->nullable()->index();
            });
        }

        $hasher = new ManagedImagePathHasherV1;
        DB::table('images')
            ->whereNull('managed_path_hash')
            ->select(['id', 'file_path'])
            ->orderBy('id')
            ->chunkById(200, function ($images) use ($hasher): void {
                foreach ($images as $image) {
                    $filePath = (string) $image->file_path;
                    try {
                        $pathHash = $hasher->hashManagedPathV1($filePath);
                    } catch (InvalidArgumentException) {
                        $pathHash = $hasher->terminalHashV1($filePath);
                    }

                    DB::table('images')
                        ->where('id', $image->id)
                        ->whereNull('managed_path_hash')
                        ->update(['managed_path_hash' => $pathHash]);
                }
            }, 'id');

        if (DB::table('images')->whereNull('managed_path_hash')->exists()) {
            throw new RuntimeException('Managed image path hash backfill did not complete.');
        }

        Schema::table('images', function (Blueprint $table) {
            // The preflight gate guarantees that every writer now supplies this identity.
            $table->char('managed_path_hash', 64)->nullable(false)->change();
        });
    }

    private function assertExistingDeploymentWasDrained(): void
    {
        if ($this->drainWasConfirmed()
            || ($this->freshInstallWasConfirmed() && $this->isPristineInstallation())) {
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

    private function isPristineInstallation(): bool
    {
        if ($this->hasMultipleMigrationBatches()) {
            return false;
        }

        $businessTables = [
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

        foreach ($businessTables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function hasMultipleMigrationBatches(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        $batches = DB::table('migrations')
            ->distinct()
            ->limit(2)
            ->pluck('batch');

        return $batches->count() > 1;
    }

    private function drainWasConfirmed(): bool
    {
        return $this->environmentFlagIsTrue('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');
    }

    private function freshInstallWasConfirmed(): bool
    {
        return $this->environmentFlagIsTrue('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED');
    }

    private function environmentFlagIsTrue(string $key): bool
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(['managed_path_hash']);
            $table->dropColumn('managed_path_hash');
        });
    }
};
