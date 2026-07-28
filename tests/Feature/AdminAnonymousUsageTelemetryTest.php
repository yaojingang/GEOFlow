<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SystemState;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAnonymousUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_contains_only_anonymous_installation_activity_fields(): void
    {
        $this->enableTelemetry();
        $admin = $this->createAdmin('telemetry_admin');
        $service = app(AnonymousUsageTelemetry::class);

        $payload = $service->payload($admin);
        $repeatedPayload = $service->payload($admin);
        $secondAdminPayload = $service->payload($this->createAdmin('telemetry_second_admin'));

        $this->assertIsArray($payload);
        $this->assertSame([
            'endpoint',
            'event',
            'instance_id',
            'user_hash',
            'version',
            'interval_seconds',
        ], array_keys($payload));
        $this->assertSame('https://monitor.example/api/pulse', $payload['endpoint']);
        $this->assertSame('admin_active', $payload['event']);
        $this->assertSame('2.1.1', $payload['version']);
        $this->assertSame(86400, $payload['interval_seconds']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['instance_id'],
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['user_hash']);
        $this->assertSame($payload['instance_id'], $repeatedPayload['instance_id']);
        $this->assertSame($payload['user_hash'], $repeatedPayload['user_hash']);
        $this->assertSame($payload['instance_id'], $secondAdminPayload['instance_id']);
        $this->assertNotSame($payload['user_hash'], $secondAdminPayload['user_hash']);

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($admin->email, $encodedPayload);
        $this->assertStringNotContainsString($admin->username, $encodedPayload);
        $this->assertStringNotContainsString('APP_KEY', $encodedPayload);
        $this->assertStringNotContainsString('dashboard', $encodedPayload);

        $state = SystemState::query()->where('key', 'geoflow.anonymous_usage_telemetry')->firstOrFail();
        $this->assertIsArray($state->value);
        $this->assertStringNotContainsString((string) $state->value['secret'], $encodedPayload);
    }

    public function test_admin_layout_loads_local_pulse_script_when_telemetry_is_configured(): void
    {
        $this->enableTelemetry();
        $admin = $this->createAdmin('telemetry_layout_admin');

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertSee('name="geoflow-telemetry-endpoint"', false)
            ->assertSee('content="https://monitor.example/api/pulse"', false)
            ->assertSee('name="geoflow-telemetry-instance"', false)
            ->assertSee('name="geoflow-telemetry-user"', false)
            ->assertSee('js/geoflow-pulse.js', false)
            ->assertDontSee($admin->email, false);
    }

    public function test_telemetry_is_absent_when_disabled_or_endpoint_is_missing(): void
    {
        config([
            'geoflow.telemetry_enabled' => false,
            'geoflow.telemetry_endpoint' => 'https://monitor.example/api/pulse',
            'geoflow.update_check_enabled' => false,
        ]);
        $admin = $this->createAdmin('telemetry_disabled_admin');

        $this->assertNull(app(AnonymousUsageTelemetry::class)->payload($admin));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('name="geoflow-telemetry-endpoint"', false)
            ->assertDontSee('js/geoflow-pulse.js', false);

        config([
            'geoflow.telemetry_enabled' => true,
            'geoflow.telemetry_endpoint' => '',
        ]);

        $this->assertNull(app(AnonymousUsageTelemetry::class)->payload($admin));
    }

    public function test_local_pulse_script_omits_credentials_and_referrer(): void
    {
        $script = file_get_contents(public_path('js/geoflow-pulse.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('credentials: "omit"', $script);
        $this->assertStringContainsString('referrerPolicy: "no-referrer"', $script);
        $this->assertStringContainsString('keepalive: true', $script);
        $this->assertStringNotContainsString('document.cookie', $script);
        $this->assertStringNotContainsString('window.location.href', $script);
    }

    public function test_telemetry_rejects_endpoints_that_could_expose_credentials_or_tracking_parameters(): void
    {
        $admin = $this->createAdmin('telemetry_endpoint_admin');

        foreach ([
            'https://username:password@monitor.example/api/pulse',
            'https://monitor.example/api/pulse?token=secret',
            'https://monitor.example/api/pulse#collector',
        ] as $endpoint) {
            config([
                'geoflow.telemetry_enabled' => true,
                'geoflow.telemetry_endpoint' => $endpoint,
            ]);

            $this->assertNull(
                app(AnonymousUsageTelemetry::class)->payload($admin),
                "Endpoint should be rejected: {$endpoint}",
            );
        }
    }

    public function test_invalid_stored_instance_identifier_is_replaced_with_a_collector_compatible_uuid(): void
    {
        $this->enableTelemetry();
        SystemState::query()->create([
            'key' => 'geoflow.anonymous_usage_telemetry',
            'value' => [
                'instance_id' => '550e8400-e29b-11d4-a716-446655440000',
                'secret' => str_repeat('s', 64),
                'created_at' => now()->toIso8601String(),
            ],
        ]);

        $payload = app(AnonymousUsageTelemetry::class)
            ->payload($this->createAdmin('telemetry_repaired_state_admin'));

        $this->assertIsArray($payload);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['instance_id'],
        );
        $this->assertNotSame('550e8400-e29b-11d4-a716-446655440000', $payload['instance_id']);
    }

    public function test_production_telemetry_requires_an_https_endpoint(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');

        try {
            config([
                'geoflow.telemetry_enabled' => true,
                'geoflow.telemetry_endpoint' => 'http://monitor.example/api/pulse',
            ]);

            $this->assertNull(
                app(AnonymousUsageTelemetry::class)
                    ->payload($this->createAdmin('telemetry_https_admin')),
            );
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }

    public function test_server_activity_reports_install_update_and_one_daily_heartbeat(): void
    {
        $this->enableTelemetry();
        $this->travelTo(now()->startOfDay()->addHours(4));
        Http::fake([
            'https://monitor.example/api/pulse' => Http::response('', 204),
        ]);
        $service = app(AnonymousUsageTelemetry::class);

        $this->assertTrue($service->reportInstalled());
        $this->assertFalse($service->reportInstalled());
        $this->assertNull($service->reportDailyActivity());

        $this->travel(1)->day();
        $this->assertSame('heartbeat', $service->reportDailyActivity());
        $this->assertNull($service->reportDailyActivity());

        config(['geoflow.app_version' => '2.1.2']);
        $this->assertSame('updated', $service->reportDailyActivity());

        Http::assertSentCount(3);
        $events = [];
        Http::assertSent(function ($request) use (&$events): bool {
            $data = $request->data();
            $events[] = $data['event'] ?? null;

            $this->assertSame(['event', 'instance_id', 'version'], array_keys($data));
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                (string) ($data['instance_id'] ?? ''),
            );

            return true;
        });
        $this->assertSame(['installed', 'heartbeat', 'updated'], $events);

        $state = SystemState::query()->where('key', 'geoflow.anonymous_usage_telemetry')->firstOrFail();
        $this->assertSame('2.1.2', $state->value['last_reported_version'] ?? null);
        $this->assertSame('updated', $state->value['last_server_event'] ?? null);
    }

    public function test_failed_server_activity_is_retried_without_affecting_the_application(): void
    {
        $this->enableTelemetry();
        Http::fake([
            'https://monitor.example/api/pulse' => Http::sequence()
                ->push(['error' => 'unavailable'], 503)
                ->push('', 204),
        ]);
        $service = app(AnonymousUsageTelemetry::class);

        $this->assertFalse($service->reportInstalled());
        $state = SystemState::query()->where('key', 'geoflow.anonymous_usage_telemetry')->firstOrFail();
        $this->assertArrayNotHasKey('last_reported_version', $state->value);

        $this->assertTrue($service->reportInstalled());
    }

    public function test_update_without_a_successful_baseline_is_reported_as_installation(): void
    {
        $this->enableTelemetry();
        Http::fake([
            'https://monitor.example/api/pulse' => Http::response('', 204),
        ]);

        $this->assertTrue(app(AnonymousUsageTelemetry::class)->reportUpdated('2.1.2'));
        Http::assertSent(fn ($request): bool => $request->data()['event'] === 'installed'
            && $request->data()['version'] === '2.1.2');
    }

    public function test_daily_heartbeat_command_is_safe_when_telemetry_is_unavailable(): void
    {
        config([
            'geoflow.telemetry_enabled' => false,
            'geoflow.telemetry_endpoint' => '',
        ]);

        $this->artisan('geoflow:telemetry:heartbeat')
            ->expectsOutputToContain('already current or telemetry is unavailable')
            ->assertSuccessful();
    }

    private function enableTelemetry(): void
    {
        config([
            'geoflow.telemetry_enabled' => true,
            'geoflow.telemetry_endpoint' => 'https://monitor.example/api/pulse',
            'geoflow.telemetry_interval_seconds' => 86400,
            'geoflow.app_version' => '2.1.1',
            'geoflow.update_check_enabled' => false,
        ]);
    }

    private function createAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Telemetry Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
