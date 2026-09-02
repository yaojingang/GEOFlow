<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\OpenAiRuntimeProvider;

final class KnowledgeEmbeddingModelFingerprint
{
    public const PROFILE_VERSION = 1;

    private const NORMALIZATION_VERSION = 1;

    public function forModel(AiModel $model, int $observedDimensions = 0): string
    {
        return $this->forRuntimeProfile(
            (string) ($model->api_url ?? ''),
            (string) ($model->model_id ?? ''),
            $observedDimensions,
        );
    }

    public function forRuntimeProfile(string $apiUrl, string $modelIdentifier, int $observedDimensions): string
    {
        $profile = $this->compatibilityProfile($apiUrl, $modelIdentifier);
        $profile['observed_dimensions'] = max(0, $observedDimensions);

        if ($profile['provider'] === '' || $profile['model'] === '' || $profile['observed_dimensions'] <= 0) {
            return '';
        }

        return $this->digest($profile);
    }

    public function provider(AiModel $model): string
    {
        return $this->normalizeProvider((string) ($model->api_url ?? ''));
    }

    public function normalizeProvider(string $apiUrl): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl($apiUrl);

        return $this->normalizeProviderBase($baseUrl);
    }

    public function profileVersion(): int
    {
        return self::PROFILE_VERSION;
    }

    public function configurationRevision(AiModel $model): string
    {
        return $this->digest([
            ...$this->compatibilityProfile((string) ($model->api_url ?? ''), (string) ($model->model_id ?? '')),
            'database_model_id' => (int) $model->getKey(),
            'credential_revision' => hash('sha256', (string) ($model->getRawOriginal('api_key') ?? '')),
        ]);
    }

    public function emptyConfigurationRevision(): string
    {
        return $this->digest([
            'profile_version' => self::PROFILE_VERSION,
            'configuration' => 'none',
        ]);
    }

    /** @return array{profile_version:int,protocol:string,driver:string,provider:string,model:string,normalization_version:int} */
    private function compatibilityProfile(string $apiUrl, string $modelIdentifier): array
    {
        $baseUrl = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl($apiUrl);
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver($baseUrl, $modelIdentifier);

        return [
            'profile_version' => self::PROFILE_VERSION,
            'protocol' => $driver === 'gemini' ? 'gemini-batch-embed-v1beta' : 'openai-embeddings-v1',
            'driver' => $driver,
            'provider' => $this->normalizeProviderBase($baseUrl),
            'model' => trim($modelIdentifier),
            'normalization_version' => self::NORMALIZATION_VERSION,
        ];
    }

    private function normalizeProviderBase(string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $configuredPort = isset($parts['port']) ? (int) $parts['port'] : null;
        $port = $configuredPort !== null
            && ! (($scheme === 'https' && $configuredPort === 443) || ($scheme === 'http' && $configuredPort === 80))
                ? ':'.$configuredPort
                : '';
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '/') {
            $path = '';
        }

        return $scheme.'://'.$host.$port.$path;
    }

    /** @param array<string,int|string> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
