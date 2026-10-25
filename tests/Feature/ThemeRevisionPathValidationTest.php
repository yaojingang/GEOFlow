<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeRevisionPathValidationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        Storage::fake('local');
        $admin = Admin::query()->create(['username' => 'path-editor', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->withToken($admin->createToken('path-validation', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken);
        $workspace = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])
            ->assertCreated()->json('data');
        $base = '/api/v1/management/theme-workspaces/'.$workspace['id'];
        $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();

        return [$workspace, $base];
    }

    #[DataProvider('conflictingPaths')]
    public function test_path_set_conflicts_return_422_before_allocating_a_revision(array $paths): void
    {
        [$workspace, $base] = $this->workspace();
        $quota = ThemeRevision::query()->sum('total_bytes');
        $directories = Storage::disk('local')->allDirectories('geoflow-site-themes/revisions');
        $files = Storage::disk('local')->allFiles('geoflow-site-themes/revisions');
        $changes = array_map(static fn (string $path): array => [
            'action' => 'put', 'path' => 'public/themes/default/'.$path,
            'expected_sha256' => null, 'content' => 'body{color:navy}',
        ], $paths);

        $this->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])
            ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_theme_file');

        $this->assertSame(1, ThemeRevision::query()->count());
        $this->assertSame($quota, ThemeRevision::query()->sum('total_bytes'));
        $this->assertSame($directories, Storage::disk('local')->allDirectories('geoflow-site-themes/revisions'));
        $this->assertSame($files, Storage::disk('local')->allFiles('geoflow-site-themes/revisions'));
        $this->assertSame($workspace['revision_id'], ThemeWorkspace::query()->findOrFail($workspace['id'])->revision_id);
        $this->assertSame(1, ThemeWorkspace::query()->findOrFail($workspace['id'])->lock_version);
    }

    public static function conflictingPaths(): array
    {
        return [
            'file before descendant' => [['a.css', 'a.css/child.css']],
            'descendant before file' => [['a.css/child.css', 'a.css']],
            'directory case conflict' => [['Foo/a.css', 'foo/b.css']],
            'directory case conflict reversed' => [['foo/b.css', 'Foo/a.css']],
            'ancestor case conflict' => [['Foo.css', 'foo.css/child.css']],
            'complete path case conflict' => [['a.css', 'A.css']],
        ];
    }

    public function test_valid_nested_paths_remain_writable_and_readable(): void
    {
        [$workspace, $base] = $this->workspace();
        $changes = array_map(static fn (string $path): array => [
            'action' => 'put', 'path' => 'public/themes/default/'.$path,
            'expected_sha256' => null, 'content' => 'body{color:navy}',
        ], ['nested/child/b.css', 'nested/a.css']);

        $updated = $this->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])
            ->assertOk()->assertJsonPath('data.lock_version', 2)->json('data');

        $this->assertNotSame($workspace['revision_id'], $updated['revision_id']);
        $this->assertSame(2, ThemeRevision::query()->count());
        foreach ($changes as $change) {
            $this->getJson($base.'/files?'.http_build_query(['path' => $change['path']]))
                ->assertOk()->assertJsonPath('data.content', base64_encode('body{color:navy}'));
        }
    }
}
