<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Services\Api\ApiTokenService;
use App\Services\BrowserOperations\DeviceAuthorizationService;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RecoveryAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-recovery-auth-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function hostState(string $phase = 'ready', string $epoch = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
    {
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => $epoch,
            'transaction_id' => $phase === 'ready' ? null : 'recovery-test-01', 'phase' => $phase, 'minimum_updater_protocol' => 5,
        ]));
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'recovery-admin', 'password' => 'recovery-test-password', 'role' => 'super_admin', 'status' => 'active']);
    }

    public function test_password_login_binds_new_token_to_host_epoch(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $result = $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'recovery-test-password'])
            ->assertOk()->assertJsonPath('data.recovery.epoch', str_repeat('a', 32));
        $row = PersonalAccessToken::findToken($result->json('data.token'));
        $this->assertSame(str_repeat('a', 32), $row->recovery_epoch);
        $this->withToken($result->json('data.token'))->getJson('/api/v1/auth/session')->assertOk();
        $this->hostState('ready', str_repeat('c', 32));
        $this->withToken($result->json('data.token'))->getJson('/api/v1/auth/session')->assertUnauthorized();
    }

    public function test_missing_host_state_blocks_login_without_issuing_a_token(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'recovery-test-password'])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'recovery_state_unavailable');
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_restoring_rejects_restored_tokens_and_password_login(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $token = app(ApiTokenService::class)->createToken('recovery', ['sites:read'], $admin->id)['token'];
        $this->hostState('restoring');
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertServiceUnavailable();
        $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'recovery-test-password'])->assertServiceUnavailable();
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_epoch_less_restored_token_is_invalid_even_when_service_is_ready(): void
    {
        $this->hostState();
        $token = $this->admin()->createToken('old-backup', ['sites:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertUnauthorized();
    }

    public function test_authenticated_writes_require_epoch_before_revoking_a_token(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $token = app(ApiTokenService::class)->createToken('current', ['sites:read'], $admin->id)['token'];
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertConflict()->assertJsonPath('error.code', 'recovery_epoch_conflict');
        $this->assertSame(1, $admin->tokens()->count());
        $this->withToken($token)->withHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32))
            ->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_http_ready_allows_login_and_reading_but_holds_mutation_and_horizon_retry(): void
    {
        $this->hostState('http_ready');
        $admin = $this->admin();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'recovery-test-password'])->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk();
        $this->withHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32))->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'recovery_background_held');
        $this->postJson('/horizon/api/jobs/retry/example')->assertServiceUnavailable();
        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_restored_web_session_cannot_be_upgraded_to_a_new_epoch(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession([
            Admin::AUTH_VERSION_SESSION_KEY => $admin->auth_version,
            RecoveryState::SESSION_KEY => str_repeat('c', 32),
        ])->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_http_ready_blocks_get_and_head_that_would_create_a_fact_library(): void
    {
        $this->hostState('http_ready');
        $admin = $this->admin();
        $knowledge = KnowledgeBase::query()->create(['name' => 'Restored knowledge', 'content' => 'Evidence']);
        $this->actingAs($admin, 'admin')->withSession([
            Admin::AUTH_VERSION_SESSION_KEY => $admin->auth_version,
            RecoveryState::SESSION_KEY => str_repeat('a', 32),
        ]);

        foreach (['GET', 'HEAD'] as $method) {
            $this->call($method, route('admin.knowledge-bases.facts.index', $knowledge->id))->assertServiceUnavailable();
            $this->assertDatabaseCount('knowledge_fact_libraries', 0);
        }
    }

    public function test_http_ready_preserves_login_dashboard_and_public_permalink_resolution(): void
    {
        $this->hostState('http_ready');
        $admin = $this->admin();
        $this->post(route('admin.login.attempt'), ['username' => $admin->username, 'password' => 'recovery-test-password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get('/absent-custom-permalink')->assertNotFound();
        $this->assertDatabaseCount('task_runs', 0);
        $this->assertDatabaseCount('view_logs', 0);
    }

    public function test_password_web_login_binds_session_and_remember_credential(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $this->post(route('admin.login.attempt'), ['username' => $admin->username, 'password' => 'recovery-test-password', 'remember' => true])
            ->assertSessionHas(RecoveryState::SESSION_KEY, str_repeat('a', 32));
        $this->assertSame(str_repeat('a', 32), $admin->fresh()->remember_recovery_epoch);
    }

    public function test_signed_preview_and_native_grants_do_not_survive_epoch_change_or_token_id_reuse(): void
    {
        Storage::fake('local');
        $this->hostState();
        $admin = $this->admin();
        $token = app(ApiTokenService::class)->createToken('theme', ['themes:read', 'themes:write', 'themes:code'], $admin->id)['token'];
        $this->withToken($token)->withHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32));
        $id = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertCreated()->json('data.id');
        $base = '/api/v1/management/theme-workspaces/'.$id;
        $this->postJson($base.'/code-authorizations', ['password' => 'recovery-test-password'])->assertOk();
        $preview = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        $this->get($preview)->assertOk();
        $this->hostState('ready', str_repeat('c', 32));
        $this->get($preview)->assertForbidden();
        // Restoring an old sequence can reuse a token id; the old grant still must fail.
        PersonalAccessToken::findToken($token)->forceFill(['recovery_epoch' => str_repeat('c', 32)])->save();
        $this->withHeader('X-GEOFlow-Recovery-Epoch', str_repeat('c', 32))->postJson($base.'/previews')->assertForbidden();
        $this->postJson($base.'/code-authorizations', ['password' => 'recovery-test-password'])->assertOk();
        $this->postJson($base.'/previews')->assertOk();
        $this->get($preview)->assertForbidden();
    }

    public function test_approved_device_authorization_from_restored_cache_cannot_issue_new_credentials(): void
    {
        $this->hostState();
        $admin = $this->admin();
        $devices = app(DeviceAuthorizationService::class);
        $authorization = $devices->create('recovery-test');
        $devices->decide($authorization['user_code'], $admin, true);
        $this->hostState('ready', str_repeat('c', 32));
        $this->assertNull($devices->findByUserCode($authorization['user_code']));
        $this->expectException(ApiException::class);
        try {
            $devices->exchange($authorization['device_code'], '0.1.0');
        } finally {
            $this->assertSame(0, $admin->tokens()->count());
        }
    }

    public function test_http_ready_article_reads_preserve_restored_business_data(): void
    {
        $this->hostState('http_ready');
        $author = Author::query()->create(['name' => 'Recovery', 'slug' => 'recovery-author', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'Recovery', 'slug' => 'recovery-category', 'status' => 'active']);
        $article = Article::query()->create([
            'title' => 'Recovery', 'slug' => 'recovery-article', 'content' => 'restored article',
            'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published',
            'review_status' => 'approved', 'published_at' => now()->subDay(), 'view_count' => 0,
        ]);
        $this->get('/article/recovery-article')->assertOk();
        $this->assertSame(0, (int) $article->fresh()->view_count);
        $this->assertDatabaseCount('view_logs', 0);
        $this->hostState();
        $this->get('/article/recovery-article')->assertOk();
        $this->assertSame(1, (int) $article->fresh()->view_count);
        $this->assertDatabaseCount('view_logs', 1);
    }

    public function test_browser_client_can_revoke_its_current_token_while_background_work_is_held(): void
    {
        $this->hostState('http_ready');
        $admin = $this->admin();
        $token = app(ApiTokenService::class)->createToken('browser', ['browser-operations:read', 'browser-operations:execute'], $admin->id)['token'];
        $this->withToken($token)->withHeader('X-GEOFlow-Browser-Protocol', '1')->withHeader('X-GEOFlow-Client-Version', '0.1.0')
            ->withHeader('X-GEOFlow-Recovery-Epoch', str_repeat('a', 32))
            ->deleteJson('/api/v1/browser-operations/session')->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_unmanaged_deployment_keeps_existing_authentication_behavior(): void
    {
        config(['geoflow.recovery_contract_required' => false]);
        $token = $this->admin()->createToken('legacy', ['sites:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk()
            ->assertJsonPath('data.recovery.supported', false);
    }
}
