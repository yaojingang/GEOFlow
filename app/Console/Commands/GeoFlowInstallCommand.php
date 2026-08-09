<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\SystemState;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Support\Site\SiteSettingsBag;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\FrontendReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class GeoFlowInstallCommand extends Command
{
    public const INSTALLATION_STATE_KEY = 'geoflow.installation';

    protected $signature = 'geoflow:install
        {--force : Run first-install seeders even if an installation marker or existing data is present}
        {--without-demo : Skip the frontend reference pack on a fresh install}';

    protected $description = 'Initialize GEOFlow once, including the default frontend reference pack on a fresh database';

    /**
     * Tables that indicate the database already contains user or business data.
     *
     * Framework tables such as migrations, cache, sessions and jobs are intentionally ignored.
     *
     * @var list<string>
     */
    private array $contentTables = [
        'admins',
        'site_settings',
        'categories',
        'articles',
        'authors',
        'ai_prompts',
        'ai_special_prompts',
        'ai_models',
        'distribution_channels',
        'knowledge_bases',
        'tasks',
    ];

    /**
     * Site settings created by migrations are part of the schema baseline, not user content.
     *
     * @var list<string>
     */
    private array $migrationDefaultSiteSettings = [
        'active_theme' => 'toutiao-news-20260426',
    ];

    public function handle(AnonymousUsageTelemetry $telemetry): int
    {
        if (! Schema::hasTable('system_states')) {
            $this->error('The system_states table is missing. Run php artisan migrate --force before geoflow:install.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $existingState = SystemState::query()->where('key', self::INSTALLATION_STATE_KEY)->first();

        if ($existingState instanceof SystemState && ! $force) {
            $this->reportTelemetry($telemetry, true);
            $this->components->info('GEOFlow has already been initialized; first-install seeders were skipped.');

            return self::SUCCESS;
        }

        $tablesWithData = $this->tablesWithExistingData();
        if ($tablesWithData !== [] && ! $force) {
            $this->markInstalled('backfilled_existing_database', [
                'detected_tables' => $tablesWithData,
            ]);
            $this->reportTelemetry($telemetry, false);

            $this->components->warn('Existing application data was detected. GEOFlow recorded the installation marker and skipped first-install seeders.');

            return self::SUCCESS;
        }

        $this->components->info($force
            ? 'Running GEOFlow first-install seeders with --force.'
            : 'Running GEOFlow first-install seeders for an empty database.');

        $isPristineDatabase = ! $existingState instanceof SystemState && $tablesWithData === [];
        $seedFrontendReference = ! (bool) $this->option('without-demo')
            && ($isPristineDatabase || ($force && (bool) config('geoflow.seed_frontend_demo', false)));

        try {
            DB::transaction(function () use ($force, $isPristineDatabase, $seedFrontendReference): void {
                if ($this->call('db:seed', [
                    '--class' => AdminUserSeeder::class,
                    '--force' => true,
                ]) !== self::SUCCESS) {
                    throw new RuntimeException('Administrator seeding returned a failure status.');
                }

                if ($seedFrontendReference && $this->call('db:seed', [
                    '--class' => FrontendReferenceSeeder::class,
                    '--force' => true,
                ]) !== self::SUCCESS) {
                    throw new RuntimeException('Frontend reference seeding returned a failure status.');
                }

                if ($isPristineDatabase) {
                    $this->seedInitialSiteSettings($seedFrontendReference);
                }

                $this->markInstalled($force ? 'forced_install' : 'fresh_install', [
                    'seed_frontend_reference' => $seedFrontendReference,
                    'reference_content_version' => $seedFrontendReference
                        ? FrontendReferenceSeeder::PACK_VERSION
                        : null,
                    'default_theme' => $isPristineDatabase && $seedFrontendReference
                        ? 'geoflow-template-21-enterprise-signature'
                        : null,
                ]);
            });
        } catch (Throwable $e) {
            $this->error('GEOFlow first-install seeders failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->reportTelemetry($telemetry, $existingState instanceof SystemState);

        $this->components->info('GEOFlow installation marker has been written.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function tablesWithExistingData(): array
    {
        $tables = [];

        foreach ($this->contentTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->tableHasExistingData($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function tableHasExistingData(string $table): bool
    {
        if ($table === 'site_settings') {
            return DB::table($table)
                ->get(['setting_key', 'setting_value'])
                ->contains(function (object $setting): bool {
                    $key = (string) $setting->setting_key;

                    return ! array_key_exists($key, $this->migrationDefaultSiteSettings)
                        || (string) $setting->setting_value !== $this->migrationDefaultSiteSettings[$key];
                });
        }

        return DB::table($table)->limit(1)->exists();
    }

    private function seedInitialSiteSettings(bool $seedFrontendReference): void
    {
        SiteSetting::query()->firstOrCreate(
            ['setting_key' => 'analytics_code'],
            ['setting_value' => (string) config('geoflow.default_analytics_code', '')],
        );

        if ($seedFrontendReference) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => 'active_theme'],
                ['setting_value' => 'geoflow-template-21-enterprise-signature'],
            );
        }

        SiteSettingsBag::forget();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markInstalled(string $mode, array $extra = []): void
    {
        SystemState::query()->updateOrCreate(
            ['key' => self::INSTALLATION_STATE_KEY],
            [
                'value' => [
                    'installed_at' => now()->toIso8601String(),
                    'mode' => $mode,
                    ...$extra,
                ],
            ],
        );
    }

    private function reportTelemetry(AnonymousUsageTelemetry $telemetry, bool $updated): void
    {
        try {
            if ($updated) {
                $telemetry->reportUpdated();

                return;
            }

            $telemetry->reportInstalled();
        } catch (Throwable) {
            // Anonymous telemetry cannot change the outcome of installation.
        }
    }
}
