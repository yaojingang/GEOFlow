<?php

namespace App\Console\Commands;

use App\Models\SystemState;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\FrontendDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GeoFlowInstallCommand extends Command
{
    public const INSTALLATION_STATE_KEY = 'geoflow.installation';

    protected $signature = 'geoflow:install
        {--force : Run first-install seeders even if an installation marker or existing data is present}';

    protected $description = 'Run GEOFlow first-install seeders only for an empty database';

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
    private array $migrationDefaultSiteSettingKeys = [
        'active_theme',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('system_states')) {
            $this->error('The system_states table is missing. Run php artisan migrate --force before geoflow:install.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $existingState = SystemState::query()->where('key', self::INSTALLATION_STATE_KEY)->first();

        if ($existingState instanceof SystemState && ! $force) {
            $this->components->info('GEOFlow has already been initialized; first-install seeders were skipped.');

            return self::SUCCESS;
        }

        $tablesWithData = $this->tablesWithExistingData();
        if ($tablesWithData !== [] && ! $force) {
            $this->markInstalled('backfilled_existing_database', [
                'detected_tables' => $tablesWithData,
            ]);

            $this->components->warn('Existing application data was detected. GEOFlow recorded the installation marker and skipped first-install seeders.');

            return self::SUCCESS;
        }

        $this->components->info($force
            ? 'Running GEOFlow first-install seeders with --force.'
            : 'Running GEOFlow first-install seeders for an empty database.');

        try {
            $this->call('db:seed', [
                '--class' => AdminUserSeeder::class,
                '--force' => true,
            ]);

            if ((bool) config('geoflow.seed_frontend_demo', false)) {
                $this->call('db:seed', [
                    '--class' => FrontendDemoSeeder::class,
                    '--force' => true,
                ]);
            }

            $this->markInstalled($force ? 'forced_install' : 'fresh_install', [
                'seed_frontend_demo' => (bool) config('geoflow.seed_frontend_demo', false),
            ]);
        } catch (Throwable $e) {
            $this->error('GEOFlow first-install seeders failed: '.$e->getMessage());

            return self::FAILURE;
        }

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
                ->whereNotIn('setting_key', $this->migrationDefaultSiteSettingKeys)
                ->limit(1)
                ->exists();
        }

        return DB::table($table)->limit(1)->exists();
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
}
