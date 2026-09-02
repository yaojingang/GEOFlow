<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Services\GeoFlow\KnowledgeEmbeddingModelFingerprint;
use PHPUnit\Framework\TestCase;

final class KnowledgeEmbeddingModelFingerprintTest extends TestCase
{
    public function test_profile_digest_normalizes_provider_and_includes_dimensions_and_schema_versions(): void
    {
        $fingerprint = new KnowledgeEmbeddingModelFingerprint;
        $first = $this->model('HTTPS://AI.Example.test:8443/gateway/v1/?token=secret', 'Embedding-V2');
        $equivalent = $this->model('https://ai.example.test:8443/gateway/v1', 'Embedding-V2');

        $this->assertSame($fingerprint->forModel($first, 1536), $fingerprint->forModel($equivalent, 1536));
        $this->assertNotSame($fingerprint->forModel($first, 1536), $fingerprint->forModel($first, 3072));
        $this->assertSame('https://ai.example.test:8443/gateway/v1', $fingerprint->provider($first));
        $this->assertSame(
            $fingerprint->provider($this->model('https://ai.example.test:443/v1', 'Embedding-V2')),
            $fingerprint->provider($this->model('https://ai.example.test/v1', 'Embedding-V2')),
        );
        $this->assertSame(1, $fingerprint->profileVersion());
    }

    public function test_configuration_revision_changes_when_endpoint_model_or_encrypted_credential_changes(): void
    {
        $fingerprint = new KnowledgeEmbeddingModelFingerprint;
        $original = $this->model('https://ai.example.test/v1', 'embedding-v1', 'encrypted-key-a');

        $this->assertNotSame($fingerprint->configurationRevision($original), $fingerprint->configurationRevision($this->model('https://ai.example.test/proxy/v1', 'embedding-v1', 'encrypted-key-a')));
        $this->assertNotSame($fingerprint->configurationRevision($original), $fingerprint->configurationRevision($this->model('https://ai.example.test/v1', 'embedding-v2', 'encrypted-key-a')));
        $this->assertNotSame($fingerprint->configurationRevision($original), $fingerprint->configurationRevision($this->model('https://ai.example.test/v1', 'embedding-v1', 'encrypted-key-b')));
    }

    public function test_provider_normalization_preserves_path_case(): void
    {
        $fingerprint = new KnowledgeEmbeddingModelFingerprint;
        $upperPath = $this->model('HTTPS://AI.Example.test/Gateway/V1?token=secret', 'embedding-v1');
        $samePath = $this->model('https://ai.example.test/Gateway/V1', 'embedding-v1');
        $lowerPath = $this->model('https://ai.example.test/gateway/v1', 'embedding-v1');

        $this->assertSame('https://ai.example.test/Gateway/V1', $fingerprint->provider($upperPath));
        $this->assertSame($fingerprint->forModel($upperPath, 1536), $fingerprint->forModel($samePath, 1536));
        $this->assertNotSame($fingerprint->forModel($upperPath, 1536), $fingerprint->forModel($lowerPath, 1536));
    }

    private function model(string $url, string $identifier, string $encryptedKey = 'encrypted-key'): AiModel
    {
        $model = new AiModel;
        $model->setRawAttributes([
            'id' => 42,
            'api_url' => $url,
            'model_id' => $identifier,
            'api_key' => $encryptedKey,
        ], true);

        return $model;
    }
}
