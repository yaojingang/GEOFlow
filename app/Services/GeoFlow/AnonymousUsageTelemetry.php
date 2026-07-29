<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\SystemState;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AnonymousUsageTelemetry
{
    private const ADMIN_EVENT = 'admin_active';

    private const STATE_KEY = 'geoflow.anonymous_usage_telemetry';

    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @return array{
     *     endpoint: string,
     *     event: string,
     *     instance_id: string,
     *     user_hash: string,
     *     version: string,
     *     interval_seconds: int
     * }|null
     */
    public function payload(Admin $admin): ?array
    {
        if (! (bool) config('geoflow.telemetry_enabled', false)) {
            return null;
        }

        $endpoint = $this->validatedEndpoint();
        if ($endpoint === null) {
            return null;
        }

        $installationState = $this->installationState();
        if ($installationState === null) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'event' => self::ADMIN_EVENT,
            'instance_id' => $installationState['instance_id'],
            'user_hash' => $this->adminHash($admin, $installationState['secret']),
            'version' => $this->validatedVersion(),
            'interval_seconds' => max(
                3600,
                (int) config('geoflow.telemetry_interval_seconds', 86400),
            ),
        ];
    }

    public function reportAdminLogin(Admin $admin, string $channel): bool
    {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['web', 'api'], true)) {
            return false;
        }

        $endpoint = $this->validatedEndpoint();
        $installationState = $this->installationState();
        if ($endpoint === null || $installationState === null) {
            return false;
        }

        try {
            $request = $this->http
                ->timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->asJson();
            $response = $this->safeHttp->post(
                $request,
                $endpoint,
                [
                    'event' => 'admin_login',
                    'event_id' => (string) Str::uuid(),
                    'instance_id' => $installationState['instance_id'],
                    'user_hash' => $this->adminHash($admin, $installationState['secret']),
                    'version' => $this->validatedVersion(),
                    'channel' => $channel,
                ],
                16 * 1024,
            );

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function reportInstalled(): bool
    {
        $state = $this->telemetryState();
        if (! $state instanceof SystemState) {
            return false;
        }

        $value = is_array($state->value) ? $state->value : [];
        if ($this->lastReportedVersion($value) !== null) {
            return false;
        }

        return $this->sendServerEvent($state, $value, 'installed', $this->validatedVersion());
    }

    public function reportUpdated(?string $version = null): bool
    {
        $state = $this->telemetryState();
        if (! $state instanceof SystemState) {
            return false;
        }

        $value = is_array($state->value) ? $state->value : [];
        $version = $this->validatedVersion($version);
        $lastReportedVersion = $this->lastReportedVersion($value);
        if ($lastReportedVersion === $version) {
            return false;
        }

        return $this->sendServerEvent(
            $state,
            $value,
            $lastReportedVersion === null ? 'installed' : 'updated',
            $version,
        );
    }

    public function reportDailyActivity(): ?string
    {
        $state = $this->telemetryState();
        if (! $state instanceof SystemState) {
            return null;
        }

        $value = is_array($state->value) ? $state->value : [];
        $version = $this->validatedVersion();
        $lastReportedVersion = $this->lastReportedVersion($value);
        $event = match (true) {
            $lastReportedVersion === null => 'installed',
            $lastReportedVersion !== $version => 'updated',
            (string) ($value['last_server_activity_date'] ?? '') !== now()->toDateString() => 'heartbeat',
            default => null,
        };

        if ($event === null) {
            return null;
        }

        return $this->sendServerEvent($state, $value, $event, $version)
            ? $event
            : null;
    }

    private function validatedEndpoint(): ?string
    {
        $endpoint = trim((string) config('geoflow.telemetry_endpoint', ''));
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $disallowedPart) {
            if (array_key_exists($disallowedPart, $parts)) {
                return null;
            }
        }

        if (app()->isProduction() && $scheme !== 'https') {
            return null;
        }

        return $endpoint;
    }

    /**
     * @return array{instance_id: string, secret: string}|null
     */
    private function installationState(): ?array
    {
        $state = $this->telemetryState();
        if (! $state instanceof SystemState) {
            return null;
        }

        $value = is_array($state->value) ? $state->value : [];

        return [
            'instance_id' => (string) $value['instance_id'],
            'secret' => (string) $value['secret'],
        ];
    }

    /**
     * @return array{instance_id: string, secret: string, created_at: string}
     */
    private function newInstallationState(): array
    {
        return [
            'instance_id' => (string) Str::uuid(),
            'secret' => Str::random(64),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function isValidInstallationState(array $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) ($value['instance_id'] ?? ''),
        ) === 1
            && strlen((string) ($value['secret'] ?? '')) >= 32;
    }

    private function validatedVersion(?string $candidate = null): string
    {
        $version = trim((string) ($candidate ?? config('geoflow.app_version', '0.0.0-dev')));

        return preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) === 1
            ? $version
            : '0.0.0-dev';
    }

    private function telemetryState(): ?SystemState
    {
        if (! (bool) config('geoflow.telemetry_enabled', false)
            || $this->validatedEndpoint() === null) {
            return null;
        }

        try {
            if (! Schema::hasTable('system_states')) {
                return null;
            }

            $state = SystemState::query()->firstOrCreate(
                ['key' => self::STATE_KEY],
                ['value' => $this->newInstallationState()],
            );
            $value = is_array($state->value) ? $state->value : [];

            if (! $this->isValidInstallationState($value)) {
                $state->forceFill(['value' => $this->newInstallationState()])->save();
            }

            return $state->refresh();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function lastReportedVersion(array $value): ?string
    {
        $version = trim((string) ($value['last_reported_version'] ?? ''));

        return preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) === 1
            ? $version
            : null;
    }

    private function adminHash(Admin $admin, string $secret): string
    {
        return hash_hmac(
            'sha256',
            'admin:'.$admin->getAuthIdentifier(),
            $secret,
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function sendServerEvent(
        SystemState $state,
        array $value,
        string $event,
        string $version,
    ): bool {
        $endpoint = $this->validatedEndpoint();
        if ($endpoint === null) {
            return false;
        }

        try {
            $request = $this->http
                ->timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->asJson();
            $response = $this->safeHttp->post(
                $request,
                $endpoint,
                [
                    'event' => $event,
                    'instance_id' => (string) $value['instance_id'],
                    'version' => $version,
                ],
                16 * 1024,
            );

            if (! $response->successful()) {
                return false;
            }

            $value['last_reported_version'] = $version;
            $value['last_server_activity_date'] = now()->toDateString();
            $value['last_server_event'] = $event;
            $value['last_server_event_at'] = now()->toIso8601String();
            $state->forceFill(['value' => $value])->save();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
