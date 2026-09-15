<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Api\ThemeWorkspaceRetention;
use App\Support\Site\SiteThemePackageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ThemeWorkspaceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        Storage::fake('local');
        $admin = Admin::query()->create(['username' => 'lifecycle-owner', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->withToken($admin->createToken('lifecycle', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken);
        $data = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertCreated()->json('data');

        return [$admin, $data, '/api/v1/management/theme-workspaces/'.$data['id']];
    }

    public function test_contract_is_bound_to_the_readable_workspace_and_declares_actual_limits(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $this->getJson($base.'/contract')->assertOk()->assertJsonPath('data.revision_id', $data['revision_id'])
            ->assertJsonPath('data.page_variables.article.contentHtml', 'string')
            ->assertJsonPath('data.page_variables.archive-index.archives', 'list<array{year:string,month:string,count:int}>')
            ->assertJsonPath('data.page_variables.home.leadForms', 'Collection<string,LeadForm>')
            ->assertJsonPath('data.limits.read_chunk_bytes', 262144)
            ->assertJsonPath('data.configuration.editable', false)->assertJsonPath('data.publication.available', false);
        $this->assertArrayHasKey('resources/views/site/home.blade.php', $data['files']);
        $this->getJson($base.'/files?path=resources/views/site/home.blade.php')->assertOk();
        $other = Admin::query()->create(['username' => 'other-lifecycle', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->withToken($other->createToken('other', ['themes:read'])->plainTextToken)->getJson($base.'/contract')->assertNotFound();
    }

    public function test_discard_invalidates_preview_and_reclaims_only_unreferenced_storage_after_grace_period(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
        $preview = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        $this->postJson($base.'/discard', ['expected_version' => 2])->assertConflict();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()
            ->assertJsonPath('data.state', 'discarded')->assertJsonPath('data.cleanup.revisions_removed', 0);
        $this->get($preview)->assertNotFound();
        $this->assertSame(0, ThemeWorkspace::query()->where('state', 'draft')->count());
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 1);
        $this->assertSame(0, ThemeRevision::query()->count());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('geoflow-site-themes/revisions/'.$data['revision_id']));
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 0);
    }

    public function test_referenced_revision_is_protected_from_cleanup(): void
    {
        [$admin, $data, $base] = $this->workspace();
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => 'default', 'revision_id' => $data['revision_id'], 'settings' => []]);
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 0);
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 0);
        $this->assertNotNull(ThemeRevision::query()->find($data['revision_id']));
    }

    public function test_old_revisions_get_a_new_grace_period_when_the_workspace_is_discarded(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $this->travel(20)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()
            ->assertJsonPath('data.cleanup.state', 'deferred')->assertJsonPath('data.cleanup.revisions_removed', 0);
        $this->assertNotNull(ThemeRevision::query()->find($data['revision_id']));
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 1);
    }

    public function test_missing_files_release_quota_without_claiming_disk_bytes_were_reclaimed(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $quota = ThemeRevision::query()->findOrFail($data['revision_id'])->total_bytes;
        app(SiteThemePackageStorage::class)->deleteRevision($data['revision_id']);
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk();
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()
            ->assertJsonPath('data.cleanup.revisions_removed', 1)->assertJsonPath('data.cleanup.bytes_reclaimed', 0)
            ->assertJsonPath('data.cleanup.quota_bytes_released', $quota);
    }

    public function test_release_rollback_targets_remain_protected_after_the_grace_period(): void
    {
        [$admin, $data, $base] = $this->workspace();
        ThemeRelease::query()->create([
            'id' => (string) Str::uuid(), 'site_key' => 'primary', 'workspace_id' => $data['id'],
            'revision_id' => (string) Str::uuid(), 'previous_revision_id' => $data['revision_id'],
            'admin_id' => $admin->id, 'binding_version' => 1, 'changes' => [], 'plan_sha256' => str_repeat('a', 64),
        ]);
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk();
        $this->travel(16)->minutes();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk()->assertJsonPath('data.cleanup.revisions_removed', 0);
        $this->assertNotNull(ThemeRevision::query()->find($data['revision_id']));
    }

    public function test_cleanup_waits_for_the_same_workspace_lock_used_by_mutations(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $this->postJson($base.'/discard', ['expected_version' => 1])->assertOk();
        $this->travel(16)->minutes();
        config()->set('geoflow.theme_packages.lock_timeout_milliseconds', 10);
        $path = app(SiteThemePackageStorage::class)->directory('locks').'/'.hash('sha256', 'workspace-'.$data['id']).'.lock';
        $lock = fopen($path, 'c+b');
        flock($lock, LOCK_EX);
        try {
            app(ThemeWorkspaceRetention::class)->collect($data['id']);
            $this->fail('Cleanup must not proceed while a mutation holds the workspace lock.');
        } catch (RuntimeException) {
            $this->assertNotNull(ThemeRevision::query()->find($data['revision_id']));
            $this->assertDirectoryExists(Storage::disk('local')->path('geoflow-site-themes/revisions/'.$data['revision_id']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame(1, app(ThemeWorkspaceRetention::class)->collect($data['id'])['revisions_removed']);
    }

    public function test_discard_checks_ownership_before_creating_a_storage_lock(): void
    {
        [$admin, $data, $base] = $this->workspace();
        $other = Admin::query()->create(['username' => 'unauthorized-discard', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $path = app(SiteThemePackageStorage::class)->directory('locks').'/'.hash('sha256', 'workspace-'.$data['id']).'.lock';
        $this->assertFileDoesNotExist($path);
        $this->withToken($other->createToken('other', ['themes:write'])->plainTextToken)
            ->postJson($base.'/discard', ['expected_version' => 1])->assertNotFound();
        $this->assertFileDoesNotExist($path);
        $this->assertSame('draft', ThemeWorkspace::query()->findOrFail($data['id'])->state);
    }

    public function test_capabilities_never_advertise_publication_or_unimplemented_post_idempotency(): void
    {
        $this->workspace();
        $response = $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.theme_publication.available', false);
        $operations = collect($response->json('data.operations'))->keyBy('name');
        $this->assertFalse($operations['theme-workspaces.create']['idempotent']);
        $this->assertFalse($operations->has('theme-plans.apply'));
        $this->postJson('/api/v1/management/theme-plans/unknown/apply')->assertNotFound();
    }
}
