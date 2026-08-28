<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUiV3ApiTokenIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_token_submission_creates_only_one_credential(): void
    {
        config()->set('cache.default', 'array');
        Cache::flush();
        $admin = Admin::query()->create([
            'username' => 'token_owner',
            'password' => 'secret-123',
            'email' => 'token@example.com',
            'display_name' => 'Token Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $submissionToken = (string) Str::uuid();
        $payload = [
            'submission_token' => $submissionToken,
            'name' => 'Local CLI',
            'scopes' => ['catalog:read'],
            'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ];

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->post(route('admin.api-tokens.store'), $payload)
            ->assertRedirect(route('admin.api-tokens.index'));

        $firstToken = session('new_api_token');
        $this->assertIsString($firstToken);

        $this->post(route('admin.api-tokens.store'), $payload)
            ->assertRedirect(route('admin.api-tokens.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('new_api_token', $firstToken);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
