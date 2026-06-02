<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Jobs\ProcessSystemUpdateApplyJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdminSystemUpdatesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.update_center_enabled' => true,
            'geoflow.update_allowed_repository' => 'https://example.test',
            'geoflow.update_archive_max_bytes' => 50 * 1024 * 1024,
            'geoflow.update_archive_max_files' => 2000,
            'geoflow.update_archive_max_file_bytes' => 50 * 1024 * 1024,
            'geoflow.update_archive_max_uncompressed_bytes' => 150 * 1024 * 1024,
            'geoflow.update_preflight_check_git_dirty' => false,
        ]);
    }

    public function test_super_admin_can_open_system_update_center_from_header(): void
    {
        $admin = $this->createAdmin();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'remote-commit',
                'payload' => [
                    'summary_zh' => '测试更新中心摘要',
                    'release_url' => 'https://example.test/release',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.system-updates.index', [], false), false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.page_title'))
            ->assertSee(__('admin.system_updates.section.preflight'))
            ->assertSee('2.0.2')
            ->assertSee('2.0.3')
            ->assertSee('测试更新中心摘要');
    }

    public function test_update_center_can_be_disabled_completely(): void
    {
        $admin = $this->createAdmin();

        config([
            'geoflow.update_center_enabled' => false,
            'geoflow.update_check_enabled' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.system-updates.index', [], false), false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.check'))
            ->assertNotFound();
    }

    public function test_standard_admin_cannot_open_or_refresh_system_update_center(): void
    {
        $admin = $this->createAdmin('standard_update_admin', 'admin');

        config([
            'geoflow.update_center_enabled' => true,
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'archive_url' => 'https://example.test/geoflow.zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.system-updates.index', [], false), false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.check'))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertForbidden();
    }

    public function test_manual_check_refreshes_cached_update_metadata(): void
    {
        Cache::flush();

        $admin = $this->createAdmin();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::sequence()
                ->push(['version' => '2.0.2'], 200)
                ->push(['version' => '2.0.4'], 200),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.status.current'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.check'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee('2.0.4')
            ->assertSee(__('admin.system_updates.status.available'));
    }

    public function test_super_admin_can_generate_update_plan_from_archive(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'app/Support/AdminWelcome/intro_copy.php' => "<?php\nreturn ['updated' => true];\n",
            'database/migrations/2099_01_01_000000_create_demo_table.php' => "<?php\nreturn new class {};\n",
            'composer.lock' => '{"packages":[]}',
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertDatabaseHas('system_update_runs', [
            'action' => 'plan',
            'status' => 'succeeded',
            'target_version' => '2.0.3',
            'risk_level' => 'high',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee('composer.lock')
            ->assertSee('2099_01_01_000000_create_demo_table.php')
            ->assertSee('composer install --no-dev --optimize-autoloader')
            ->assertSee('php artisan migrate --force')
            ->assertSee(__('admin.system_updates.risk.high'))
            ->assertSee(__('admin.system_updates.preflight.manual_steps_warn', ['count' => 2]))
            ->assertSee(__('admin.system_updates.preflight.backup_warn'));
    }

    public function test_update_plan_commands_can_be_marked_as_executed(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'composer.lock' => '{"packages":[]}',
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'));

        $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
        $plan = is_array($run->plan_json) ? $run->plan_json : [];

        $this->assertSame('recommended', $plan['manual_commands'][0]['level'] ?? null);
        $this->assertTrue(collect($plan['manual_commands'] ?? [])->contains(fn (array $command): bool => ($command['level'] ?? null) === 'required'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.button.copy_script'))
            ->assertSee(__('admin.system_updates.button.copy_command'))
            ->assertSee(__('admin.system_updates.commands.level_required'))
            ->assertSee(__('admin.system_updates.commands.pending_execution'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.commands.executed', [
                'runUuid' => $run->run_uuid,
                'commandIndex' => 0,
            ]))
            ->assertRedirect(route('admin.system-updates.index'));

        $run->refresh();
        $plan = is_array($run->plan_json) ? $run->plan_json : [];
        $status = $plan['manual_command_statuses']['0'] ?? null;

        $this->assertIsArray($status);
        $this->assertSame($admin->id, $status['admin_id'] ?? null);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.commands.executed_at', ['time' => (string) ($status['executed_at'] ?? '')]));
    }

    public function test_super_admin_can_create_backup_from_update_plan(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'app/Support/AdminWelcome/intro_copy.php' => "<?php\nreturn ['updated' => true];\n",
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));

        $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ])
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertDatabaseHas('system_update_backups', [
            'from_version' => '2.0.2',
            'to_version' => '2.0.3',
            'file_count' => 1,
            'status' => 'available',
        ]);

        $backup = \App\Models\SystemUpdateBackup::query()->firstOrFail();
        Storage::disk('local')->assertExists($backup->manifest_path);
        Storage::disk('local')->assertExists($backup->files_archive_path);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.preflight.backup_pass'));
    }

    public function test_update_center_preflight_blocks_missing_allowed_repository(): void
    {
        Cache::flush();

        $admin = $this->createAdmin();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_allowed_repository' => '',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.preflight.status_fail'))
            ->assertSee(__('admin.system_updates.preflight.repository_fail'));
    }

    public function test_update_center_preflight_blocks_unapproved_archive_url(): void
    {
        Cache::flush();

        $admin = $this->createAdmin();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_allowed_repository' => 'https://github.com/yaojingang/GEOFlow',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://evil.example/geoflow.zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.preflight.status_fail'))
            ->assertSee(__('admin.system_updates.preflight.repository_archive_fail'));
    }

    public function test_update_plan_rejects_unsafe_archive_paths(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildUnsafeReleaseArchive();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('system_update_runs', [
            'action' => 'plan',
            'status' => 'succeeded',
            'target_version' => '2.0.3',
        ]);
    }

    public function test_update_plan_rejects_duplicate_archive_path_separators(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildDuplicateSeparatorReleaseArchive();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('system_update_runs', [
            'action' => 'plan',
            'status' => 'succeeded',
            'target_version' => '2.0.3',
        ]);
    }

    public function test_update_plan_rejects_archive_from_unapproved_repository(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_allowed_repository' => 'https://github.com/yaojingang/GEOFlow',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://evil.example/geoflow.zip',
            ]),
            'https://evil.example/geoflow.zip' => Http::response('should-not-download', 200),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        Http::assertNotSent(fn ($request): bool => (string) $request->url() === 'https://evil.example/geoflow.zip');
        $this->assertDatabaseMissing('system_update_runs', [
            'action' => 'plan',
            'status' => 'succeeded',
            'target_version' => '2.0.3',
        ]);
    }

    public function test_update_plan_rejects_archive_that_exceeds_limits(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'app/Support/AdminWelcome/large_update.php' => str_repeat('x', 64),
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
            'geoflow.update_archive_max_bytes' => 1024 * 1024,
            'geoflow.update_archive_max_file_bytes' => 32,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('system_update_runs', [
            'action' => 'plan',
            'status' => 'succeeded',
            'target_version' => '2.0.3',
        ]);
    }

    public function test_update_operation_lock_blocks_concurrent_plan_creation(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $lock = Cache::lock('geoflow:system-update:operation', 900);
        $this->assertTrue($lock->get());

        try {
            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.plan'))
                ->assertRedirect(route('admin.system-updates.index'))
                ->assertSessionHasErrors();

            $this->assertDatabaseMissing('system_update_runs', [
                'action' => 'plan',
                'status' => 'succeeded',
            ]);
        } finally {
            $lock->release();
        }
    }

    public function test_backup_for_add_only_plan_is_marked_not_required(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'app/Support/SystemUpdate/NewFileForBackupTest.php' => "<?php\nreturn true;\n",
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));

        $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ])
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertDatabaseHas('system_update_backups', [
            'from_version' => '2.0.2',
            'to_version' => '2.0.3',
            'file_count' => 0,
            'status' => 'not_required',
        ]);

        $backup = \App\Models\SystemUpdateBackup::query()->firstOrFail();
        Storage::disk('local')->assertExists($backup->manifest_path);
        $this->assertNull($backup->files_archive_path);
    }

    public function test_stale_update_plan_is_not_reused_for_newer_metadata(): void
    {
        Cache::flush();

        $admin = $this->createAdmin();
        $oldRunUuid = 'old-plan-run';

        \App\Models\SystemUpdateRun::query()->create([
            'run_uuid' => $oldRunUuid,
            'action' => 'plan',
            'status' => 'succeeded',
            'current_version' => '2.0.2',
            'target_version' => '2.0.3',
            'target_commit' => 'old-commit',
            'deployment_mode' => 'source',
            'risk_level' => 'low',
            'plan_json' => [
                'summary' => ['added' => 1, 'modified' => 0, 'deleted' => 0, 'total' => 1],
                'changes' => [
                    ['path' => 'app/Old.php', 'action' => 'added', 'bytes' => 12],
                ],
            ],
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.4',
                'commit' => 'new-commit',
                'archive_url' => 'https://example.test/geoflow.zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertDontSee($oldRunUuid)
            ->assertSee(__('admin.system_updates.empty.no_plan'));
    }

    public function test_stale_update_plan_is_not_reused_when_metadata_is_unavailable(): void
    {
        Cache::flush();

        $admin = $this->createAdmin();

        \App\Models\SystemUpdateRun::query()->create([
            'run_uuid' => 'stale-plan-run',
            'action' => 'plan',
            'status' => 'succeeded',
            'current_version' => '2.0.1',
            'target_version' => '2.0.2',
            'target_commit' => 'stale-commit',
            'deployment_mode' => 'source',
            'risk_level' => 'low',
            'plan_json' => [
                'summary' => ['added' => 1, 'modified' => 0, 'deleted' => 0, 'total' => 1],
                'changes' => [
                    ['path' => 'app/Stale.php', 'action' => 'added', 'bytes' => 12],
                ],
            ],
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response(['error' => 'unavailable'], 500),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertDontSee('stale-plan-run')
            ->assertSee(__('admin.system_updates.empty.no_plan'));
    }

    public function test_full_release_plan_detects_deleted_tracked_files(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $archive = $this->buildReleaseArchive([
            'artisan' => file_get_contents(base_path('artisan')),
            'composer.json' => file_get_contents(base_path('composer.json')),
        ]);

        config([
            'geoflow.app_version' => '2.0.2',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_archive_apply_enabled' => true,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.0.3',
                'commit' => 'abc123',
                'archive_url' => 'https://example.test/geoflow.zip',
                'archive_sha256' => hash_file('sha256', $archive),
            ]),
            'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.plan'))
            ->assertRedirect(route('admin.system-updates.index'));

        $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
        $plan = is_array($run->plan_json) ? $run->plan_json : [];
        $changes = is_array($plan['changes'] ?? null) ? $plan['changes'] : [];

        $this->assertGreaterThan(0, (int) ($plan['summary']['deleted'] ?? 0));
        $this->assertTrue(collect($changes)->contains(fn (array $change): bool => ($change['path'] ?? '') === 'routes/web.php'
            && ($change['action'] ?? '') === 'deleted'));
    }

    public function test_standard_admin_cannot_create_update_backup(): void
    {
        $admin = $this->createAdmin('standard_update_admin', 'admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.backup'), [
                'run_uuid' => 'missing',
            ])
            ->assertForbidden();
    }

    public function test_update_apply_requires_execution_switch(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateApplyDisabledFixture.php';
        $localPath = base_path($relativePath);

        try {
            File::put($localPath, "<?php\nreturn 'old';\n");
            $archive = $this->buildReleaseArchive([
                $relativePath => "<?php\nreturn 'new';\n",
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => false,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ]);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), [
                    'run_uuid' => $run->run_uuid,
                    'current_admin_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.system-updates.index'))
                ->assertSessionHasErrors();

            $this->assertSame("<?php\nreturn 'old';\n", File::get($localPath));
            $this->assertDatabaseMissing('system_update_runs', [
                'action' => 'apply',
                'status' => 'succeeded',
            ]);
        } finally {
            File::delete($localPath);
        }
    }

    public function test_update_apply_can_be_queued_and_exposed_through_status_endpoint(): void
    {
        Storage::fake('local');
        Cache::flush();
        Queue::fake();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateQueuedApplyFixture.php';
        $localPath = base_path($relativePath);
        $oldContents = "<?php\nreturn 'old-queued';\n";
        $newContents = "<?php\nreturn 'new-queued';\n";

        try {
            File::put($localPath, $oldContents);
            $archive = $this->buildReleaseArchive([
                $relativePath => $newContents,
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $planRun = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $planRun->run_uuid,
            ]);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), [
                    'run_uuid' => $planRun->run_uuid,
                    'current_admin_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.system-updates.index'));

            Queue::assertPushed(ProcessSystemUpdateApplyJob::class);
            $this->assertSame($oldContents, File::get($localPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'apply',
                'status' => 'queued',
            ]);

            $response = $this->actingAs($admin, 'admin')
                ->getJson(route('admin.system-updates.runs.status'))
                ->assertOk()
                ->assertJsonPath('has_active_run', true);

            $this->assertStringContainsString(__('admin.system_updates.progress.queued'), (string) $response->json('html'));
            $this->assertStringContainsString(__('admin.system_updates.run.status_queued'), (string) $response->json('html'));
        } finally {
            File::delete($localPath);
        }
    }

    public function test_active_update_run_blocks_duplicate_apply_queueing(): void
    {
        Storage::fake('local');
        Cache::flush();
        Queue::fake();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateDuplicateApplyFixture.php';
        $localPath = base_path($relativePath);

        try {
            File::put($localPath, "<?php\nreturn 'old-duplicate';\n");
            $archive = $this->buildReleaseArchive([
                $relativePath => "<?php\nreturn 'new-duplicate';\n",
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $planRun = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $planRun->run_uuid,
            ]);

            $payload = [
                'run_uuid' => $planRun->run_uuid,
                'current_admin_password' => 'secret-123',
            ];

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), $payload)
                ->assertRedirect(route('admin.system-updates.index'));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), $payload)
                ->assertRedirect(route('admin.system-updates.index'))
                ->assertSessionHasErrors();

            Queue::assertPushed(ProcessSystemUpdateApplyJob::class, 1);
            $this->assertSame(1, \App\Models\SystemUpdateRun::query()->where('action', 'apply')->count());
        } finally {
            File::delete($localPath);
        }
    }

    public function test_super_admin_can_apply_update_and_rollback_backup_when_switches_are_enabled(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateApplyFixture.php';
        $localPath = base_path($relativePath);
        $oldContents = "<?php\nreturn 'old';\n";
        $newContents = "<?php\nreturn 'new';\n";

        try {
            File::put($localPath, $oldContents);
            $archive = $this->buildReleaseArchive([
                $relativePath => $newContents,
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
                'geoflow.update_rollback_enabled' => true,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ]);

            $backup = \App\Models\SystemUpdateBackup::query()->firstOrFail();

            $this->actingAs($admin, 'admin')
                ->get(route('admin.system-updates.index'))
                ->assertOk()
                ->assertSee(__('admin.system_updates.confirm.apply_update'))
                ->assertSee(__('admin.system_updates.confirm.rollback_backup'));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), [
                    'run_uuid' => $run->run_uuid,
                    'current_admin_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.system-updates.index'));

            $this->assertSame($newContents, File::get($localPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'apply',
                'status' => 'succeeded',
            ]);
            $applyRun = \App\Models\SystemUpdateRun::query()
                ->where('action', 'apply')
                ->where('status', 'succeeded')
                ->firstOrFail();
            $applyPayload = is_array($applyRun->plan_json) ? $applyRun->plan_json : [];

            $this->assertSame(100, (int) ($applyPayload['progress_percent'] ?? 0));
            $this->assertSame('succeeded', $applyPayload['progress_status'] ?? null);
            $this->assertIsArray($applyPayload['verification'] ?? null);
            $this->assertContains('system_updates_route', collect($applyPayload['verification']['items'] ?? [])->pluck('key')->all());

            $this->actingAs($admin, 'admin')
                ->get(route('admin.system-updates.index'))
                ->assertOk()
                ->assertSee(__('admin.system_updates.section.recent_runs'))
                ->assertSee(__('admin.system_updates.progress.complete'))
                ->assertSee(__('admin.system_updates.verification.system_updates_route'));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.rollback', ['backupUuid' => $backup->backup_uuid]), [
                    'current_admin_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.system-updates.index'));

            $this->assertSame($oldContents, File::get($localPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'rollback',
                'status' => 'succeeded',
            ]);
            $rollbackRun = \App\Models\SystemUpdateRun::query()
                ->where('action', 'rollback')
                ->where('status', 'succeeded')
                ->firstOrFail();
            $rollbackPayload = is_array($rollbackRun->plan_json) ? $rollbackRun->plan_json : [];

            $this->assertSame(100, (int) ($rollbackPayload['progress_percent'] ?? 0));
            $this->assertSame('succeeded', $rollbackPayload['progress_status'] ?? null);
            $this->assertIsArray($rollbackPayload['verification'] ?? null);
        } finally {
            File::delete($localPath);
        }
    }

    public function test_backup_detail_shows_preflight_and_restores_single_file(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateSingleFileRollback.php';
        $localPath = base_path($relativePath);
        $oldContents = "<?php\nreturn 'old-single';\n";
        $newContents = "<?php\nreturn 'new-single';\n";

        try {
            File::put($localPath, $oldContents);
            $archive = $this->buildReleaseArchive([
                $relativePath => $newContents,
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
                'geoflow.update_rollback_enabled' => true,
                'geoflow.update_require_admin_password' => false,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ]);
            $backup = \App\Models\SystemUpdateBackup::query()->firstOrFail();

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), [
                    'run_uuid' => $run->run_uuid,
                ])
                ->assertRedirect(route('admin.system-updates.index'));

            $this->assertSame($newContents, File::get($localPath));

            $this->actingAs($admin, 'admin')
                ->get(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]))
                ->assertOk()
                ->assertSee(__('admin.system_updates.section.backup_detail'))
                ->assertSee($relativePath)
                ->assertSee(__('admin.system_updates.rollback_preflight.ready_restore'))
                ->assertSee(__('admin.system_updates.button.restore_file'));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.rollback-file', ['backupUuid' => $backup->backup_uuid]), [
                    'path' => $relativePath,
                ])
                ->assertRedirect(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]));

            $this->assertSame($oldContents, File::get($localPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'rollback_file',
                'status' => 'succeeded',
            ]);
        } finally {
            File::delete($localPath);
        }
    }

    public function test_single_file_restore_is_blocked_when_target_changed_after_update(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateChangedRollbackTarget.php';
        $localPath = base_path($relativePath);
        $oldContents = "<?php\nreturn 'old-target';\n";
        $newContents = "<?php\nreturn 'new-target';\n";
        $localEditContents = "<?php\nreturn 'local-edit';\n";

        try {
            File::put($localPath, $oldContents);
            $archive = $this->buildReleaseArchive([
                $relativePath => $newContents,
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
                'geoflow.update_rollback_enabled' => true,
                'geoflow.update_require_admin_password' => false,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ]);
            $backup = \App\Models\SystemUpdateBackup::query()->firstOrFail();

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.apply'), [
                'run_uuid' => $run->run_uuid,
            ]);
            File::put($localPath, $localEditContents);

            $this->actingAs($admin, 'admin')
                ->get(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]))
                ->assertOk()
                ->assertSee(__('admin.system_updates.rollback_preflight.target_changed'));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.rollback-file', ['backupUuid' => $backup->backup_uuid]), [
                    'path' => $relativePath,
                ])
                ->assertRedirect(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]))
                ->assertSessionHasErrors();

            $this->assertSame($localEditContents, File::get($localPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'rollback_file',
                'status' => 'failed',
            ]);
        } finally {
            File::delete($localPath);
        }
    }

    public function test_single_file_restore_rejects_unsupported_manifest_action(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateUnsupportedRollbackAction.php';
        $manifestPath = 'geoflow-updates/backups/unsupported-action/manifest.json';

        config([
            'geoflow.update_execution_enabled' => true,
            'geoflow.update_rollback_enabled' => true,
            'geoflow.update_require_admin_password' => false,
        ]);

        Storage::disk('local')->put($manifestPath, json_encode([
            'files' => [
                [
                    'path' => $relativePath,
                    'action' => 'unknown',
                    'old_sha256' => '',
                    'new_sha256' => '',
                    'bytes' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $run = \App\Models\SystemUpdateRun::query()->create([
            'run_uuid' => 'unsupported-action-plan',
            'action' => 'plan',
            'status' => 'succeeded',
            'current_version' => '2.0.2',
            'target_version' => '2.0.3',
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $backup = \App\Models\SystemUpdateBackup::query()->create([
            'backup_uuid' => 'unsupported-action-backup',
            'run_id' => $run->id,
            'from_version' => '2.0.2',
            'to_version' => '2.0.3',
            'backup_path' => 'geoflow-updates/backups/unsupported-action',
            'manifest_path' => $manifestPath,
            'files_archive_path' => null,
            'file_count' => 1,
            'total_bytes' => 0,
            'status' => 'available',
            'created_by_admin_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]))
            ->assertOk()
            ->assertSee(__('admin.system_updates.rollback_preflight.unknown_action'))
            ->assertSee('unknown');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.rollback-file', ['backupUuid' => $backup->backup_uuid]), [
                'path' => $relativePath,
            ])
            ->assertRedirect(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('system_update_runs', [
            'action' => 'rollback_file',
            'status' => 'failed',
            'error_message' => __('admin.system_updates.error.backup_file_not_restorable'),
        ]);
    }

    public function test_full_rollback_skips_added_file_that_is_already_missing(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $relativePath = 'app/SystemUpdateAddedAlreadyMissing.php';
        $localPath = base_path($relativePath);
        $newContents = "<?php\nreturn 'added';\n";
        $manifestPath = 'geoflow-updates/backups/added-missing/manifest.json';

        File::delete($localPath);

        config([
            'geoflow.update_execution_enabled' => true,
            'geoflow.update_rollback_enabled' => true,
            'geoflow.update_require_admin_password' => false,
        ]);

        Storage::disk('local')->put($manifestPath, json_encode([
            'files' => [
                [
                    'path' => $relativePath,
                    'action' => 'added',
                    'old_sha256' => '',
                    'new_sha256' => hash('sha256', $newContents),
                    'bytes' => strlen($newContents),
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $run = \App\Models\SystemUpdateRun::query()->create([
            'run_uuid' => 'added-missing-plan',
            'action' => 'plan',
            'status' => 'succeeded',
            'current_version' => '2.0.2',
            'target_version' => '2.0.3',
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $backup = \App\Models\SystemUpdateBackup::query()->create([
            'backup_uuid' => 'added-missing-backup',
            'run_id' => $run->id,
            'from_version' => '2.0.2',
            'to_version' => '2.0.3',
            'backup_path' => 'geoflow-updates/backups/added-missing',
            'manifest_path' => $manifestPath,
            'files_archive_path' => null,
            'file_count' => 1,
            'total_bytes' => strlen($newContents),
            'status' => 'available',
            'created_by_admin_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.rollback', ['backupUuid' => $backup->backup_uuid]))
            ->assertRedirect(route('admin.system-updates.index'));

        $rollback = \App\Models\SystemUpdateRun::query()
            ->where('action', 'rollback')
            ->where('status', 'succeeded')
            ->firstOrFail();
        $report = $rollback->plan_json['rollback_report'] ?? [];

        $this->assertSame(0, (int) ($report['removed'] ?? -1));
        $this->assertSame(1, (int) ($report['skipped'] ?? 0));
        $this->assertSame('added_file_already_missing', $report['files'][0]['action'] ?? null);
    }

    public function test_update_apply_preflight_prevents_partial_file_replacement(): void
    {
        Storage::fake('local');
        Cache::flush();

        $admin = $this->createAdmin();
        $firstPath = 'app/SystemUpdateApplyPreflightA.php';
        $secondPath = 'app/SystemUpdateApplyPreflightB.php';
        $firstLocalPath = base_path($firstPath);
        $secondLocalPath = base_path($secondPath);
        $oldFirst = "<?php\nreturn 'old-a';\n";
        $oldSecond = "<?php\nreturn 'old-b';\n";

        try {
            File::put($firstLocalPath, $oldFirst);
            File::put($secondLocalPath, $oldSecond);

            $archive = $this->buildReleaseArchive([
                $firstPath => "<?php\nreturn 'new-a';\n",
                $secondPath => "<?php\nreturn 'new-b';\n",
            ]);

            config([
                'geoflow.app_version' => '2.0.2',
                'geoflow.update_check_enabled' => true,
                'geoflow.update_metadata_url' => 'https://example.test/version.json',
                'geoflow.update_archive_apply_enabled' => true,
                'geoflow.update_execution_enabled' => true,
                'geoflow.update_rollback_enabled' => true,
            ]);

            Http::fake([
                'https://example.test/version.json' => Http::response([
                    'version' => '2.0.3',
                    'commit' => 'abc123',
                    'archive_url' => 'https://example.test/geoflow.zip',
                    'archive_sha256' => hash_file('sha256', $archive),
                ]),
                'https://example.test/geoflow.zip' => Http::response(file_get_contents($archive), 200, [
                    'Content-Type' => 'application/zip',
                ]),
            ]);

            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.plan'));
            $run = \App\Models\SystemUpdateRun::query()->where('action', 'plan')->firstOrFail();
            $this->actingAs($admin, 'admin')->post(route('admin.system-updates.backup'), [
                'run_uuid' => $run->run_uuid,
            ]);

            $plan = is_array($run->plan_json) ? $run->plan_json : [];
            $sourceRootPath = (string) ($plan['source_root_path'] ?? '');
            File::delete(Storage::disk('local')->path($sourceRootPath.'/'.$secondPath));

            $this->actingAs($admin, 'admin')
                ->post(route('admin.system-updates.apply'), [
                    'run_uuid' => $run->run_uuid,
                    'current_admin_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.system-updates.index'))
                ->assertSessionHasErrors();

            $this->assertSame($oldFirst, File::get($firstLocalPath));
            $this->assertSame($oldSecond, File::get($secondLocalPath));
            $this->assertDatabaseHas('system_update_runs', [
                'action' => 'apply',
                'status' => 'failed',
            ]);
        } finally {
            File::delete($firstLocalPath);
            File::delete($secondLocalPath);
        }
    }

    private function createAdmin(string $username = 'system_update_admin', string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'System Update Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function buildReleaseArchive(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-release-');
        @unlink($path);
        $zipPath = $path.'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($files as $relativePath => $contents) {
            $zip->addFromString('GEOFlow-main/'.$relativePath, $contents);
        }
        $zip->close();

        return $zipPath;
    }

    private function buildUnsafeReleaseArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-unsafe-release-');
        @unlink($path);
        $zipPath = $path.'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('GEOFlow-main/../../outside.php', "<?php\nreturn 'unsafe';\n");
        $zip->close();

        return $zipPath;
    }

    private function buildDuplicateSeparatorReleaseArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-unsafe-release-');
        @unlink($path);
        $zipPath = $path.'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('GEOFlow-main/app//UnsafePath.php', "<?php\nreturn 'unsafe';\n");
        $zip->close();

        return $zipPath;
    }
}
