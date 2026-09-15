<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ThemeWorkspaceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_and_preview_assets_do_not_exhaust_password_authorization_attempts(): void
    {
        config(['cache.limiter' => 'database']);
        $this->freezeTime();
        Storage::fake('local');
        $admin = Admin::query()->create(['username' => 'rate-limit-editor', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->withToken($admin->createToken('editor', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken);
        $workspace = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertCreated()->json('data');
        $base = '/api/v1/management/theme-workspaces/'.$workspace['id'];

        for ($read = 0; $read < 6; $read++) {
            $this->getJson($base.'/contract')->assertOk();
        }
        $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
        $workspace = $this->postJson($base.'/changes', ['expected_version' => 1, 'changes' => [
            ['action' => 'put', 'path' => 'public/themes/default/limit.css', 'expected_sha256' => null, 'content' => 'body{color:#135}'],
        ]])->assertOk()->json('data');
        $this->assertSame(2, $workspace['lock_version']);
        $preview = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        parse_str(parse_url($preview, PHP_URL_QUERY), $query);
        $asset = URL::temporarySignedRoute('api.v1.theme-preview-asset', now()->addMinutes(15), [
            'workspace' => $workspace['id'], 'revision' => $workspace['revision_id'], 'assetPath' => 'limit.css', 'token_id' => $query['token_id'],
        ]);
        for ($read = 0; $read < 61; $read++) {
            $this->get($asset)->assertOk()->assertSee('body{color:#135}', false);
        }
        $this->get($preview)->assertOk();
        $this->getJson($base)->assertOk();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
        }
        $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertStatus(429);
        $this->getJson($base)->assertOk();
        $this->get($preview)->assertOk();

        $this->travel(61)->seconds();
        $this->postJson($base.'/code-authorizations', ['password' => 'test-password'])->assertOk();
    }
}
