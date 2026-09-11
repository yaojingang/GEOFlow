<?php

namespace Tests\Unit;

use App\Support\GeoFlow\OpenAiRuntimeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VolcengineMultimodalEmbeddingRouteTest extends TestCase
{
    #[Test]
    public function it_routes_vision_embedding_on_volces_host_to_multimodal_driver(): void
    {
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver(
            'https://ark.cn-beijing.volces.com/api/v3',
            'doubao-embedding-vision-251215',
        );

        $this->assertSame('volcengine-multimodal', $driver);
    }

    #[Test]
    public function it_does_not_route_text_embedding_on_volces_host_to_multimodal_driver(): void
    {
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver(
            'https://ark.cn-beijing.volces.com/api/v3',
            'doubao-embedding-text-240515',
        );

        $this->assertSame('openai-compatible', $driver);
    }

    #[Test]
    public function it_does_not_route_vision_model_on_openai_host_to_multimodal_driver(): void
    {
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver(
            'https://api.openai.com/v1',
            'doubao-embedding-vision-251215',
        );

        $this->assertSame('openai', $driver);
    }

    #[Test]
    public function it_keeps_gemini_priority_over_volcengine_driver_classification(): void
    {
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver(
            'https://generativelanguage.googleapis.com/v1beta',
            'doubao-embedding-vision-251215',
        );

        $this->assertSame('gemini', $driver);
    }

    #[Test]
    public function it_returns_fixed_multimodal_endpoint_path(): void
    {
        $this->assertSame(
            '/embeddings/multimodal-embedding-v1',
            OpenAiRuntimeProvider::volcengineMultimodalEmbeddingPath(),
        );
    }

    #[Test]
    public function it_detects_volcengine_provider_url_only_for_volces_hosts(): void
    {
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineProviderUrl('https://ark.cn-beijing.volces.com/api/v3'));
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineProviderUrl('https://ark.cn-shanghai.volces.com/api/v3'));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineProviderUrl('https://api.openai.com/v1'));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineProviderUrl('https://volces.com.example.com/api/v3'));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineProviderUrl(''));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineProviderUrl('not-a-url'));
    }

    #[Test]
    public function it_detects_vision_substring_case_insensitively(): void
    {
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineMultimodalEmbeddingModel('doubao-embedding-vision-251215'));
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineMultimodalEmbeddingModel('Doubao-Embedding-VISION-150'));
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineMultimodalEmbeddingModel('multi-Vision-model'));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineMultimodalEmbeddingModel('doubao-embedding-text-240515'));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineMultimodalEmbeddingModel(''));
    }

    #[Test]
    public function it_composites_volcengine_url_and_vision_model_into_multimodal_embedding(): void
    {
        $this->assertTrue(OpenAiRuntimeProvider::isVolcengineMultimodalEmbedding(
            'https://ark.cn-beijing.volces.com/api/v3',
            'doubao-embedding-vision-251215',
        ));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineMultimodalEmbedding(
            'https://ark.cn-beijing.volces.com/api/v3',
            'doubao-embedding-text-240515',
        ));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineMultimodalEmbedding(
            'https://api.openai.com/v1',
            'doubao-embedding-vision-251215',
        ));
        $this->assertFalse(OpenAiRuntimeProvider::isVolcengineMultimodalEmbedding('', ''));
    }

    #[Test]
    public function it_resolves_embedding_base_url_without_collapsing_volcengine_branch(): void
    {
        // The base URL helper strips /embeddings or /chat/completions but never
        // collapses the volces host, otherwise the multimodal path can never be
        // detected by resolveEndpoint downstream.
        $base = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl('https://ark.cn-beijing.volces.com/api/v3/embeddings');
        $this->assertSame('https://ark.cn-beijing.volces.com/api/v3', $base);
    }
}
