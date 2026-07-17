<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\ApiIdempotencyKey;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\TitleLibrary;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\ManagedImageFileService;
use App\Services\GeoFlow\MaterialLibraryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImageLibrarySecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $adminSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.managed_image_deletion_enabled', true);
    }

    public function test_durable_image_coordination_schema_is_installed(): void
    {
        $this->assertTrue(Schema::hasColumns('api_idempotency_keys', [
            'fingerprint_version',
            'state',
            'owner_token',
            'lease_expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('managed_image_paths', [
            'path_hash',
            'file_path',
            'content_sha256',
            'state',
            'lock_version',
        ]));
        $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));
    }

    public function test_hash_migration_depends_only_on_the_versioned_hasher_contract(): void
    {
        $migrationSource = file_get_contents(database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php'));
        $gateSource = file_get_contents(app_path('Support/GeoFlow/SecurityUpgradeMigrationGate.php'));
        $this->assertIsString($migrationSource);
        $this->assertIsString($gateSource);
        $source = $migrationSource.$gateSource;
        $this->assertStringContainsString('ManagedImagePathHasherV1', $source);
        $this->assertStringContainsString('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED', $source);
        $this->assertStringContainsString('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED', $source);
        $this->assertStringContainsString('$_SERVER', $source);
        $this->assertStringContainsString('$_ENV', $source);
        $this->assertStringContainsString('getenv(', $source);
        $this->assertStringNotContainsString('ManagedImageFileService', $source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('config(', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!get)env\(/', $source);
    }

    public function test_idempotency_reservation_is_visible_on_a_separate_connection_before_mutation(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'geoflow-idempotency-');
        $this->assertIsString($databasePath);
        $originalDefault = (string) config('database.default');
        $baseConnection = config('database.connections.sqlite');
        $this->assertIsArray($baseConnection);
        config()->set('database.connections.durable_primary', $baseConnection + ['database' => $databasePath]);
        config()->set('database.connections.durable_observer', $baseConnection + ['database' => $databasePath]);
        config()->set('database.connections.durable_primary.database', $databasePath);
        config()->set('database.connections.durable_observer.database', $databasePath);
        config()->set('database.default', 'durable_primary');

        try {
            Schema::connection('durable_primary')->create('api_idempotency_keys', function ($table): void {
                $table->id();
                $table->string('idempotency_key', 120);
                $table->string('route_key', 255);
                $table->char('request_hash', 64);
                $table->text('response_body');
                $table->unsignedSmallInteger('response_status');
                $table->unsignedTinyInteger('fingerprint_version')->default(1);
                $table->string('state', 20)->default('completed');
                $table->char('owner_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamps();
                $table->unique(['idempotency_key', 'route_key']);
            });

            $request = Request::create('/api/v1/materials/image-libraries/1/items', 'POST', ['name' => 'probe']);
            $request->headers->set('X-Idempotency-Key', 'separate-connection-probe');
            $observed = false;

            try {
                IdempotencyService::executeJson($request, 'POST /materials/{type}/{id}/items', function () use (&$observed): never {
                    $observed = DB::connection('durable_observer')
                        ->table('api_idempotency_keys')
                        ->where('idempotency_key', 'separate-connection-probe')
                        ->value('state') === 'in_progress';

                    throw new RuntimeException('forced mutation failure');
                });
                $this->fail('The forced mutation failure was not thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced mutation failure', $exception->getMessage());
            }

            $this->assertTrue($observed);
            $this->assertFalse(DB::connection('durable_observer')
                ->table('api_idempotency_keys')
                ->where('idempotency_key', 'separate-connection-probe')
                ->exists());
        } finally {
            config()->set('database.default', $originalDefault);
            DB::purge('durable_observer');
            DB::purge('durable_primary');
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function test_unbound_legacy_multipart_fingerprint_requires_a_new_key(): void
    {
        $request = Request::create(
            '/api/v1/materials/image-libraries/1/items',
            'POST',
            [],
            [],
            ['image' => $this->gifUpload()],
        );
        $request->headers->set('X-Idempotency-Key', 'legacy-multipart-replay');
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'legacy-multipart-replay',
            'route_key' => 'POST /materials/{type}/{id}/items',
            'request_hash' => hash('sha256', 'unverifiable-legacy-upload'),
            'response_body' => '{"success":true,"data":{"legacy":true}}',
            'response_status' => 201,
            'fingerprint_version' => 1,
            'state' => 'completed',
        ]);

        try {
            IdempotencyService::maybeReplayJson($request, 'POST /materials/{type}/{id}/items');
            $this->fail('An unbound legacy row must require a new key.');
        } catch (ApiException $exception) {
            $this->assertSame('idempotency_upgrade_required', $exception->getErrorCode());
            $this->assertTrue($exception->getDetails()['use_new_key'] ?? false);
        }
    }

    public function test_unbound_legacy_fingerprint_cannot_replay_across_targets_or_tokens(): void
    {
        $body = ['name' => 'same-body'];
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'legacy-unbound-attack',
            'route_key' => 'POST /materials/{type}/{id}/items',
            'request_hash' => IdempotencyService::requestHash($body),
            'response_body' => '{"success":true,"data":{"secret":"cached"}}',
            'response_status' => 201,
            'fingerprint_version' => 1,
            'state' => 'completed',
        ]);

        foreach ([[1, 10], [2, 20]] as [$libraryId, $tokenId]) {
            $request = Request::create(
                "/api/v1/materials/image-libraries/{$libraryId}/items",
                'POST',
                $body,
            );
            $request->headers->set('X-Idempotency-Key', 'legacy-unbound-attack');
            $request->attributes->set('api_auth', new ApiAuthContext(['id' => $tokenId], $tokenId));

            try {
                IdempotencyService::maybeReplayJson($request, 'POST /materials/{type}/{id}/items');
                $this->fail('An unbound legacy row must not replay.');
            } catch (ApiException $exception) {
                $this->assertSame('idempotency_upgrade_required', $exception->getErrorCode());
                $this->assertTrue($exception->getDetails()['use_new_key'] ?? false);
            }
        }
    }

    public function test_post_mutation_v1_collision_never_instructs_a_new_key_retry(): void
    {
        ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'legacy-post-mutation-collision',
            'route_key' => 'POST /legacy-mutation',
            'request_hash' => hash('sha256', 'old-worker-body-only'),
            'response_body' => '{"success":true}',
            'response_status' => 201,
            'fingerprint_version' => 1,
            'state' => 'completed',
        ]);

        try {
            IdempotencyService::store(
                'legacy-post-mutation-collision',
                'POST /legacy-mutation',
                hash('sha256', 'new-worker-bound-fingerprint'),
                ['success' => true],
                201,
            );
            $this->fail('A post-mutation collision must report an uncertain result.');
        } catch (ApiException $exception) {
            $this->assertSame('idempotency_result_uncertain', $exception->getErrorCode());
            $this->assertFalse($exception->getDetails()['retryable'] ?? true);
            $this->assertFalse($exception->getDetails()['use_new_key'] ?? true);
        }
    }

    public function test_registry_mutex_preserves_a_legacy_file_after_the_cache_lease_is_lost(): void
    {
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/durable-registry-race.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $databasePath = tempnam(sys_get_temp_dir(), 'geoflow-path-registry-');
        $this->assertIsString($databasePath);
        $originalDefault = (string) config('database.default');
        $baseConnection = config('database.connections.sqlite');
        $this->assertIsArray($baseConnection);
        $baseConnection['busy_timeout'] = 100;
        config()->set('database.connections.path_primary', $baseConnection);
        config()->set('database.connections.path_observer', $baseConnection);
        config()->set('database.connections.path_primary.database', $databasePath);
        config()->set('database.connections.path_observer.database', $databasePath);
        config()->set('database.default', 'path_primary');

        try {
            Schema::connection('path_primary')->create('managed_image_paths', function ($table): void {
                $table->id();
                $table->char('path_hash', 64)->unique();
                $table->text('file_path');
                $table->char('content_sha256', 64)->nullable();
                $table->string('state', 20)->default('unknown');
                $table->unsignedBigInteger('lock_version')->default(0);
                $table->timestamps();
            });
            Schema::connection('path_primary')->create('images', function ($table): void {
                $table->id();
                $table->text('file_path');
                $table->char('managed_path_hash', 64)->nullable()->index();
            });

            $creator = new ManagedImageFileService;
            $creator->withExistingPathLock($path, function () use ($path, $diskPath): void {
                Cache::lock($this->managedPathLockName($path), 1)->forceRelease();
                config()->set('database.default', 'path_observer');
                $cleanupFailed = (new ManagedImageFileService)->cleanupUnreferenced([$path]);
                config()->set('database.default', 'path_primary');

                $this->assertSame(1, $cleanupFailed);
                Storage::disk('public')->assertExists($diskPath);
                DB::connection('path_primary')->table('images')->insert(['file_path' => $path]);
            });

            config()->set('database.default', 'path_observer');
            $this->assertSame(0, (new ManagedImageFileService)->cleanupUnreferenced([$path]));
            Storage::disk('public')->assertExists($diskPath);
            $this->assertGreaterThanOrEqual(
                2,
                (int) DB::connection('path_observer')->table('managed_image_paths')->value('lock_version'),
            );
        } finally {
            config()->set('database.default', $originalDefault);
            DB::purge('path_observer');
            DB::purge('path_primary');
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function test_registry_path_hash_mismatch_fails_closed_without_deleting_the_file(): void
    {
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/registry-mismatch.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        DB::table('managed_image_paths')->insert([
            'path_hash' => hash('sha256', $path),
            'file_path' => 'storage/uploads/images/2026/07/different-path.png',
            'state' => 'present',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, app(ManagedImageFileService::class)->cleanupUnreferenced([$path]));
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_case_insensitive_aliases_share_the_mutex_registry_and_reference_identity(): void
    {
        Storage::fake('public');
        $mixedPath = 'storage/uploads/images/2026/07/Case-Alias.png';
        $lowerPath = strtolower($mixedPath);
        $mixedDiskPath = substr($mixedPath, strlen('storage/'));
        $lowerDiskPath = substr($lowerPath, strlen('storage/'));
        Storage::disk('public')->put($mixedDiskPath, 'image');
        $mixedAbsolutePath = Storage::disk('public')->path($mixedDiskPath);
        $lowerAbsolutePath = Storage::disk('public')->path($lowerDiskPath);
        if (! is_file($lowerAbsolutePath)) {
            $this->assertTrue(link($mixedAbsolutePath, $lowerAbsolutePath));
        }

        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return true;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return true;
            }
        };
        $identityHash = hash('sha256', $lowerPath);
        $mutex = Cache::lock('geoflow:managed-image-path:'.$identityHash, 10);
        $this->assertTrue($mutex->get());
        try {
            $this->assertSame(1, $service->cleanupUnreferenced([$mixedPath]));
            $this->assertFileExists($mixedAbsolutePath);
        } finally {
            $mutex->release();
        }

        $library = $this->createImageLibrary();
        $image = $this->createImage($library, $lowerPath);
        $this->assertSame(0, $service->cleanupUnreferenced([$mixedPath]));
        $this->assertFileExists($mixedAbsolutePath);
        $this->assertSame($identityHash, $image->fresh()->managed_path_hash);
        $service->withExistingPathLock($mixedPath, static fn (): null => null);
        $service->withExistingPathLock($lowerPath, static fn (): null => null);

        $this->assertDatabaseCount('managed_image_paths', 1);
        $this->assertDatabaseHas('managed_image_paths', [
            'path_hash' => $identityHash,
            'state' => 'present',
        ]);
    }

    public function test_rollout_gate_keeps_files_until_old_workers_are_drained(): void
    {
        Storage::fake('public');
        config()->set('geoflow.managed_image_deletion_enabled', false);
        $path = 'storage/uploads/images/2026/07/rollout-gated.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');

        $this->assertSame(1, app(ManagedImageFileService::class)->cleanupUnreferenced([$path]));
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_readiness_command_reports_completed_backfill_while_gate_is_closed(): void
    {
        config()->set('geoflow.managed_image_deletion_enabled', false);

        $this->artisan('geoflow:managed-images:readiness')
            ->expectsOutputToContain('Backfill and registry reconciliation are complete')
            ->assertSuccessful();
    }

    public function test_readiness_reconciles_historical_images_before_the_security_audit(): void
    {
        Storage::fake('public');
        config()->set('geoflow.managed_image_deletion_enabled', false);
        config()->set('geoflow.legacy_image_path_input', false);
        config()->set('geoflow.outbound_private_targets', []);
        $path = 'storage/uploads/images/2026/07/historical.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'historical-image');
        $image = $this->createImage($this->createImageLibrary(), $path);

        $this->assertDatabaseMissing('managed_image_paths', [
            'path_hash' => $image->managed_path_hash,
        ]);

        $this->artisan('geoflow:managed-images:readiness')
            ->expectsOutputToContain('Backfill and registry reconciliation are complete')
            ->assertSuccessful();

        $this->assertDatabaseHas('managed_image_paths', [
            'path_hash' => $image->managed_path_hash,
            'file_path' => $path,
            'content_sha256' => hash('sha256', 'historical-image'),
            'state' => 'present',
        ]);
        $this->assertSame(0, Artisan::call('geoflow:security-audit', ['--json' => true]));
    }

    public function test_readiness_fails_closed_for_a_terminal_historical_path(): void
    {
        config()->set('geoflow.managed_image_deletion_enabled', false);
        $library = $this->createImageLibrary();
        $path = 'storage/uploads/images/2026/07/../terminal.png';
        DB::table('images')->insert([
            'library_id' => $library->id,
            'filename' => 'terminal.png',
            'original_name' => 'terminal.png',
            'file_name' => 'terminal.png',
            'file_path' => $path,
            'managed_path_hash' => app(ManagedImageFileService::class)->terminalHashV1($path),
            'file_size' => 5,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->artisan('geoflow:managed-images:readiness')
            ->expectsOutputToContain('Managed image readiness is incomplete')
            ->assertFailed();
    }

    public function test_case_sensitive_paths_keep_distinct_registry_identities(): void
    {
        Storage::fake('public');
        $mixedPath = 'storage/uploads/images/2026/07/Case-Distinct.png';
        $lowerPath = strtolower($mixedPath);
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return false;
            }
        };

        $this->assertSame(0, $service->cleanupUnreferenced([$mixedPath, $lowerPath]));
        $this->assertDatabaseHas('managed_image_paths', ['path_hash' => hash('sha256', $mixedPath)]);
        $this->assertDatabaseHas('managed_image_paths', ['path_hash' => hash('sha256', $lowerPath)]);
        $this->assertDatabaseCount('managed_image_paths', 2);
    }

    public function test_case_insensitive_alias_can_finalize_a_registry_created_with_other_casing(): void
    {
        Storage::fake('public');
        $mixedPath = 'storage/uploads/images/2026/07/Case-Finalize.png';
        $lowerPath = strtolower($mixedPath);
        $mixedAbsolutePath = Storage::disk('public')->path(substr($mixedPath, strlen('storage/')));
        $lowerAbsolutePath = Storage::disk('public')->path(substr($lowerPath, strlen('storage/')));
        Storage::disk('public')->put(substr($mixedPath, strlen('storage/')), 'image');
        $caseInsensitiveOnHost = is_file($lowerAbsolutePath);
        if (! $caseInsensitiveOnHost) {
            $this->assertTrue(link($mixedAbsolutePath, $lowerAbsolutePath));
        }
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return true;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return true;
            }
        };

        $service->withExistingPathLock($mixedPath, static fn (): null => null);
        $this->assertSame(0, $service->cleanupUnreferenced([$lowerPath]));
        $this->assertFileDoesNotExist($lowerAbsolutePath);
        if ($caseInsensitiveOnHost) {
            $this->assertFileDoesNotExist($mixedAbsolutePath);
        }
        $this->assertDatabaseHas('managed_image_paths', [
            'path_hash' => hash('sha256', $lowerPath),
            'state' => 'missing',
        ]);
    }

    public function test_case_insensitive_unicode_normalization_aliases_share_reference_identity(): void
    {
        Storage::fake('public');
        $composedPath = 'storage/uploads/images/2026/07/Café.png';
        $decomposedPath = "storage/uploads/images/2026/07/cafe\u{0301}.png";
        $composedAbsolutePath = Storage::disk('public')->path(substr($composedPath, strlen('storage/')));
        $decomposedAbsolutePath = Storage::disk('public')->path(substr($decomposedPath, strlen('storage/')));
        Storage::disk('public')->put(substr($composedPath, strlen('storage/')), 'image');
        if (! is_file($decomposedAbsolutePath)) {
            $this->assertTrue(link($composedAbsolutePath, $decomposedAbsolutePath));
        }
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return true;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return true;
            }
        };
        $library = $this->createImageLibrary();
        $this->createImage(
            $library,
            $decomposedPath,
            managedPathHash: $service->pathHash($decomposedPath),
        );

        $this->assertSame(0, $service->cleanupUnreferenced([$composedPath]));
        $this->assertFileExists($composedAbsolutePath);
        $this->assertDatabaseCount('managed_image_paths', 1);
    }

    public function test_case_sensitive_unicode_variants_keep_distinct_identities(): void
    {
        Storage::fake('public');
        $composedPath = 'storage/uploads/images/2026/07/Café.png';
        $decomposedPath = "storage/uploads/images/2026/07/cafe\u{0301}.png";
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return false;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return false;
            }
        };

        $this->assertSame(0, $service->cleanupUnreferenced([$composedPath, $decomposedPath]));
        $this->assertDatabaseCount('managed_image_paths', 2);
    }

    public function test_case_sensitive_normalization_insensitive_paths_share_only_unicode_aliases(): void
    {
        Storage::fake('public');
        $composedPath = 'storage/uploads/images/2026/07/Café.png';
        $decomposedPath = "storage/uploads/images/2026/07/Cafe\u{0301}.png";
        $lowercasePath = 'storage/uploads/images/2026/07/café.png';
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return false;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return true;
            }
        };

        $this->assertSame($service->pathHash($composedPath), $service->pathHash($decomposedPath));
        $this->assertNotSame($service->pathHash($composedPath), $service->pathHash($lowercasePath));
    }

    public function test_case_insensitive_normalization_sensitive_paths_fold_case_only(): void
    {
        Storage::fake('public');
        $composedPath = 'storage/uploads/images/2026/07/Café.png';
        $decomposedPath = "storage/uploads/images/2026/07/Cafe\u{0301}.png";
        $lowercasePath = 'storage/uploads/images/2026/07/café.png';
        $service = new class extends ManagedImageFileService
        {
            protected function filesystemIsCaseInsensitive(string $root): bool
            {
                return true;
            }

            protected function filesystemIsNormalizationInsensitive(string $root): bool
            {
                return false;
            }
        };

        $this->assertSame($service->pathHash($composedPath), $service->pathHash($lowercasePath));
        $this->assertNotSame($service->pathHash($composedPath), $service->pathHash($decomposedPath));
    }

    public function test_real_filesystem_probe_preserves_or_separates_case_variants(): void
    {
        Storage::fake('public');
        $mixedPath = 'storage/uploads/images/2026/07/Case-Probe.png';
        $lowerPath = strtolower($mixedPath);
        $mixedDiskPath = substr($mixedPath, strlen('storage/'));
        $lowerDiskPath = substr($lowerPath, strlen('storage/'));
        Storage::disk('public')->put($mixedDiskPath, 'mixed');
        $caseInsensitive = is_file(Storage::disk('public')->path($lowerDiskPath));
        if (! $caseInsensitive) {
            Storage::disk('public')->put($lowerDiskPath, 'lower');
        }
        $library = $this->createImageLibrary();
        $this->createImage($library, $lowerPath);

        $this->assertSame(0, app(ManagedImageFileService::class)->cleanupUnreferenced([$mixedPath]));
        if ($caseInsensitive) {
            Storage::disk('public')->assertExists($mixedDiskPath);
            $this->assertDatabaseHas('managed_image_paths', [
                'path_hash' => hash('sha256', $lowerPath),
                'state' => 'present',
            ]);
        } else {
            Storage::disk('public')->assertMissing($mixedDiskPath);
            Storage::disk('public')->assertExists($lowerDiskPath);
            $this->assertDatabaseHas('managed_image_paths', [
                'path_hash' => hash('sha256', $mixedPath),
                'state' => 'missing',
            ]);
        }
    }

    public function test_real_filesystem_probe_preserves_or_separates_unicode_normalization_variants(): void
    {
        Storage::fake('public');
        $composedPath = 'storage/uploads/images/2026/07/Probe-Café.png';
        $decomposedPath = "storage/uploads/images/2026/07/Probe-Cafe\u{0301}.png";
        Storage::disk('public')->put(substr($composedPath, strlen('storage/')), 'image');
        $decomposedAbsolutePath = Storage::disk('public')->path(substr($decomposedPath, strlen('storage/')));
        $normalizationInsensitive = is_file($decomposedAbsolutePath);
        $service = app(ManagedImageFileService::class);

        if ($normalizationInsensitive) {
            $this->assertSame($service->pathHash($composedPath), $service->pathHash($decomposedPath));
        } else {
            $this->assertNotSame($service->pathHash($composedPath), $service->pathHash($decomposedPath));
        }
    }

    public function test_creator_fails_closed_after_cleanup_commits_a_deleting_fence(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/two-phase-delete.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $creatorRejected = false;
        $service = new class(function () use ($library, $path, &$creatorRejected): void {
            try {
                app(MaterialLibraryService::class)->createItem('image-libraries', (int) $library->id, [
                    'file_path' => $path,
                ]);
            } catch (RuntimeException) {
                $creatorRejected = true;
            }
        }) extends ManagedImageFileService
        {

            public function __construct(private readonly \Closure $hook) {}

            protected function afterDeletionPrepared(string $path, int $fenceToken): void
            {
                ($this->hook)();
            }
        };

        $this->assertSame(0, $service->cleanupUnreferenced([$path]));
        $this->assertTrue($creatorRejected);
        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        Storage::disk('public')->assertMissing($diskPath);
        $this->assertDatabaseHas('managed_image_paths', [
            'path_hash' => hash('sha256', $path),
            'state' => 'missing',
        ]);
    }

    public function test_crash_after_deleting_fence_keeps_the_file_and_auditable_state(): void
    {
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/two-phase-crash.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $service = new class extends ManagedImageFileService
        {
            protected function afterDeletionPrepared(string $path, int $fenceToken): void
            {
                throw new RuntimeException('simulated crash after deleting fence');
            }
        };

        $this->assertSame(1, $service->cleanupUnreferenced([$path]));
        Storage::disk('public')->assertExists($diskPath);
        $this->assertDatabaseHas('managed_image_paths', [
            'path_hash' => hash('sha256', $path),
            'state' => 'deleting',
        ]);
    }

    public function test_durable_coordination_migrations_upgrade_a_drained_legacy_idempotency_table(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'geoflow-coordination-upgrade-');
        $this->assertIsString($databasePath);
        $originalDefault = (string) config('database.default');
        $connection = config('database.connections.sqlite');
        $this->assertIsArray($connection);
        config()->set('database.connections.coordination_upgrade', $connection);
        config()->set('database.connections.coordination_upgrade.database', $databasePath);
        config()->set('database.default', 'coordination_upgrade');
        $originalDrainConfirmation = getenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');
        $_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = 'true';
        $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = 'true';
        putenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true');

        try {
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
            $historicalImageId = DB::table('images')->insertGetId([
                'file_path' => 'storage/uploads/images/2026/07/../migration-invalid.png',
            ]);
            $legacyPayload = ['legacy' => 'same'];
            $legacyHash = IdempotencyService::requestHash($legacyPayload);
            DB::table('api_idempotency_keys')->insert([
                'idempotency_key' => 'legacy-completed',
                'route_key' => 'POST /legacy',
                'request_hash' => $legacyHash,
                'response_body' => '{"ok":true}',
                'response_status' => 201,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $idempotencyMigration = require database_path('migrations/2026_07_17_000401_add_durable_state_to_api_idempotency_keys_table.php');
            $registryMigration = require database_path('migrations/2026_07_17_000402_create_managed_image_paths_table.php');
            $imageIdentityMigration = require database_path('migrations/2026_07_17_000403_add_managed_path_hash_to_images_table.php');
            $fingerprintMigration = require database_path('migrations/2026_07_17_000404_add_fingerprint_version_to_api_idempotency_keys_table.php');
            $idempotencyMigration->up();
            $registryMigration->up();
            $imageIdentityMigration->up();
            $fingerprintMigration->up();

            $this->assertSame('completed', DB::table('api_idempotency_keys')->value('state'));
            $this->assertSame(1, (int) DB::table('api_idempotency_keys')->value('fingerprint_version'));
            $this->assertNull(DB::table('api_idempotency_keys')->value('owner_token'));
            $this->assertTrue(Schema::hasTable('managed_image_paths'));
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                (string) DB::table('images')->where('id', $historicalImageId)->value('managed_path_hash'),
            );
            try {
                DB::table('images')->insert([
                    'file_path' => 'storage/uploads/images/2026/07/old-worker-omission.png',
                ]);
                $this->fail('The database must reject a stale binary restarted after the drained migration.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $v2Hash = IdempotencyService::requestHash([
                'request' => [
                    'method' => 'POST',
                    'target' => '/legacy',
                    'token_id' => 1,
                ],
                'payload' => $legacyPayload,
            ]);
            try {
                IdempotencyService::loadReplay(
                    'legacy-completed',
                    'POST /legacy',
                    $v2Hash,
                );
                $this->fail('An unbound historical row should require a new key.');
            } catch (ApiException $exception) {
                $this->assertSame('idempotency_upgrade_required', $exception->getErrorCode());
                $this->assertTrue($exception->getDetails()['use_new_key'] ?? false);
            }

            $fingerprintMigration->down();
            $imageIdentityMigration->down();
            $registryMigration->down();
            $idempotencyMigration->down();

            $this->assertFalse(Schema::hasTable('managed_image_paths'));
            $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'state'));
            $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'owner_token'));
            $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'lease_expires_at'));
            $this->assertFalse(Schema::hasColumn('api_idempotency_keys', 'fingerprint_version'));
            $this->assertFalse(Schema::hasColumn('images', 'managed_path_hash'));

            $idempotencyMigration->up();
            $registryMigration->up();
            $imageIdentityMigration->up();
            $fingerprintMigration->up();

            $this->assertTrue(Schema::hasColumns('api_idempotency_keys', [
                'fingerprint_version',
                'state',
                'owner_token',
                'lease_expires_at',
            ]));
            $this->assertTrue(Schema::hasTable('managed_image_paths'));
            $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));

            $newCodeHash = IdempotencyService::requestHash([
                'request' => ['method' => 'POST', 'target' => '/new-v2', 'token_id' => 1],
                'payload' => ['new' => 'record'],
            ]);
            IdempotencyService::store('new-code-v2', 'POST /new-v2', $newCodeHash, ['ok' => true], 201);
            $this->assertSame(2, (int) DB::table('api_idempotency_keys')
                ->where('idempotency_key', 'new-code-v2')
                ->value('fingerprint_version'));
            try {
                $fingerprintMigration->down();
                $imageIdentityMigration->down();
                $registryMigration->down();
                $idempotencyMigration->down();
                $this->fail('Rollback must retain fingerprint metadata while v2 rows exist.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('v2 rows exist', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('api_idempotency_keys', 'fingerprint_version'));
            $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));
            $this->assertTrue(Schema::hasTable('managed_image_paths'));
        } finally {
            if ($originalDrainConfirmation === false) {
                unset($_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'], $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED']);
                putenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');
            } else {
                $_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = $originalDrainConfirmation;
                $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] = $originalDrainConfirmation;
                putenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED='.$originalDrainConfirmation);
            }
            config()->set('database.default', $originalDefault);
            DB::purge('coordination_upgrade');
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function test_admin_image_deletion_cannot_delete_a_file_outside_managed_roots(): void
    {
        $sentinelPath = tempnam(sys_get_temp_dir(), 'geoflow-issue-60-');
        $this->assertIsString($sentinelPath);
        file_put_contents($sentinelPath, 'harmless sentinel');

        try {
            Log::spy();
            $admin = $this->createAdmin();
            $library = $this->createImageLibrary();
            $image = Image::query()->create([
                'library_id' => $library->id,
                'filename' => basename($sentinelPath),
                'original_name' => basename($sentinelPath),
                'file_name' => basename($sentinelPath),
                'file_path' => $this->relativePath(base_path(), $sentinelPath),
                'managed_path_hash' => hash('sha256', 'invalid-managed-image-reference:'.$sentinelPath),
                'file_size' => filesize($sentinelPath),
                'mime_type' => 'image/png',
                'width' => 1,
                'height' => 1,
                'tags' => '',
                'used_count' => 0,
                'usage_count' => 0,
            ]);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.image-libraries.images.delete', ['libraryId' => $library->id]), [
                    'image_ids' => [$image->id],
                ])
                ->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => $library->id]));

            $this->assertFileExists($sentinelPath);
            $this->assertModelMissing($image);
            Log::shouldHaveReceived('warning')->with(
                'geoflow.managed_image_cleanup_skipped',
                Mockery::on(static function (array $context) use ($sentinelPath): bool {
                    return isset($context['path_fingerprint'], $context['path_length'], $context['reason'])
                        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), basename($sentinelPath));
                })
            )->once();
        } finally {
            if (is_file($sentinelPath)) {
                unlink($sentinelPath);
            }
        }
    }

    public function test_json_image_path_input_is_disabled_by_default(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/images/2026/07/existing.png', 'image');
        $library = $this->createImageLibrary();

        $this->withToken($this->materialsToken())
            ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => 'storage/uploads/images/2026/07/existing.png',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
    }

    public function test_enabled_json_image_path_input_requires_and_canonicalizes_an_existing_managed_file(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        Storage::disk('public')->put('uploads/images/2026/07/existing.png', 'image');
        $library = $this->createImageLibrary();

        $this->withToken($this->materialsToken())
            ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => 'storage/uploads/images/2026/07/existing.png',
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.file_path', 'storage/uploads/images/2026/07/existing.png');

        $this->withToken($this->materialsToken())
            ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => 'storage/uploads/images/2026/07/missing.png',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_enabled_json_image_path_input_accepts_an_existing_legacy_file(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        $legacyPath = 'uploads/images/'.uniqid('api-legacy-', true).'.png';
        $absolutePath = public_path($legacyPath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, 'image');
        $library = $this->createImageLibrary();

        try {
            $this->withToken($this->materialsToken())
                ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                    'file_path' => $legacyPath,
                ])
                ->assertCreated()
                ->assertJsonPath('data.item.file_path', $legacyPath);
        } finally {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
    }

    public function test_enabled_json_image_path_input_does_not_create_while_the_path_cleanup_lock_is_held(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/locked-create.png';
        Storage::disk('public')->put(substr($path, strlen('storage/')), 'image');
        $library = $this->createImageLibrary();
        $lock = Cache::lock($this->managedPathLockName($path), 10);
        $this->assertTrue($lock->acquire());

        try {
            $this->withToken($this->materialsToken())
                ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                    'file_path' => $path,
                ])
                ->assertStatus(500);

            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            Storage::disk('public')->assertExists(substr($path, strlen('storage/')));
        } finally {
            $lock->release();
        }
    }

    public function test_idempotent_legacy_path_create_holds_the_cleanup_lock_through_response_finalization(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/idempotent-legacy-lock.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $pathLockName = $this->managedPathLockName($path);
        $cleanupCouldAcquirePathLock = null;

        DB::listen(static function (QueryExecuted $query) use ($pathLockName, &$cleanupCouldAcquirePathLock): void {
            if ($cleanupCouldAcquirePathLock !== null
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'update')
                || ! str_contains(strtolower($query->sql), 'api_idempotency_keys')) {
                return;
            }

            $cleanupLock = Cache::lock($pathLockName, 10);
            $cleanupCouldAcquirePathLock = $cleanupLock->get();
            if ($cleanupCouldAcquirePathLock) {
                $cleanupLock->release();
            }
        });

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->materialsToken(),
                'Accept' => 'application/json',
                'X-Idempotency-Key' => 'legacy-path-lock-finalization',
            ])->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => $path,
            ])->assertCreated();

            $this->assertFalse($cleanupCouldAcquirePathLock);
            $this->assertDatabaseHas('images', [
                'library_id' => $library->id,
                'file_path' => $path,
            ]);
            $this->assertDatabaseHas('managed_image_paths', [
                'path_hash' => hash('sha256', $path),
                'file_path' => $path,
                'state' => 'present',
            ]);
            $this->assertGreaterThanOrEqual(
                1,
                (int) DB::table('managed_image_paths')->where('path_hash', hash('sha256', $path))->value('lock_version'),
            );
            Storage::disk('public')->assertExists($diskPath);
        } finally {
            Event::forget(QueryExecuted::class);
        }
    }

    public function test_idempotent_legacy_path_create_releases_the_path_lock_after_rollback(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/idempotent-legacy-rollback.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
                && str_contains(strtolower($query->sql), 'api_idempotency_keys')) {
                throw new RuntimeException('forced legacy finalization rollback');
            }
        });

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->materialsToken(),
                'Accept' => 'application/json',
                'X-Idempotency-Key' => 'legacy-path-lock-rollback',
            ])->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => $path,
            ])->assertStatus(500);

            $this->assertDatabaseMissing('images', [
                'library_id' => $library->id,
                'file_path' => $path,
            ]);
            Storage::disk('public')->assertExists($diskPath);
        } finally {
            Event::forget(QueryExecuted::class);
        }

        $nextCleanupLock = Cache::lock($this->managedPathLockName($path), 10);
        $this->assertTrue($nextCleanupLock->get());
        $nextCleanupLock->release();
    }

    public function test_idempotent_legacy_path_replay_does_not_revalidate_the_completed_file(): void
    {
        config()->set('geoflow.legacy_image_path_input', true);
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/idempotent-legacy-replay.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $headers = [
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => 'legacy-path-completed-replay',
        ];

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => $path,
            ]);
        $first->assertCreated();
        Storage::disk('public')->delete($diskPath);

        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'file_path' => $path,
            ])
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
    }

    public function test_api_can_upload_an_image_with_server_derived_metadata(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $upload = UploadedFile::fake()->image('client-name.png', 123, 45);

        $response = $this->withToken($this->materialsToken())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $upload,
                'filename' => 'attacker-controlled.php',
                'file_size' => 1,
                'mime_type' => 'text/plain',
                'width' => 999,
                'height' => 999,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.item.original_name', 'client-name.png')
            ->assertJsonPath('data.item.mime_type', 'image/png')
            ->assertJsonPath('data.item.width', 123)
            ->assertJsonPath('data.item.height', 45);

        $storedImage = Image::query()->where('library_id', $library->id)->firstOrFail();
        $this->assertNotSame('attacker-controlled.php', $storedImage->filename);
        $this->assertGreaterThan(1, $storedImage->file_size);
        $this->assertStringStartsWith('storage/uploads/images/', $storedImage->file_path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $storedImage->managed_path_hash);
        Storage::disk('public')->assertExists(substr($storedImage->file_path, strlen('storage/')));
    }

    public function test_same_image_content_uses_one_content_addressed_file_across_distinct_requests(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $token = $this->materialsToken();

        foreach (['content-address-a', 'content-address-b'] as $idempotencyKey) {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])->assertCreated();
        }

        $paths = Image::query()
            ->where('library_id', $library->id)
            ->pluck('file_path')
            ->all();

        $this->assertCount(2, $paths);
        $this->assertCount(1, array_unique($paths));
        $this->assertMatchesRegularExpression(
            '#^storage/uploads/images/sha256/[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}\.gif$#',
            $paths[0],
        );
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_content_addressed_upload_does_not_overwrite_a_mismatched_existing_target(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $upload = $this->gifUpload();
        $realPath = $upload->getRealPath();
        $this->assertIsString($realPath);
        $contentHash = hash_file('sha256', $realPath);
        $this->assertIsString($contentHash);
        $diskPath = 'uploads/images/sha256/'.substr($contentHash, 0, 2).'/'.substr($contentHash, 2, 2).'/'.$contentHash.'.gif';
        Storage::disk('public')->put($diskPath, 'tampered content');

        $this->withToken($this->materialsToken())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $upload,
            ])
            ->assertStatus(500);

        $this->assertSame('tampered content', Storage::disk('public')->get($diskPath));
        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_api_rejects_forged_non_image_upload_content(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();

        $response = $this->withToken($this->materialsToken())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => UploadedFile::fake()->createWithContent('forged.png', 'plain text payload'),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertIsString($response->json('error.details.field_errors.image'));

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_api_rejects_an_image_larger_than_the_configured_maximum(): void
    {
        config()->set('geoflow.max_upload_bytes', 1024);
        Storage::fake('public');
        $library = $this->createImageLibrary();

        $this->withToken($this->materialsToken())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => UploadedFile::fake()->image('oversized.png', 20, 10)->size(2),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_api_returns_validation_error_for_an_invalid_php_upload(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $invalidUpload = new UploadedFile(
            sys_get_temp_dir().'/missing-geoflow-upload-'.bin2hex(random_bytes(8)),
            'too-large.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->withToken($this->materialsToken())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $invalidUpload,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['field_errors' => ['image']]]]);

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_generic_keyword_and_title_item_posts_still_use_the_existing_contract(): void
    {
        $token = $this->materialsToken();
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'Request compatibility keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Request compatibility titles',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", [
                'keyword' => 'request compatibility',
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.keyword', 'request compatibility');

        $this->withToken($token)
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => 'Request compatibility title',
                'keyword' => 'request compatibility',
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.title', 'Request compatibility title');
    }

    public function test_multipart_idempotency_replays_the_same_file_without_creating_a_duplicate(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $token = $this->materialsToken();
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'X-Idempotency-Key' => 'same-image-upload',
        ];

        $first = $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ]);
        $second = $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ]);

        $first->assertCreated();
        $second->assertCreated()->assertExactJson($first->json());
        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_conflicts_when_file_content_changes(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $token = $this->materialsToken();
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'X-Idempotency-Key' => 'different-image-upload',
        ];

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertCreated();

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(true),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_conflicts_when_the_same_key_targets_a_different_library(): void
    {
        Storage::fake('public');
        $firstLibrary = $this->createImageLibrary('First target');
        $secondLibrary = $this->createImageLibrary('Second target');
        $headers = [
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => 'different-library-target',
        ];

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$firstLibrary->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertCreated();

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$secondLibrary->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, Image::query()->where('library_id', $firstLibrary->id)->count());
        $this->assertSame(0, Image::query()->where('library_id', $secondLibrary->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_conflicts_when_the_same_key_is_used_by_a_different_token(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'different-token-owner';

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => $idempotencyKey,
        ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
            'image' => $this->gifUpload(),
        ])->assertCreated();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => $idempotencyKey,
        ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
            'image' => $this->gifUpload(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_reserves_the_key_before_mutation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'reserved-image-upload';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $lockName = 'geoflow:idempotency:'.hash('sha256', $routeKey."\0".$idempotencyKey);
        $lock = Cache::lock($lockName, 10);
        $this->assertTrue($lock->acquire());

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->materialsToken(),
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'idempotency_in_progress');

            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
        } finally {
            $lock->release();
        }
    }

    public function test_multipart_idempotency_uses_the_shared_database_cache_lock(): void
    {
        config()->set('cache.default', 'database');
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'database-cache-reservation';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $lockName = 'geoflow:idempotency:'.hash('sha256', $routeKey."\0".$idempotencyKey);
        $lock = Cache::store('database')->lock($lockName, 300);
        $this->assertTrue($lock->acquire());
        $this->assertDatabaseCount('cache_locks', 1);

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->materialsToken(),
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'idempotency_in_progress');

            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
        } finally {
            $lock->release();
        }
    }

    public function test_multipart_idempotency_rejects_an_overlong_key_before_mutation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => str_repeat('k', 121),
        ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
            'image' => $this->gifUpload(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_idempotency_key');

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_rejects_an_invalid_key_format_before_mutation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => 'invalid key with spaces',
        ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
            'image' => $this->gifUpload(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_idempotency_key');

        $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_persists_an_in_progress_reservation_before_mutation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'persistent-reservation';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $reservationObserved = false;
        $eventName = 'eloquent.creating: '.Image::class;
        Event::listen($eventName, static function () use ($idempotencyKey, $routeKey, &$reservationObserved): never {
            $row = ApiIdempotencyKey::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('route_key', $routeKey)
                ->first();
            $reservationObserved = $row?->state === 'in_progress'
                && $row->fingerprint_version === 2
                && is_string($row->owner_token)
                && strlen($row->owner_token) === 64
                && $row->lease_expires_at?->isFuture()
                && (int) $row->response_status === 0;

            throw new RuntimeException('forced image insert failure after reservation');
        });

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->materialsToken(),
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])->assertStatus(500);

            $this->assertTrue($reservationObserved);
            $this->assertDatabaseMissing('api_idempotency_keys', [
                'idempotency_key' => $idempotencyKey,
                'route_key' => $routeKey,
            ]);
            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
        } finally {
            Event::forget($eventName);
        }
    }

    public function test_multipart_idempotency_fails_closed_for_a_fresh_persistent_reservation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'fresh-persistent-reservation';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $headers = [
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => $idempotencyKey,
        ];

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertCreated();

        ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->update([
                'state' => 'in_progress',
                'owner_token' => hash('sha256', 'fresh-reservation-owner'),
                'lease_expires_at' => now()->addMinute(),
                'response_body' => '{}',
                'response_status' => 0,
                'updated_at' => now(),
            ]);

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_in_progress');

        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_requires_manual_recovery_for_a_stale_reservation(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'stale-persistent-reservation';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $headers = [
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => $idempotencyKey,
        ];

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertCreated();

        ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->update([
                'state' => 'in_progress',
                'owner_token' => hash('sha256', 'stale-reservation-owner'),
                'lease_expires_at' => now()->subMinute(),
                'response_body' => '{}',
                'response_status' => 0,
                'updated_at' => now()->subMinute(),
            ]);

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_stale')
            ->assertJsonPath('error.details.retryable', false);

        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_multipart_idempotency_rolls_back_business_changes_when_response_finalization_fails(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $idempotencyKey = 'failed-response-finalization';
        $routeKey = 'POST /materials/{type}/{id}/items';
        $headers = [
            'Authorization' => 'Bearer '.$this->materialsToken(),
            'Accept' => 'application/json',
            'X-Idempotency-Key' => $idempotencyKey,
        ];
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
                && str_contains(strtolower($query->sql), 'api_idempotency_keys')) {
                throw new RuntimeException('forced idempotency finalization failure');
            }
        });

        try {
            $this->withHeaders($headers)->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])->assertStatus(500);

            $this->assertDatabaseMissing('api_idempotency_keys', [
                'idempotency_key' => $idempotencyKey,
                'route_key' => $routeKey,
            ]);
            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
        } finally {
            Event::forget(QueryExecuted::class);
        }

        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                'image' => $this->gifUpload(),
            ])
            ->assertCreated();
        $this->assertSame(1, Image::query()->where('library_id', $library->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('uploads/images'));
    }

    public function test_api_upload_removes_the_stored_file_when_database_creation_fails(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $eventName = 'eloquent.creating: '.Image::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced image insert failure');
        });

        try {
            $this->withToken($this->materialsToken())
                ->withHeader('Accept', 'application/json')
                ->post("/api/v1/materials/image-libraries/{$library->id}/items", [
                    'image' => UploadedFile::fake()->image('rollback.png', 20, 10),
                ])
                ->assertStatus(500);

            $this->assertDatabaseMissing('images', ['library_id' => $library->id]);
            $this->assertSame([], Storage::disk('public')->allFiles('uploads/images'));
        } finally {
            Event::forget($eventName);
        }
    }

    public function test_api_item_deletion_removes_a_current_managed_file_after_database_deletion(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/delete-current.png';
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $image = $this->createImage($library, 'storage/'.$diskPath);

        $this->withToken($this->materialsToken())
            ->deleteJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'ids' => [$image->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertModelMissing($image);
        Storage::disk('public')->assertMissing($diskPath);
    }

    public function test_admin_item_deletion_removes_a_legacy_managed_file(): void
    {
        $legacyPath = 'uploads/images/'.uniqid('delete-legacy-', true).'.png';
        $absolutePath = public_path($legacyPath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, 'image');
        $library = $this->createImageLibrary();
        $image = $this->createImage($library, $legacyPath);

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.image-libraries.images.delete', ['libraryId' => $library->id]), [
                'image_ids' => [$image->id],
            ])
            ->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => $library->id]));

        $this->assertFileDoesNotExist($absolutePath);
    }

    public function test_missing_managed_file_cleanup_is_idempotent(): void
    {
        Storage::fake('public');
        $library = $this->createImageLibrary();
        $image = $this->createImage($library, 'storage/uploads/images/2026/07/already-missing.png');

        $this->withToken($this->materialsToken())
            ->deleteJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'ids' => [$image->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertModelMissing($image);
    }

    public function test_cleanup_reports_and_logs_a_path_lock_timeout_without_deleting_the_file(): void
    {
        Storage::fake('public');
        $path = 'storage/uploads/images/2026/07/locked-cleanup.png';
        $diskPath = substr($path, strlen('storage/'));
        Storage::disk('public')->put($diskPath, 'image');
        $lock = Cache::lock($this->managedPathLockName($path), 10);
        $this->assertTrue($lock->acquire());
        Log::spy();

        try {
            $failed = app(ManagedImageFileService::class)->cleanupUnreferenced([$path]);

            $this->assertSame(1, $failed);
            Storage::disk('public')->assertExists($diskPath);
            Log::shouldHaveReceived('warning')->with(
                'geoflow.managed_image_cleanup_failed',
                Mockery::on(fn (array $context): bool => $this->isRedactedFailureContext($context, $path, 'lock_timeout'))
            )->once();
        } finally {
            $lock->release();
        }
    }

    public function test_api_delete_reports_and_logs_a_storage_cleanup_failure(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/delete-failure.png';
        $path = 'storage/'.$diskPath;
        Storage::disk('public')->put($diskPath, 'image');
        $absolutePath = Storage::disk('public')->path($diskPath);
        $disk = Mockery::mock(Storage::disk('public'))->makePartial();
        $disk->shouldReceive('delete')->once()->with($diskPath)->andReturn(false);
        $this->app['filesystem']->set('public', $disk);
        $library = $this->createImageLibrary();
        $image = $this->createImage($library, $path);
        Log::spy();

        $this->withToken($this->materialsToken())
            ->deleteJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'ids' => [$image->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.cleanup_failed_count', 1);

        $this->assertModelMissing($image);
        $this->assertFileExists($absolutePath);
        Log::shouldHaveReceived('warning')->with(
            'geoflow.managed_image_cleanup_failed',
            Mockery::on(fn (array $context): bool => $this->isRedactedFailureContext($context, $path, 'delete_failed'))
        )->once();
    }

    public function test_failed_upload_compensation_is_logged_without_exposing_the_path(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/compensation-failure.png';
        $path = 'storage/'.$diskPath;
        Storage::disk('public')->put($diskPath, 'image');
        $disk = Mockery::mock(Storage::disk('public'))->makePartial();
        $disk->shouldReceive('delete')->once()->with($diskPath)->andReturn(false);
        $this->app['filesystem']->set('public', $disk);
        Log::spy();

        $discarded = app(ManagedImageFileService::class)->discardStoredUpload($path);

        $this->assertFalse($discarded);
        Log::shouldHaveReceived('warning')->with(
            'geoflow.managed_image_compensation_failed',
            Mockery::on(fn (array $context): bool => $this->isRedactedFailureContext($context, $path, 'delete_failed'))
        )->once();
    }

    public function test_upload_compensation_exceptions_are_converted_to_a_logged_failure(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/compensation-exception.png';
        $path = 'storage/'.$diskPath;
        Storage::disk('public')->put($diskPath, 'image');
        $disk = Mockery::mock(Storage::disk('public'))->makePartial();
        $disk->shouldReceive('delete')->once()->with($diskPath)->andThrow(new RuntimeException('sensitive storage failure'));
        $this->app['filesystem']->set('public', $disk);
        Log::spy();

        $discarded = app(ManagedImageFileService::class)->discardStoredUpload($path);

        $this->assertFalse($discarded);
        Log::shouldHaveReceived('warning')->with(
            'geoflow.managed_image_compensation_failed',
            Mockery::on(fn (array $context): bool => $this->isRedactedFailureContext($context, $path, 'operation_failed'))
        )->once();
    }

    public function test_duplicate_path_reference_keeps_the_shared_file(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/shared.png';
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $first = $this->createImage($library, 'storage/'.$diskPath, 'first.png');
        $second = $this->createImage($library, 'storage/'.$diskPath, 'second.png');

        $this->withToken($this->materialsToken())
            ->deleteJson("/api/v1/materials/image-libraries/{$library->id}/items", [
                'ids' => [$first->id],
            ])
            ->assertOk();

        $this->assertModelExists($second);
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_api_library_deletion_removes_current_files(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/delete-library.png';
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $this->createImage($library, 'storage/'.$diskPath);

        $this->withToken($this->materialsToken())
            ->deleteJson("/api/v1/materials/image-libraries/{$library->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertModelMissing($library);
        Storage::disk('public')->assertMissing($diskPath);
    }

    public function test_web_library_deletion_removes_current_files_after_commit(): void
    {
        Storage::fake('public');
        $diskPath = 'uploads/images/2026/07/delete-web-library.png';
        Storage::disk('public')->put($diskPath, 'image');
        $library = $this->createImageLibrary();
        $this->createImage($library, 'storage/'.$diskPath);

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.image-libraries.delete', ['libraryId' => $library->id]))
            ->assertRedirect(route('admin.image-libraries.index'));

        $this->assertModelMissing($library);
        Storage::disk('public')->assertMissing($diskPath);
    }

    private function createAdmin(): Admin
    {
        $this->adminSequence++;

        return Admin::query()->create([
            'username' => 'image_security_admin_'.$this->adminSequence,
            'password' => 'secret-123',
            'email' => 'image-security-'.$this->adminSequence.'@example.com',
            'display_name' => 'Image Security Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createImageLibrary(string $name = 'Security Images'): ImageLibrary
    {
        return ImageLibrary::query()->create([
            'name' => $name,
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
    }

    private function createImage(
        ImageLibrary $library,
        string $path,
        string $filename = 'image.png',
        ?string $managedPathHash = null,
    ): Image {
        return Image::query()->create([
            'library_id' => $library->id,
            'filename' => $filename,
            'original_name' => $filename,
            'file_name' => $filename,
            'file_path' => $path,
            'managed_path_hash' => $managedPathHash ?? app(ManagedImageFileService::class)->pathHash($path),
            'file_size' => 5,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
    }

    private function materialsToken(): string
    {
        return $this->createAdmin()->createToken('image-security', ['materials:read', 'materials:write'])->plainTextToken;
    }

    private function gifUpload(bool $alternate = false): UploadedFile
    {
        $contents = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->assertIsString($contents);
        if ($alternate) {
            $contents[13] = chr(ord($contents[13]) ^ 1);
        }

        return UploadedFile::fake()->createWithContent('same.gif', $contents);
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode(DIRECTORY_SEPARATOR, trim(realpath($from) ?: $from, DIRECTORY_SEPARATOR));
        $toParts = explode(DIRECTORY_SEPARATOR, trim(realpath($to) ?: $to, DIRECTORY_SEPARATOR));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }

    private function managedPathLockName(string $path): string
    {
        return 'geoflow:managed-image-path:'.hash('sha256', $path);
    }

    private function isRedactedFailureContext(array $context, string $path, string $reason): bool
    {
        return ($context['path_fingerprint'] ?? null) === substr(hash('sha256', $path), 0, 16)
            && ($context['path_length'] ?? null) === strlen($path)
            && ($context['reason'] ?? null) === $reason
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), basename($path));
    }
}
