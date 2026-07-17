<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SecurityUpgradeMigrationTest extends TestCase
{
    private string $databasePath;

    private string $originalDefaultConnection;

    private string|false $originalDrainConfirmation;

    private string|false $originalFreshInstallConfirmation;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'geoflow-security-upgrade-');
        $this->assertIsString($databasePath);
        $this->databasePath = $databasePath;
        $this->originalDefaultConnection = (string) config('database.default');
        $this->originalDrainConfirmation = getenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');
        $this->originalFreshInstallConfirmation = getenv('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED');

        $connection = config('database.connections.sqlite');
        $this->assertIsArray($connection);
        config()->set('database.connections.security_upgrade', $connection);
        config()->set('database.connections.security_upgrade.database', $databasePath);
        config()->set('database.default', 'security_upgrade');

        Schema::create('api_idempotency_keys', function ($table): void {
            $table->id();
            $table->string('idempotency_key', 120);
            $table->string('route_key', 255);
            $table->char('request_hash', 64);
            $table->text('response_body');
            $table->unsignedSmallInteger('response_status');
            $table->timestamps();
            $table->unique(['idempotency_key', 'route_key']);
        });
        Schema::create('images', function ($table): void {
            $table->id();
            $table->string('file_path', 500);
        });

        $this->setDrainConfirmation(null);
        $this->setFreshInstallConfirmation(null);
    }

    protected function tearDown(): void
    {
        $this->setDrainConfirmation($this->originalDrainConfirmation === false
            ? null
            : $this->originalDrainConfirmation);
        $this->setFreshInstallConfirmation($this->originalFreshInstallConfirmation === false
            ? null
            : $this->originalFreshInstallConfirmation);
        config()->set('database.default', $this->originalDefaultConnection);
        DB::purge('security_upgrade');

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_old_first_flight_forces_the_security_migration_to_wait_for_drain_confirmation(): void
    {
        DB::table('images')->insert([
            'file_path' => 'storage/uploads/images/2026/07/existing.png',
        ]);

        // An old request has passed its empty replay check and remains in flight.
        $this->assertFalse(DB::table('api_idempotency_keys')
            ->where('idempotency_key', 'old-first-flight')
            ->where('route_key', 'POST /tasks')
            ->exists());

        $migration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');

        try {
            $migration->up();
            $this->fail('An existing deployment must remain on the old schema until every old first-flight is drained.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true', $exception->getMessage());
            $this->assertStringContainsString('php artisan down', $exception->getMessage());
            $this->assertStringContainsString('old processes', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));

        // The rejected migration leaves the old schema intact while the old request finishes.
        DB::table('api_idempotency_keys')->insert([
            'idempotency_key' => 'old-first-flight',
            'route_key' => 'POST /tasks',
            'request_hash' => hash('sha256', 'body-only-v1'),
            'response_body' => '{"success":true}',
            'response_status' => 201,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('api_idempotency_keys', [
            'idempotency_key' => 'old-first-flight',
            'route_key' => 'POST /tasks',
        ]);
    }

    public function test_preflight_blocks_the_complete_security_migration_sequence_before_schema_changes(): void
    {
        DB::table('images')->insert([
            'file_path' => 'storage/uploads/images/2026/07/existing.png',
        ]);
        $preflight = require database_path('migrations/2026_07_17_000400_security_upgrade_preflight.php');

        try {
            $preflight->up();
            $this->fail('The security migration sequence must stop before its first schema change.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('blocked before schema changes', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'state'));
        $this->assertFalse(Schema::hasTable('managed_image_paths'));
        $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));
    }

    public function test_confirmed_existing_upgrade_supports_up_down_and_up_again(): void
    {
        $imageId = DB::table('images')->insertGetId([
            'file_path' => 'storage/uploads/images/2026/07/existing.png',
        ]);
        $this->setDrainConfirmation('true');

        $migration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');
        $migration->up();

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) DB::table('images')->where('id', $imageId)->value('managed_path_hash'),
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));
    }

    public function test_fresh_install_confirmation_cannot_bypass_prior_migration_history(): void
    {
        Schema::create('migrations', function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert([
            [
                'migration' => '2026_07_15_000003_add_override_username_snapshot_to_article_risk_scans_table',
                'batch' => 1,
            ],
            [
                'migration' => '2026_07_17_000401_add_durable_state_to_api_idempotency_keys_table',
                'batch' => 2,
            ],
            [
                'migration' => '2026_07_17_000402_create_managed_image_paths_table',
                'batch' => 2,
            ],
        ]);
        $this->setFreshInstallConfirmation('true');

        $migration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');

        try {
            $migration->up();
            $this->fail('Fresh-install intent must not bypass prior migration history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));
    }

    public function test_pristine_install_without_explicit_install_confirmation_fails_closed(): void
    {
        $migration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');

        try {
            $migration->up();
            $this->fail('A pristine install must state its intent explicitly before the security migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));
    }

    public function test_pristine_install_uses_a_scoped_fresh_install_confirmation_without_drain_confirmation(): void
    {
        $this->setFreshInstallConfirmation('true');
        $migration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));
    }

    private function setDrainConfirmation(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'], $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED']);
            putenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');

            return;
        }

        $_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = $value;
        $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = $value;
        putenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED='.$value);
    }

    private function setFreshInstallConfirmation(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED'], $_SERVER['GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED']);
            putenv('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED');

            return;
        }

        $_ENV['GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED'] = $value;
        $_SERVER['GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED'] = $value;
        putenv('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED='.$value);
    }
}
