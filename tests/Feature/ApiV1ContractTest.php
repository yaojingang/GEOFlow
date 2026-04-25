<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API v1 契约：鉴权、scope、登录与统一信封（SQLite 测试库依赖 {@see 2026_04_18_120002_sqlite_geoflow_minimal_for_testing}）。
 */
class ApiV1ContractTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveAdmin(string $username = 'api_test_admin', string $password = 'secret-123'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => $password,
            'email' => 't@example.com',
            'display_name' => 'API Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{plain: string}
     */
    private function createBearerToken(Admin $admin, array $scopes): array
    {
        $plain = $admin->createToken('contract-test', $scopes)->plainTextToken;

        return ['plain' => $plain];
    }

    public function test_catalog_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_login_validation_empty_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_error_response_includes_request_id_meta(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);
    }

    public function test_login_invalid_credentials_returns_401(): void
    {
        $this->createActiveAdmin('u1', 'right-pass');

        $this->postJson('/api/v1/auth/login', [
            'username' => 'u1',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_login_success_returns_token_and_admin_summary(): void
    {
        $this->createActiveAdmin('u2', 'good-pass');

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'u2',
            'password' => 'good-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'expires_at', 'admin' => ['id', 'username', 'display_name', 'role', 'status']],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_catalog_forbidden_when_scope_missing(): void
    {
        $admin = $this->createActiveAdmin('u3', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_catalog_success_envelope_with_catalog_read_scope(): void
    {
        $admin = $this->createActiveAdmin('u4', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'models',
                    'prompts',
                    'title_libraries',
                    'knowledge_bases',
                    'authors',
                    'categories',
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
