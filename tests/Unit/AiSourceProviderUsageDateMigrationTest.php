<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiSourceProviderUsageDateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_upgrades_an_existing_provider_table_without_usage_date(): void
    {
        $this->travelTo('2026-07-26 12:00:00');
        $this->createLegacyProviderTable();

        DB::table('ai_source_providers')->insert([
            'name' => 'Legacy Search Provider',
            'provider_key' => 'doubao_search_custom',
            'endpoint_url' => 'https://search.test',
            'api_key' => 'encrypted-key',
            'status' => 'active',
            'daily_limit' => 100,
            'used_today' => 3,
            'total_used' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_26_000001_add_usage_date_to_ai_source_providers.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('ai_source_providers', 'usage_date'));
        $this->assertSame(
            '2026-07-26',
            DB::table('ai_source_providers')->value('usage_date'),
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ai_source_providers', 'usage_date'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ai_source_providers', 'usage_date'));
    }

    public function test_create_migration_leaves_usage_date_ownership_to_incremental_migration(): void
    {
        $this->dropProviderTables();

        $createMigration = require database_path('migrations/2026_07_10_001913_create_ai_source_providers_table.php');
        $createMigration->up();
        $this->assertFalse(Schema::hasColumn('ai_source_providers', 'usage_date'));

        $usageDateMigration = require database_path('migrations/2026_07_26_000001_add_usage_date_to_ai_source_providers.php');
        $usageDateMigration->up();
        $this->assertTrue(Schema::hasColumn('ai_source_providers', 'usage_date'));

        $usageDateMigration->down();
        $this->assertFalse(Schema::hasColumn('ai_source_providers', 'usage_date'));

        $usageDateMigration->up();
        $this->assertTrue(Schema::hasColumn('ai_source_providers', 'usage_date'));
    }

    public function test_migration_resumes_backfill_when_the_column_already_exists(): void
    {
        $this->travelTo('2026-07-26 12:00:00');
        $this->createLegacyProviderTable();
        Schema::table('ai_source_providers', function (Blueprint $table): void {
            $table->date('usage_date')->nullable();
        });

        DB::table('ai_source_providers')->insert([
            [
                'name' => 'Partially Migrated Provider',
                'provider_key' => 'doubao_search_custom',
                'endpoint_url' => 'https://search.test',
                'api_key' => 'encrypted-key',
                'status' => 'active',
                'daily_limit' => 100,
                'used_today' => 3,
                'usage_date' => null,
                'total_used' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Already Dated Provider',
                'provider_key' => 'doubao_search_custom',
                'endpoint_url' => 'https://search.test',
                'api_key' => 'encrypted-key',
                'status' => 'active',
                'daily_limit' => 100,
                'used_today' => 2,
                'usage_date' => '2026-07-25',
                'total_used' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_07_26_000001_add_usage_date_to_ai_source_providers.php');
        $migration->up();

        $usageDates = DB::table('ai_source_providers')
            ->orderBy('id')
            ->pluck('usage_date')
            ->all();

        $this->assertSame(['2026-07-26', '2026-07-25'], $usageDates);
    }

    private function createLegacyProviderTable(): void
    {
        $this->dropProviderTables();

        Schema::create('ai_source_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('provider_key', 80);
            $table->string('endpoint_url', 500);
            $table->text('api_key');
            $table->string('status', 40)->default('active');
            $table->unsignedInteger('daily_limit')->default(0);
            $table->unsignedInteger('used_today')->default(0);
            $table->unsignedBigInteger('total_used')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    private function dropProviderTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ai_visibility_sources');
        Schema::dropIfExists('ai_visibility_runs');
        Schema::dropIfExists('ai_source_providers');
        Schema::enableForeignKeyConstraints();
    }
}
