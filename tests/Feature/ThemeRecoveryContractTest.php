<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Admin\SiteThemePackageService;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\SystemUpdater\RecoveryPreparation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class ThemeRecoveryContractTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->directory = sys_get_temp_dir().'/geoflow-theme-recovery-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function workspace(bool $installed = false): array
    {
        $admin = Admin::query()->create(['username' => 'theme-recovery-owner', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $theme = $installed ? 'recovery-installed' : 'default';
        if ($installed) {
            $files = ThemePackageFixture::files($theme);
            $packages = app(SiteThemePackageService::class);
            $inspection = $packages->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, $theme)), $admin->id);
            $packages->install($admin->id, $inspection['token'], true);
        }
        $this->withToken($admin->createToken('theme-recovery', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken);
        $workspace = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => $theme])->assertCreated()->json('data');
        $base = '/api/v1/management/theme-workspaces/'.$workspace['id'];
        $this->postJson($base.'/code-authorizations', ['password' => 'fixture-password'])->assertOk();

        return [$admin, $workspace, $base];
    }

    private function validating(): void
    {
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => 'theme-restore-01', 'phase' => 'validating', 'minimum_updater_protocol' => 5,
        ]));
    }

    public static function themeSources(): array
    {
        return ['builtin' => [false], 'installed' => [true]];
    }

    #[DataProvider('themeSources')]
    public function test_restored_theme_files_and_pointers_are_verified_and_old_grants_are_invalidated(bool $installed): void
    {
        [$admin, $workspace, $base] = $this->workspace($installed);
        $path = $installed ? 'resources/views/theme/recovery-installed/home.blade.php' : 'resources/views/site/home.blade.php';
        foreach ([1, 2] as $round) {
            $workspace = $this->postJson($base.'/changes', ['expected_version' => $workspace['lock_version'], 'changes' => [[
                'action' => 'put', 'path' => $path, 'expected_sha256' => $workspace['files'][$path]['sha256'], 'content' => 'Restored draft round '.$round,
            ]]])->assertOk()->json('data');
            $preview = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
            $this->get($preview)->assertOk()->assertSee('Restored draft round '.$round);
        }
        $revision = ThemeRevision::query()->findOrFail($workspace['revision_id']);
        $bytes = app(ThemeRevisionStorage::class)->contents($revision);
        $snapshot = $revision->only(['files', 'settings', 'dependencies', 'content_sha256', 'total_bytes']);
        // Reconstitute the persisted theme rows and complete local storage fixture.
        $rows = [];
        foreach (['theme_workspaces', 'theme_revisions'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $disk = Storage::disk('local');
        $archive = [];
        foreach ($disk->allFiles('geoflow-site-themes') as $file) {
            $archive[$file] = $disk->get($file);
        }
        DB::table('theme_workspaces')->delete();
        DB::table('theme_revisions')->delete();
        $disk->deleteDirectory('geoflow-site-themes');
        foreach ($archive as $file => $contents) {
            $disk->put($file, $contents);
        }
        foreach ($rows as $table => $records) {
            DB::table($table)->insert($records);
        }
        $this->validating();
        $service = app(RecoveryPreparation::class);
        $report = $service->prepare('theme-restore-01', $service->inspect()['admin_digest']);
        $this->assertSame(3, $report['theme_revisions']);
        $this->assertSame('pass', $service->verify('theme-restore-01')['status']);
        $this->assertSame($snapshot, $revision->fresh()->only(array_keys($snapshot)));
        $this->assertSame($bytes, app(ThemeRevisionStorage::class)->contents($revision->fresh()));
        $this->assertSame($revision->id, ThemeWorkspace::query()->findOrFail($workspace['id'])->revision_id);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertNull(ThemeWorkspace::query()->findOrFail($workspace['id'])->code_token_id);
    }

    #[DataProvider('brokenReferences')]
    public function test_recovery_rejects_missing_live_and_historical_references(string $reference): void
    {
        [$admin, $workspace] = $this->workspace();
        $missing = (string) Str::uuid();
        if ($reference === 'binding') {
            SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $missing, 'settings' => []]);
        } elseif ($reference === 'workspace') {
            ThemeWorkspace::query()->whereKey($workspace['id'])->update(['revision_id' => $missing]);
        } else {
            ThemeRelease::query()->create(['id' => (string) Str::uuid(), 'site_key' => 'primary', 'workspace_id' => $workspace['id'],
                'revision_id' => $reference === 'release' ? $missing : $workspace['revision_id'],
                'previous_revision_id' => $reference === 'previous_release' ? $missing : null,
                'admin_id' => $admin->id, 'binding_version' => 1, 'changes' => [], 'plan_sha256' => str_repeat('a', 64)]);
        }
        $this->validating();
        $this->expectExceptionMessage('recovery_theme_reference_invalid');
        app(RecoveryPreparation::class)->inspect();
    }

    public static function brokenReferences(): array
    {
        return array_combine(['binding', 'workspace', 'release', 'previous_release'], array_map(fn ($name) => [$name], ['binding', 'workspace', 'release', 'previous_release']));
    }

    public function test_recovery_rejects_revision_manifest_which_disagrees_with_restored_database(): void
    {
        [, $workspace] = $this->workspace();
        file_put_contents(Storage::disk('local')->path('geoflow-site-themes/revisions/'.$workspace['revision_id'].'/revision.json'), '{}');
        $this->validating();
        $this->expectExceptionMessage('recovery_theme_manifest_invalid');
        app(RecoveryPreparation::class)->inspect();
    }

    public function test_normally_collected_discarded_workspace_remains_recoverable(): void
    {
        [, $workspace, $base] = $this->workspace();
        $this->postJson($base.'/discard', ['expected_version' => $workspace['lock_version']])->assertOk();
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => $workspace['lock_version']])->assertOk()->assertJsonPath('data.cleanup.state', 'completed');
        $this->assertDatabaseMissing('theme_revisions', ['id' => $workspace['revision_id']]);
        $this->assertDatabaseHas('theme_workspaces', ['id' => $workspace['id'], 'state' => 'discarded']);
        $this->assertSame('pass', app(RecoveryPreparation::class)->inspect()['status']);
    }

    public function test_active_draft_requires_a_revision_of_its_own_theme(): void
    {
        [, $workspace] = $this->workspace();
        ThemeWorkspace::query()->whereKey($workspace['id'])->update(['theme_id' => 'unexpected-theme']);
        $this->expectExceptionMessage('recovery_theme_reference_invalid');
        app(RecoveryPreparation::class)->inspect();
    }

    public function test_missing_theme_bytes_fail_before_restored_credentials_are_invalidated(): void
    {
        [$admin, $workspace] = $this->workspace();
        $service = app(RecoveryPreparation::class);
        $digest = $service->inspect()['admin_digest'];
        unlink(Storage::disk('local')->path('geoflow-site-themes/revisions/'.$workspace['revision_id'].'/resources/views/site/home.blade.php'));
        $this->validating();
        try {
            $service->prepare('theme-restore-01', $digest);
            $this->fail('Missing restored file accepted.');
        } catch (RuntimeException) {
            $this->assertSame(1, $admin->tokens()->count());
            $this->assertDatabaseCount('recovery_preparations', 0);
        }
    }
}
