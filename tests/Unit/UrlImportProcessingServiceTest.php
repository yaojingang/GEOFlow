<?php

namespace Tests\Unit;

use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use InvalidArgumentException;
use Tests\TestCase;

class UrlImportProcessingServiceTest extends TestCase
{
    private UrlImportProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UrlImportProcessingService(new ApiKeyCrypto);
    }

    public function test_it_accepts_valid_public_url(): void
    {
        $result = $this->service->normalizeInputUrl('https://www.example.com');
        $this->assertSame('https://www.example.com', $result['url']);
        $this->assertSame('www.example.com', $result['host']);
    }

    public function test_it_rejects_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeInputUrl('http://localhost');
    }

    public function test_it_rejects_loopback_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeInputUrl('http://127.0.0.1');
    }

    public function test_it_rejects_private_network_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeInputUrl('http://192.168.1.1');
    }

    public function test_it_rejects_private_hostname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeInputUrl('http://mycomputer.local');
    }

    public function test_it_rejects_url_without_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normalizeInputUrl('not-a-url');
    }

    public function test_it_accepts_valid_url_with_path(): void
    {
        $result = $this->service->normalizeInputUrl('https://www.example.com/some/path');
        $this->assertSame('https://www.example.com/some/path', $result['url']);
        $this->assertSame('www.example.com', $result['host']);
    }

    public function test_it_normalizes_http_to_https(): void
    {
        $result = $this->service->normalizeInputUrl('http://www.example.com');
        $this->assertSame('http://www.example.com', $result['url']);
    }
}