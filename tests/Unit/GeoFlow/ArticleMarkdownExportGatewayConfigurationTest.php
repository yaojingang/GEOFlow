<?php

namespace Tests\Unit\GeoFlow;

use App\Services\GeoFlow\ArticleMarkdownExportService;
use GuzzleHttp\Psr7\MultipartStream;
use PHPUnit\Framework\TestCase;

final class ArticleMarkdownExportGatewayConfigurationTest extends TestCase
{
    public function test_nginx_limits_the_prepare_request_before_php_parses_it(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3).'/docker/nginx/geoflow-app.conf');

        self::assertIsString($configuration);
        self::assertStringContainsString(
            'location ~ ^/(?:[^/]+/)*articles/batch/export-markdown/prepare$',
            $configuration,
        );
        self::assertStringContainsString('client_max_body_size 128k;', $configuration);
    }

    public function test_five_hundred_maximum_integer_ids_fit_the_request_limit(): void
    {
        $elements = [['name' => '_token', 'contents' => str_repeat('a', 40)]];
        foreach (range(1, ArticleMarkdownExportService::MAX_ARTICLES) as $offset) {
            $elements[] = [
                'name' => 'article_ids[]',
                'contents' => (string) (PHP_INT_MAX - $offset),
            ];
        }

        $multipart = new MultipartStream($elements);

        self::assertLessThan(
            ArticleMarkdownExportService::MAX_PREPARE_REQUEST_BYTES,
            $multipart->getSize(),
        );
    }
}
