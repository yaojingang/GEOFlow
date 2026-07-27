<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\SystemState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AnonymousUsageTelemetry
{
    private const EVENT_NAME = 'admin_active';

    private const STATE_KEY = 'geoflow.anonymous_usage_telemetry';

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
            'event' => self::EVENT_NAME,
            'instance_id' => $installationState['instance_id'],
            'user_hash' => hash_hmac(
                'sha256',
                'admin:'.$admin->getAuthIdentifier(),
                $installationState['secret'],
            ),
            'version' => $this->validatedVersion(),
            'interval_seconds' => max(
                3600,
                (int) config('geoflow.telemetry_interval_seconds', 86400),
            ),
        ];
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
                $value = $this->newInstallationState();
                $state->forceFill(['value' => $value])->save();
            }

            return [
                'instance_id' => (string) $value['instance_id'],
                'secret' => (string) $value['secret'],
            ];
        } catch (Throwable) {
            return null;
        }
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

    private function validatedVersion(): string
    {
        $version = trim((string) config('geoflow.app_version', '0.0.0-dev'));

        return preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) === 1
            ? $version
            : '0.0.0-dev';
    }
}
