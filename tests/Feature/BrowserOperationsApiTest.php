<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_login_scopes_exclude_browser_execution_permissions(): void
    {
        $scopes = app(ApiTokenService::class)->getCliLoginScopes();

        $this->assertContains('materials:read', $scopes);
        $this->assertNotContains('browser-operations:read', $scopes);
        $this->assertNotContains('browser-operations:execute', $scopes);
    }

    public function test_admin_can_approve_device_and_extension_receives_one_time_browser_token(): void
    {
        $admin = $this->admin();
        $headers = $this->browserHeaders();

        $authorization = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', [
                'client_name' => 'Operations Chrome',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval',
            ]]);

        $deviceCode = (string) $authorization->json('data.device_code');
        $userCode = (string) $authorization->json('data.user_code');

        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'authorization_pending');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.manual-publications.browser-connect.show', ['user_code' => $userCode]))
            ->assertOk()
            ->assertSee($userCode);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.browser-connect.decision'), [
                'user_code' => $userCode,
                'decision' => 'approve',
            ])
            ->assertRedirect();

        $this->travel(5)->seconds();

        $tokenResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertOk()
            ->assertJsonPath('data.scopes.0', 'browser-operations:read')
            ->assertJsonPath('data.scopes.1', 'browser-operations:execute');

        $plainToken = (string) $tokenResponse->json('data.token');
        $this->assertNotSame('', $plainToken);

        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'expired_token');

        $this->withHeaders($headers + ['Authorization' => 'Bearer '.$plainToken])
            ->getJson('/api/v1/browser-operations/session')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $admin->id)
            ->assertJsonPath('data.protocol_version', 1);
    }

    public function test_device_authorization_can_be_denied_or_expire(): void
    {
        $admin = $this->admin();
        $headers = $this->browserHeaders();

        $denied = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', ['client_name' => 'Denied Chrome'])
            ->assertOk();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.browser-connect.decision'), [
                'user_code' => $denied->json('data.user_code'),
                'decision' => 'deny',
            ])
            ->assertRedirect();
        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', [
                'device_code' => $denied->json('data.device_code'),
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'access_denied');

        $expired = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', ['client_name' => 'Expired Chrome'])
            ->assertOk();
        $this->travel(11)->minutes();
        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', [
                'device_code' => $expired->json('data.device_code'),
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'expired_token');
    }

    public function test_cli_token_and_incompatible_protocol_cannot_use_browser_operations(): void
    {
        $admin = $this->admin();
        $cliToken = $admin->createToken(
            'CLI token',
            app(ApiTokenService::class)->getCliLoginScopes(),
        )->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($cliToken))
            ->getJson('/api/v1/manual-publications')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$cliToken,
            'X-GEOFlow-Browser-Protocol' => '99',
            'X-GEOFlow-Client-Version' => '0.1.0',
        ])->getJson('/api/v1/manual-publications')
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'upgrade_required');
    }

    public function test_browser_token_can_claim_heartbeat_and_complete_an_assigned_publication(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'GEOFlow 专家']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'account_name' => 'GEOFlow 知乎账号',
            'profile_url' => 'https://www.zhihu.com/people/geoflow',
        ]);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_POST,
            'persona_id' => $persona->id,
            'account_id' => $account->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'target_url' => 'https://www.zhihu.com/question/123456',
            'target_url_hash' => hash('sha256', 'https://www.zhihu.com/question/123456'),
            'content' => '这是需要填充到知乎回答编辑器的正文。',
            'content_fingerprint' => hash('sha256', 'browser-operation-content'),
            'identity_snapshot' => [],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 1,
                'target_action' => 'zhihu_answer',
                'title' => '',
                'body_plain' => '这是需要填充到知乎回答编辑器的正文。',
                'body_markdown' => '这是需要填充到知乎回答编辑器的正文。',
                'tags' => [],
                'canonical_url' => 'https://www.zhihu.com/question/123456',
                'disclosure' => null,
                'asset_ids' => [],
            ],
        ]);
        $firstToken = $admin->createToken('Chrome one', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;
        $secondToken = $admin->createToken('Chrome two', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($firstToken))
            ->getJson('/api/v1/manual-publications')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $publication->id)
            ->assertJsonPath('data.items.0.account.profile_url', 'https://www.zhihu.com/people/geoflow');

        $claimHeaders = $this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'claim-publication-12345678',
        ];
        $claim = $this->withHeaders($claimHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 2);

        $this->withHeaders($claimHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 2);

        $this->withHeaders($this->authenticatedHeaders($secondToken) + [
            'X-Idempotency-Key' => 'claim-publication-second-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'publication_claimed');

        $this->withHeaders($this->authenticatedHeaders($secondToken))
            ->getJson('/api/v1/manual-publications/'.$publication->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');

        $this->withHeaders($this->authenticatedHeaders($firstToken))
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.alive', true);

        $receipt = [
            'revision' => 2,
            'outcome' => 'completed',
            'completion_url' => 'https://www.zhihu.com/question/123456/answer/987654',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://www.zhihu.com',
            'observed_account_hash' => hash('sha256', 'https://www.zhihu.com/people/geoflow'),
            'started_at' => now()->subMinute()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];

        $wrongAccountReceipt = array_replace($receipt, [
            'observed_account_hash' => str_repeat('0', 64),
        ]);
        $this->withHeaders($this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-wrong-account-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $wrongAccountReceipt)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_mismatch');

        $wrongQuestionReceipt = array_replace($receipt, [
            'completion_url' => 'https://www.zhihu.com/question/999999/answer/987654',
        ]);
        $this->withHeaders($this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-wrong-question-123', // gitleaks:allow
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $wrongQuestionReceipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_completion_target');

        $receiptHeaders = $this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-publication-123456',
        ];
        $this->withHeaders($receiptHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $receipt)
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED)
            ->assertJsonPath('data.publication.completion_url', $receipt['completion_url'])
            ->assertJsonPath('data.publication.revision', 3);

        $this->withHeaders($receiptHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $receipt)
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED)
            ->assertJsonPath('data.publication.revision', 3);

        $publication->refresh();
        $this->assertSame(ManualPublication::STATUS_COMPLETED, $publication->status);
        $this->assertSame('0.1.0', $publication->execution_receipt['adapter_version']);
        $this->assertNull($publication->browser_claimed_by_token_id);
        $this->assertSame(2, (int) $claim->json('data.publication.revision'));
    }

    public function test_generic_work_order_can_run_without_a_saved_platform_account(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'Generic operator']);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_COMMENT,
            'persona_id' => $persona->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_CUSTOM,
            'custom_platform' => 'Community',
            'target_url' => 'https://community.example.com/thread/123',
            'target_context' => 'A community discussion',
            'target_url_hash' => hash('sha256', 'https://community.example.com/thread/123'),
            'content' => 'Generic browser-assisted reply.',
            'content_fingerprint' => hash('sha256', 'generic-browser-assisted-reply'),
            'identity_snapshot' => [],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 1,
                'target_action' => 'manual_comment',
                'title' => '',
                'body_plain' => 'Generic browser-assisted reply.',
                'body_markdown' => 'Generic browser-assisted reply.',
                'tags' => [],
                'canonical_url' => 'https://community.example.com/thread/123',
                'disclosure' => null,
                'asset_ids' => [],
            ],
        ]);
        $token = $admin->createToken('Generic Chrome', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($token))
            ->getJson('/api/v1/manual-publications')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $publication->id)
            ->assertJsonPath('data.items.0.account', null);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'claim-generic-publication-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS);

        $releaseHeaders = $this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'release-generic-publication-12',
        ];
        $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_READY)
            ->assertJsonPath('data.publication.revision', 3);
        $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_READY)
            ->assertJsonPath('data.publication.revision', 3);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'reclaim-generic-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 3])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 4);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-generic-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
            'revision' => 4,
            'outcome' => 'failed',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://community.example.com',
            'finished_at' => now()->toIso8601String(),
            'error_code' => 'operator_reported_failure',
        ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_FAILED);

        $cancelled = $publication->replicate();
        $cancelled->forceFill([
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
        ])->save();
        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'claim-cancelled-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$cancelled->id.'/claim', ['revision' => 1])
            ->assertOk();
        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-cancelled-publication',
        ])->postJson('/api/v1/manual-publications/'.$cancelled->id.'/receipt', [
            'revision' => 2,
            'outcome' => 'cancelled',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://community.example.com',
            'finished_at' => now()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_CANCELLED);
    }

    public function test_admin_only_sees_and_revokes_owned_browser_connections(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $superAdmin = $this->admin('super_admin');
        $ownBrowserResult = $admin->createToken('Chrome owned', [
            'browser-operations:read', 'browser-operations:execute',
        ]);
        $ownBrowser = $ownBrowserResult->accessToken;
        $admin->createToken('CLI token', ['catalog:read']);
        $otherBrowser = $other->createToken('Chrome other', [
            'browser-operations:read', 'browser-operations:execute',
        ])->accessToken;

        $this->actingAs($admin, 'admin')
            ->get(route('admin.account.browser-clients.index'))
            ->assertOk()
            ->assertSee('Chrome owned')
            ->assertDontSee('CLI token')
            ->assertDontSee('Chrome other');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.account.browser-clients.index'))
            ->assertOk()
            ->assertSee('Chrome owned')
            ->assertSee('Chrome other')
            ->assertDontSee('CLI token');

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.account.browser-clients.destroy', ['tokenId' => $otherBrowser->id]))
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.account.browser-clients.destroy', ['tokenId' => $ownBrowser->id]))
            ->assertRedirect(route('admin.account.browser-clients.index'));

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $ownBrowser->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherBrowser->id]);

        $this->withHeaders($this->authenticatedHeaders($ownBrowserResult->plainTextToken))
            ->getJson('/api/v1/browser-operations/session')
            ->assertUnauthorized();
    }

    /** @return array<string,string> */
    private function browserHeaders(): array
    {
        return [
            'X-GEOFlow-Browser-Protocol' => '1',
            'X-GEOFlow-Client-Version' => '0.1.0',
        ];
    }

    /** @return array<string,string> */
    private function authenticatedHeaders(string $plainToken): array
    {
        return $this->browserHeaders() + ['Authorization' => 'Bearer '.$plainToken];
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('browser_admin_'),
            'password' => 'secret-123',
            'email' => uniqid('browser-').'@example.com',
            'display_name' => 'Browser Operator',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
