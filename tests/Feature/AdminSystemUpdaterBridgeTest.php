<?php

namespace Tests\Feature;

use App\Contracts\SystemUpdater\AgentClient;
use App\Exceptions\SystemUpdaterPreparationException;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use App\Services\Admin\SystemUpdaterBootstrapService;
use App\Services\Admin\SystemUpdaterBridgeService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminSystemUpdaterBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'geoflow.update_center_enabled' => true,
            'geoflow.update_check_enabled' => false,
        ]);
    }

    public function test_super_admin_sees_the_agent_as_the_only_mutation_boundary_and_read_only_legacy_history(): void
    {
        $this->app->instance(AgentClient::class, new AgentClientStub);
        $run = SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-history-run',
            'action' => 'apply',
            'status' => 'succeeded',
            'target_version' => '2.4.0',
        ]);
        $backup = SystemUpdateBackup::query()->create([
            'backup_uuid' => 'phase-c-history-backup',
            'run_id' => $run->id,
            'from_version' => '2.3.0',
            'to_version' => '2.4.0',
            'backup_path' => '/var/lib/geoflow-updates/phase-c-history-backup',
            'manifest_path' => '/var/lib/geoflow-updates/phase-c-history-backup/manifest.json',
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.title'))
            ->assertSee(__('admin.system_updates.updater.readiness.ready'))
            ->assertSee(__('admin.system_updates.updater.journey.title'))
            ->assertSee('https://github.com/yaojingang/geoflow-updater', false)
            ->assertSee('name="updater_authorization_code"', false)
            ->assertSee(route('admin.system-updates.updater.update'), false)
            ->assertSee(route('admin.system-updates.runs.show', ['runUuid' => $run->run_uuid]), false)
            ->assertSee(route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]), false)
            ->assertSee(__('admin.system_updates.backup.status_available'))
            ->assertDontSee('data-system-update-release-notice', false)
            ->assertDontSee(route('admin.system-updates.check'), false)
            ->assertDontSee(route('admin.system-updates.updater.prepare'), false)
            ->assertDontSee('/system-updates/apply', false)
            ->assertDontSee('/system-updates/plan', false)
            ->assertDontSee('/system-updates/backups/'.$backup->backup_uuid.'/rollback', false);
    }

    public function test_disconnected_page_guides_the_admin_to_get_the_updater_without_fake_host_paths(): void
    {
        config(['geoflow.updater_host_root' => '']);
        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andThrow(new RuntimeException('agent unavailable'));
        });
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->once()->andReturn(null);
        });

        $response = $this->actingAs($this->createAdmin('phase_c_disconnected_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.readiness.not_installed'))
            ->assertSee(__('admin.system_updates.updater.journey.obtain'))
            ->assertSee(__('admin.system_updates.updater.journey.install'))
            ->assertSee(__('admin.system_updates.updater.journey.authorize'))
            ->assertSee(__('admin.system_updates.updater.journey.operate'))
            ->assertSee(__('admin.system_updates.updater.cta.get'))
            ->assertSee(route('admin.system-updates.updater.prepare'), false)
            ->assertDontSee('/absolute/path/to/GEOFlow', false)
            ->assertDontSee(route('admin.system-updates.check'), false);
    }

    public function test_manual_knowledge_sync_explains_its_purpose_and_keeps_the_pending_command_copyable(): void
    {
        $this->app->instance(AgentClient::class, new AgentClientStub);

        $this->actingAs($this->createAdmin('manual_sync_copy_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.manual_commands.title'))
            ->assertSee(__('admin.system_updates.manual_commands.sync_system_knowledge_desc'))
            ->assertSee(__('admin.system_updates.manual_commands.sync_system_knowledge_pending_desc'))
            ->assertSee(__('admin.system_updates.manual_commands.command_label'))
            ->assertSee('php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media')
            ->assertSee('data-system-updater-copy="#manual-command-0-sync_ai_workspace_system_knowledge"', false)
            ->assertSee(__('admin.system_updates.updater.copy'));
    }

    public function test_new_release_notice_explains_changes_and_leads_into_the_existing_update_guidance(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '3.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '3.1.0',
                'tag' => 'v3.1.0',
                'release_date' => '2026-09-01',
                'release_type' => 'minor',
                'payload' => [
                    'summary_zh' => '新增版本更新说明，并优化系统更新引导。',
                    'summary_en' => 'Adds release notes and improves the system update guidance.',
                    'release_url' => 'https://github.com/yaojingang/GEOFlow/releases/tag/v3.1.0',
                ],
            ]),
        ]);
        $this->app->instance(AgentClient::class, new AgentClientStub);

        $response = $this->actingAs($this->createAdmin('release_notice_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee('data-system-update-release-notice', false)
            ->assertSee(__('admin.system_updates.release_notice.title', ['version' => '3.1.0']))
            ->assertSee(__('admin.system_updates.release_notice.version_line', ['current' => '3.0.0', 'latest' => '3.1.0']))
            ->assertSee('新增版本更新说明，并优化系统更新引导。')
            ->assertSee(__('admin.system_updates.release_notice.release_type.minor'))
            ->assertSee(__('admin.system_updates.release_notice.release_date', ['date' => '2026-09-01']))
            ->assertSee('https://github.com/yaojingang/GEOFlow/releases/tag/v3.1.0', false)
            ->assertSee(__('admin.system_updates.release_notice.cta'))
            ->assertSee(__('admin.system_updates.manual_commands.title'))
            ->assertSee(__('admin.system_updates.updater.title'));

        $content = (string) $response->getContent();
        $noticePosition = strpos($content, 'data-system-update-release-notice');
        $manualPosition = strpos($content, __('admin.system_updates.manual_commands.title'));
        $updaterPosition = strpos($content, __('admin.system_updates.updater.title'));

        $this->assertIsInt($noticePosition);
        $this->assertIsInt($manualPosition);
        $this->assertIsInt($updaterPosition);
        $this->assertLessThan($manualPosition, $noticePosition);
        $this->assertLessThan($updaterPosition, $manualPosition);
    }

    public function test_prepared_package_shows_a_download_entry_and_requires_a_real_host_root_before_commands(): void
    {
        config(['geoflow.updater_host_root' => '']);
        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andThrow(new RuntimeException('agent unavailable'));
        });
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->once()->andReturn([
                'version' => '0.2.0',
                'filename' => 'geoflow-updater_0.2.0_linux_amd64.tar.gz',
                'sha256' => str_repeat('a', 64),
            ]);
        });

        $response = $this->actingAs($this->createAdmin('phase_c_prepared_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.readiness.installation_pending'))
            ->assertSee(__('admin.system_updates.updater.cta.download'))
            ->assertSee(route('admin.system-updates.updater.download'), false)
            ->assertSee(__('admin.system_updates.updater.host_root_required_title'))
            ->assertDontSee('sudo geoflow-updater enroll', false)
            ->assertDontSee('/absolute/path/to/GEOFlow', false);
    }

    public function test_prepared_package_reveals_numbered_copyable_commands_when_host_root_is_configured(): void
    {
        config(['geoflow.updater_host_root' => '/srv/geoflow']);
        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andThrow(new RuntimeException('agent unavailable'));
        });
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->once()->andReturn([
                'version' => '0.2.0',
                'filename' => 'geoflow-updater_0.2.0_linux_amd64.tar.gz',
                'sha256' => str_repeat('b', 64),
            ]);
        });

        $response = $this->actingAs($this->createAdmin('phase_c_install_commands_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.install_commands_title'))
            ->assertSee('data-system-updater-copy=', false)
            ->assertSee("--instance-root '/srv/geoflow'")
            ->assertSee(__('admin.system_updates.updater.copy'))
            ->assertDontSee('/absolute/path/to/GEOFlow', false);
    }

    public function test_super_admin_can_prepare_and_download_a_verified_updater_package(): void
    {
        Storage::fake('local');
        $path = 'system-updater/bootstrap/0.2.0/geoflow-updater_0.2.0_linux_amd64.tar.gz';
        Storage::disk('local')->put($path, 'verified archive');
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock) use ($path): void {
            $mock->shouldReceive('prepare')->once()->andReturn(['version' => '0.2.0', 'filename' => basename($path)]);
            $mock->shouldReceive('download')->once()->andReturn(['path' => $path, 'filename' => basename($path)]);
        });

        $admin = $this->createAdmin('phase_c_package_admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.prepare'))
            ->assertRedirect(route('admin.system-updates.index'));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.updater.download'))
            ->assertOk()
            ->assertDownload(basename($path));
    }

    public function test_missing_updater_release_opens_an_actionable_error_dialog_without_a_duplicate_error_banner(): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andThrow(new RuntimeException('agent unavailable'));
        });
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $response = new Response(new Psr7Response(404, [], '{"error":"Not Found"}'));

            $mock->shouldReceive('prepare')->once()->andThrow(new RequestException($response));
            $mock->shouldReceive('state')->once()->andReturn(null);
        });

        $admin = $this->createAdmin('phase_c_missing_release_admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.prepare'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHas('system_updater_error', ['reason' => 'release_not_found'])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee('data-system-updater-error-dialog', false)
            ->assertSee(__('admin.system_updates.updater.error_dialog.title.release_not_found'))
            ->assertSee('https://github.com/yaojingang/geoflow-updater/releases', false)
            ->assertSee('https://github.com/yaojingang/geoflow-updater/actions/workflows/release-candidate.yml', false)
            ->assertSee('https://github.com/yaojingang/geoflow-updater/actions/workflows/release.yml', false)
            ->assertDontSee(__('admin.system_updates.updater.prepare_failed'));
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*\bopen\b)(?=[^>]*data-system-updater-error-dialog)[^>]*>/',
            (string) $response->getContent(),
        );
        $this->assertSame(2, substr_count((string) $response->getContent(), 'method="dialog"'));
    }

    #[DataProvider('updaterPreparationFailureReasons')]
    public function test_updater_preparation_failures_are_classified_for_actionable_guidance(
        string $expectedReason,
        \Throwable $exception,
    ): void {
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock) use ($exception): void {
            $mock->shouldReceive('prepare')->once()->andThrow($exception);
        });

        $admin = $this->createAdmin('phase_c_failure_'.$expectedReason);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.prepare'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHas('system_updater_error', ['reason' => $expectedReason])
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, array{string, \Throwable}> */
    public static function updaterPreparationFailureReasons(): array
    {
        return [
            'release unavailable through previous exception' => [
                'release_unavailable',
                new RuntimeException('wrapped', 0, new RequestException(new Response(new Psr7Response(503)))),
            ],
            'connection failure' => [
                'connection_failed',
                SystemUpdaterPreparationException::connectionFailed(new ConnectionException('connection refused')),
            ],
            'unsupported platform' => [
                'platform_unsupported',
                SystemUpdaterPreparationException::platformUnsupported(
                    new RuntimeException('The signed updater release has no package for this host.'),
                ),
            ],
            'expired signature metadata' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(new RuntimeException('Signed metadata has expired.')),
            ],
            'invalid expiry' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(new RuntimeException('Signed metadata expiry is invalid.')),
            ],
            'unsupported manifest schema' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(new RuntimeException('Bootstrap manifest schema is unsupported.')),
            ],
            'unofficial asset URL' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(new RuntimeException('Bootstrap asset URL is outside the official release.')),
            ],
            'invalid signed asset size' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(new RuntimeException('Bootstrap asset size is invalid.')),
            ],
            'download length mismatch' => [
                'verification_failed',
                SystemUpdaterPreparationException::verificationFailed(
                    new RuntimeException('Updater package length does not match the signed manifest.'),
                ),
            ],
            'storage directory creation' => [
                'storage_failed',
                SystemUpdaterPreparationException::storageFailed(new RuntimeException('Unable to create directory at /private/path.')),
            ],
            'unclassified failure' => ['unexpected', new RuntimeException('unclassified preparation failure')],
        ];
    }

    public function test_mutating_agent_operation_requires_password_and_one_time_authorization_code(): void
    {
        $this->ensureActivityLogTable();
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_c_update_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
                'updater_authorization_code' => '123456',
            ])
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame(['123456'], $client->updates);
        $this->assertSame([], $response->getSession()->getOldInput());
        $activity = AdminActivityLog::query()->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('secret-123', (string) $activity->details);
        $this->assertStringNotContainsString('123456', (string) $activity->details);
        $this->assertStringNotContainsString('updater_authorization_code', (string) $activity->details);
    }

    public function test_invalid_authorization_code_never_reaches_the_agent(): void
    {
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_c_invalid_code_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
                'updater_authorization_code' => "123456\r\nX-Injection: yes",
            ])
            ->assertSessionHasErrors('updater_authorization_code');

        $response->assertSessionMissing('_old_input.updater_authorization_code');
        $this->assertSame([], $client->updates);
    }

    public function test_agent_without_mutation_authorization_capability_cannot_receive_a_mutation(): void
    {
        $client = new AgentClientStub;
        $client->mutationAuthorizationReady = false;
        $this->app->instance(AgentClient::class, $client);
        $admin = $this->createAdmin('phase_c_legacy_agent_admin');

        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.authorization_setup_title'))
            ->assertSee('disabled', false);
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.update'), [
            'current_admin_password' => 'secret-123',
            'updater_authorization_code' => '123456',
        ])->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.verify'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame([], $client->updates);
        $this->assertSame(1, $client->verifications);
    }

    public function test_phase_b_retired_worker_failure_keeps_only_the_signed_update_handover_available(): void
    {
        $client = new AgentClientStub;
        $client->doctorStatus = 'fail';
        $client->retiredWorkerPresent = true;
        $this->app->instance(AgentClient::class, $client);

        $summary = $this->app->make(SystemUpdaterBridgeService::class)->summary();
        $this->assertSame('degraded', $summary['connection']);
        $this->assertTrue($summary['phase_b_handover_ready']);

        $html = $this->actingAs($this->createAdmin('phase_c_handover_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.phase_b_handover_hint'))
            ->getContent();

        preg_match('/<form[^>]+action="[^"]+\/updater\/update".*?<\/form>/s', $html, $updateForm);
        preg_match('/<form[^>]+action="[^"]+\/updater\/backup".*?<\/form>/s', $html, $backupForm);
        $this->assertNotEmpty($updateForm);
        $this->assertNotEmpty($backupForm);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|>)/', $updateForm[0]);
        $this->assertMatchesRegularExpression('/\sdisabled(?:\s|>)/', $backupForm[0]);

        $this->actingAs($this->createAdmin('phase_c_handover_submit_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
                'updater_authorization_code' => '123456',
            ])
            ->assertRedirect(route('admin.system-updates.index'));
        $this->assertSame(['123456'], $client->updates);

        $admin = $this->createAdmin('phase_c_handover_blocked_admin');
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.backup'), [
            'current_admin_password' => 'secret-123',
            'updater_authorization_code' => '234567',
        ])->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.rollback'), [
            'current_admin_password' => 'secret-123',
            'updater_authorization_code' => '345678',
            'recovery_point_id' => '20260827T120000Z-11111111',
        ])->assertSessionHasErrors();
        $this->assertSame([], $client->backups);
        $this->assertSame([], $client->rollbacks);
    }

    public function test_backup_and_rollback_forward_distinct_authorization_codes_while_verify_remains_read_only(): void
    {
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);
        $admin = $this->createAdmin('phase_c_recovery_admin');

        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.backup'), [
            'current_admin_password' => 'secret-123',
            'updater_authorization_code' => '234567',
        ])->assertRedirect(route('admin.system-updates.index'))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.rollback'), [
            'current_admin_password' => 'secret-123',
            'updater_authorization_code' => '345678',
            'recovery_point_id' => '20260827T120000Z-1234abcd',
        ])->assertRedirect(route('admin.system-updates.index'));
        $this->actingAs($admin, 'admin')->post(route('admin.system-updates.updater.verify'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame(['234567'], $client->backups);
        $this->assertSame([['20260827T120000Z-1234abcd', '345678']], $client->rollbacks);
        $this->assertSame(1, $client->verifications);
    }

    public function test_active_legacy_row_blocks_agent_mutation_during_cutover(): void
    {
        SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-active-legacy-run',
            'action' => 'apply',
            'status' => 'running',
        ]);
        $client = new AgentClientStub;
        $client->doctorStatus = 'fail';
        $client->retiredWorkerPresent = true;
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($this->createAdmin('phase_c_cutover_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
                'updater_authorization_code' => '456789',
            ])
            ->assertSessionHasErrors();
        $this->actingAs($this->createAdmin('phase_c_cutover_verify_admin'), 'admin')
            ->post(route('admin.system-updates.updater.verify'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame([], $client->updates);
        $this->assertSame(1, $client->verifications);
    }

    public function test_orphaned_legacy_rows_are_retired_after_the_agent_confirms_the_worker_is_absent(): void
    {
        $queued = SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-stale-queued-legacy-run',
            'action' => 'apply',
            'status' => 'queued',
        ]);
        $running = SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-stale-running-legacy-run',
            'action' => 'rollback',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);
        $admin = $this->createAdmin('phase_c_stale_cutover_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->getContent();
        preg_match('/<form[^>]+action="[^"]+\/updater\/update".*?<\/form>/s', $html, $updateForm);
        $this->assertNotEmpty($updateForm);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|>)/', $updateForm[0]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
                'updater_authorization_code' => '567890',
            ])
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['567890'], $client->updates);
        foreach ([$queued, $running] as $run) {
            $run->refresh();
            $this->assertSame('failed', $run->status);
            $this->assertSame('legacy_executor_retired', $run->error_message);
            $this->assertNotNull($run->finished_at);
        }
    }

    public function test_recovery_required_blocks_mutations_without_polling_forever(): void
    {
        $client = new AgentClientStub;
        $client->current = $client->operation('update', 'recovery_required');
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_c_recovery_required_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response->assertOk()
            ->assertDontSee('data-system-updater-auto-reload', false);
        $html = $response->getContent();
        preg_match('/<form[^>]+action="[^"]+\/updater\/update".*?<\/form>/s', $html, $updateForm);
        $this->assertNotEmpty($updateForm);
        $this->assertMatchesRegularExpression('/\sdisabled(?:\s|>)/', $updateForm[0]);
    }

    public function test_running_operation_keeps_page_polling_enabled(): void
    {
        $client = new AgentClientStub;
        $client->current = $client->operation('update', 'running');
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($this->createAdmin('phase_c_running_operation_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee('data-system-updater-auto-reload="5000"', false);
    }

    public function test_page_only_offers_web_rollback_for_the_newest_update_checkpoint(): void
    {
        $client = new AgentClientStub;
        $client->points = [
            $this->recoveryPoint('20260828T120000Z-1234abcd', 'manual-backup'),
            $this->recoveryPoint('20260827T120000Z-1234abcd', 'update-to-2.4.0'),
            $this->recoveryPoint('20260826T120000Z-1234abcd', 'update-to-2.3.0'),
        ];
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_c_web_rollback_admin'), 'admin')
            ->get(route('admin.system-updates.index'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'action="'.route('admin.system-updates.updater.rollback').'"'));
        $this->assertStringContainsString('value="20260827T120000Z-1234abcd"', $html);
        $this->assertStringNotContainsString('value="20260828T120000Z-1234abcd"', $html);
        $this->assertStringNotContainsString('value="20260826T120000Z-1234abcd"', $html);
    }

    public function test_old_execution_routes_are_retired_and_standard_admin_is_forbidden(): void
    {
        $prefix = '/'.trim((string) config('geoflow.admin_base_path'), '/').'/system-updates';
        $superAdmin = $this->createAdmin('phase_c_route_admin');
        foreach (['plan', 'backup', 'apply', 'runs/example/retry', 'runs/example/mark-failed', 'backups/example/rollback', 'backups/example/files/rollback'] as $path) {
            $this->actingAs($superAdmin, 'admin')->post($prefix.'/'.$path)->assertNotFound();
        }

        $this->actingAs($this->createAdmin('phase_c_standard_admin', 'admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertForbidden();
    }

    public function test_history_uses_a_ninety_day_recent_window_and_keeps_older_rows_read_only(): void
    {
        $recent = SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-recent',
            'action' => 'apply',
            'status' => 'succeeded',
        ]);
        $recent->forceFill(['created_at' => now()->subDays(20)])->save();
        $archived = SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-c-archived',
            'action' => 'rollback',
            'status' => 'failed',
            'error_message' => '<script>alert(1)</script>',
        ]);
        $archived->forceFill(['created_at' => now()->subDays(120)])->save();
        $admin = $this->createAdmin('phase_c_history_admin');

        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee($recent->run_uuid)
            ->assertDontSee($archived->run_uuid);
        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.index', ['history' => 'archived']))
            ->assertOk()
            ->assertSee($archived->run_uuid)
            ->assertDontSee($recent->run_uuid);
        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.runs.show', ['runUuid' => $archived->run_uuid]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('/retry', false)
            ->assertDontSee('/mark-failed', false);
    }

    public function test_archived_runs_and_backups_remain_reachable_after_the_first_twenty_rows(): void
    {
        $admin = $this->createAdmin('phase_c_paginated_history_admin');
        for ($index = 0; $index < 21; $index++) {
            $run = SystemUpdateRun::query()->create([
                'run_uuid' => 'phase-c-paged-run-'.$index,
                'action' => 'apply',
                'status' => 'succeeded',
            ]);
            $run->forceFill(['created_at' => now()->subDays(120)])->save();
            $backup = SystemUpdateBackup::query()->create([
                'backup_uuid' => 'phase-c-paged-backup-'.$index,
                'run_id' => $run->id,
                'backup_path' => '/var/lib/geoflow-updates/phase-c-paged-'.$index,
                'manifest_path' => '/var/lib/geoflow-updates/phase-c-paged-'.$index.'/manifest.json',
                'status' => 'available',
            ]);
            $backup->forceFill(['created_at' => now()->subDays(120)])->save();
        }

        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.index', ['history' => 'archived']))
            ->assertOk()
            ->assertDontSee('phase-c-paged-run-0')
            ->assertDontSee('phase-c-paged-backup-0');
        $this->actingAs($admin, 'admin')->get(route('admin.system-updates.index', [
            'history' => 'archived',
            'runs_page' => 2,
            'backups_page' => 2,
        ]))
            ->assertOk()
            ->assertSee('phase-c-paged-run-0')
            ->assertSee('phase-c-paged-backup-0');
    }

    private function createAdmin(string $username = 'system_updater_admin', string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'System Updater Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function ensureActivityLogTable(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_username', 50);
            $table->string('admin_role', 20)->default('admin');
            $table->string('action', 120);
            $table->string('request_method', 10)->default('POST');
            $table->string('page')->default('');
            $table->string('target_type', 50)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->text('details')->default('');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /** @return array<string, mixed> */
    private function recoveryPoint(string $id, string $reason): array
    {
        return [
            'schema_version' => 1,
            'id' => $id,
            'instance_id' => 'primary',
            'reason' => $reason,
            'created_at' => '2026-08-27T12:00:00Z',
            'version' => '2.4.0',
            'release_sequence' => 17,
        ];
    }
}

class AgentClientStub implements AgentClient
{
    public bool $mutationAuthorizationReady = true;

    public string $doctorStatus = 'pass';

    public bool $retiredWorkerPresent = false;

    /** @var list<array<string, string>> */
    public array $additionalChecks = [];

    /** @var list<string> */
    public array $updates = [];

    /** @var list<string> */
    public array $backups = [];

    public int $verifications = 0;

    /** @var list<array{0: string, 1: string}> */
    public array $rollbacks = [];

    /** @var array<string, mixed>|null */
    public ?array $current = null;

    /** @var list<array<string, mixed>> */
    public array $points = [];

    public function status(): array
    {
        $checks = $this->mutationAuthorizationReady ? [[
            'id' => 'mutation-authorization',
            'status' => 'pass',
            'message' => 'Human mutation authorization is configured',
        ]] : [];
        $checks[] = [
            'id' => 'retired-update-worker',
            'status' => $this->retiredWorkerPresent ? 'fail' : 'pass',
            'message' => $this->retiredWorkerPresent
                ? 'Retired Phase B update worker must be removed during the signed update handover.'
                : 'Retired Phase B update worker is absent.',
        ];

        return [
            'schema_version' => 1,
            'status' => $this->doctorStatus,
            'instance' => ['id' => 'primary', 'version' => '2.4.0', 'release_sequence' => 17],
            'checks' => [...$checks, ...$this->additionalChecks],
            'updater_version' => '0.2.0',
        ];
    }

    public function startUpdate(string $authorizationCode): array
    {
        $this->updates[] = $authorizationCode;

        return $this->queuedOperation('update');
    }

    public function startBackup(string $authorizationCode): array
    {
        $this->backups[] = $authorizationCode;

        return $this->queuedOperation('backup');
    }

    public function startRollback(string $recoveryPointId, string $authorizationCode): array
    {
        $this->rollbacks[] = [$recoveryPointId, $authorizationCode];

        return $this->queuedOperation('rollback');
    }

    public function startVerify(): array
    {
        $this->verifications++;

        return $this->queuedOperation('verify');
    }

    public function currentOperation(): ?array
    {
        return $this->current;
    }

    public function recoveryPoints(): array
    {
        return $this->points;
    }

    /** @return array<string, mixed> */
    public function operation(string $kind, string $status): array
    {
        return [
            ...$this->queuedOperation($kind),
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function queuedOperation(string $kind): array
    {
        return [
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => $kind,
            'status' => 'queued',
            'stages' => [],
            'started_at' => '2026-08-27T12:34:56Z',
        ];
    }
}
