<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->assertSee(__('admin.system_updates.risk.high'))
            ->assertSee(__('admin.system_updates.preflight.manual_steps_warn', ['count' => 2]))
            ->assertSee(__('admin.system_updates.preflight.backup_warn'));
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
}
