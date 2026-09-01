<?php

namespace App\Data\Admin;

use App\Models\AiSourceProvider;
use App\Services\GeoFlow\AiUsageReservation;
use JsonSerializable;
use LogicException;

final class AdminAiSourceProviderTestSnapshot implements JsonSerializable
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly int $adminId,
        public readonly int $adminAccessVersion,
        public readonly int $providerId,
        public readonly string $configurationDigest,
        private readonly string $name,
        private readonly string $providerKey,
        private readonly string $endpointUrl,
        private readonly string $status,
        private readonly array $options,
        public readonly ?AiUsageReservation $reservation,
        private readonly string $encryptedApiKey,
    ) {}

    /** @return array<string, mixed> */
    public function probeOptions(): array
    {
        return $this->options;
    }

    public function providerForProbe(): AiSourceProvider
    {
        $provider = new AiSourceProvider;
        $provider->setRawAttributes([
            'id' => $this->providerId,
            'name' => $this->name,
            'provider_key' => $this->providerKey,
            'endpoint_url' => $this->endpointUrl,
            'api_key' => $this->encryptedApiKey,
            'status' => $this->status,
            'metadata_json' => $this->options,
        ], true);
        $provider->exists = true;

        return $provider;
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return [
            'admin_id' => $this->adminId,
            'provider_id' => $this->providerId,
            'provider_key' => $this->providerKey,
        ];
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('AI source provider test snapshots cannot be serialized.');
    }
}
