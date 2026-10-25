<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiThemeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        Storage::fake('local');
        $admin = Admin::query()->create(['username' => 'theme-editor', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);

        return $admin->createToken('theme-test', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken;
    }

    public function test_builtin_theme_can_be_edited_twice_without_changing_source_or_old_revisions(): void
    {
        $token = $this->token();
        $source = file_get_contents(resource_path('views/theme/default/manifest.json'));
        $created = $this->withToken($token)->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])
            ->assertCreated()->assertJsonPath('data.lock_version', 1);
        $id = $created->json('data.id');
        $base = '/api/v1/management/theme-workspaces/'.$id;
        $changes = [['action' => 'put', 'path' => 'resources/views/theme/default/home.blade.php', 'expected_sha256' => null, 'content' => '<h1>First draft</h1>']];
        $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])->assertForbidden();
        $this->withToken($token)->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
        $first = $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])
            ->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])->assertConflict();
        $changes[0]['expected_sha256'] = hash('sha256', '<h1>First draft</h1>');
        $changes[0]['content'] = '<h1>Second draft</h1>';
        $second = $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 2, 'changes' => $changes])
            ->assertOk()->assertJsonPath('data.lock_version', 3);
        $this->assertNotSame($first->json('data.revision_id'), $second->json('data.revision_id'));
        $preview = $this->withToken($token)->postJson($base.'/previews')->assertOk();
        $url = $preview->json('data.pages.home.url');
        $this->get($url)->assertOk()->assertSee('Second draft')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.'&sitePath=about')->assertForbidden();
        $this->assertNotNull($preview->json('data.pages.empty-state.url'));
        $old = ThemeRevision::query()->findOrFail($first->json('data.revision_id'));
        $oldPath = Storage::disk('local')->path('geoflow-site-themes/revisions/'.$old->id.'/resources/views/theme/default/home.blade.php');
        $this->assertSame('<h1>First draft</h1>', file_get_contents($oldPath));
        $this->assertSame($source, file_get_contents(resource_path('views/theme/default/manifest.json')));
        $this->assertFileDoesNotExist(resource_path('views/theme/default/home.blade.php'));
        $this->withToken($token)->getJson($base.'/files?'.http_build_query(['path' => 'resources/views/theme/default/home.blade.php', 'length' => 4]))
            ->assertOk()->assertJsonPath('data.content', base64_encode('<h1>'));
    }

    public function test_path_escape_wrong_file_hash_and_expired_grant_do_not_mutate_draft(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertCreated()->json('data.id');
        $base = '/api/v1/management/theme-workspaces/'.$id;
        $this->withToken($token)->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
        $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => [['action' => 'put', 'path' => 'resources/views/theme/default/manifest.json', 'expected_sha256' => null, 'content' => '{}']]])->assertConflict();
        $this->withToken($token)->getJson($base.'/files?path=../../.env')->assertUnprocessable();
        $this->travel(31)->minutes();
        $this->withToken($token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => [['action' => 'put', 'path' => 'resources/views/theme/default/home.blade.php', 'expected_sha256' => null, 'content' => 'expired']]])->assertForbidden();
        $this->assertSame(1, ThemeWorkspace::query()->findOrFail($id)->lock_version);
    }

    public function test_role_downgrade_blocks_an_existing_explicit_theme_token(): void
    {
        $token = $this->token();
        Admin::query()->update(['role' => 'admin']);
        $this->withToken($token)->getJson('/api/v1/management/themes')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertForbidden();
    }

    public function test_denied_workspace_changes_do_not_create_storage_lock_files(): void
    {
        $token = $this->token();
        $admin = Admin::query()->firstOrFail();
        $limited = $admin->createToken('limited', ['articles:read'])->plainTextToken;
        $this->withToken($limited)->postJson('/api/v1/management/theme-workspaces/'.Str::uuid().'/changes', [
            'expected_version' => 1, 'changes' => [['action' => 'put', 'path' => 'public/themes/default/test.css', 'expected_sha256' => null, 'content' => 'body{}']],
        ])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/management/theme-workspaces/'.Str::uuid().'/changes', [
            'expected_version' => 1, 'changes' => [['action' => 'put', 'path' => 'public/themes/default/test.css', 'expected_sha256' => null, 'content' => 'body{}']],
        ])->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('geoflow-site-themes'));
    }
}
