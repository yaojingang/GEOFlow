<?php

namespace Tests\Feature;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class DistributionTargetPermalinkPatternTest extends TestCase
{
    public function test_generated_runtime_supports_safe_root_categories_and_rejects_reserved_ones(): void
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate('/{category}/{id}');
        $channel = new DistributionChannel([
            'name' => 'Root Category Runtime',
            'domain' => 'runtime.example.com',
            'endpoint_url' => 'https://runtime.example.com',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'front_mode' => 'rewrite',
            'site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => $policy->toArray()],
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_test', 'gfsec_test');
        $zip = new ZipArchive;

        try {
            $this->assertTrue($zip->open($package['path']));
            $frontController = (string) $zip->getFromName('public/index.php');
            $start = strpos($frontController, 'function articlePermalinkDefaultPolicy');
            $end = strpos($frontController, 'function normalizeSiteSettings');
            $categoryStart = strpos($frontController, 'function articleCategorySlug');
            $categoryEnd = strpos($frontController, 'function articleDate');

            $this->assertIsInt($start);
            $this->assertIsInt($end);
            $this->assertIsInt($categoryStart);
            $this->assertIsInt($categoryEnd);
            eval(
                substr($frontController, $start, $end - $start)
                .substr($frontController, $categoryStart, $categoryEnd - $categoryStart)
            );

            $this->assertSame(
                ArticlePermalinkPattern::reservedFirstSegments(''),
                articlePermalinkReservedFirstSegments(),
            );

            $article = [
                'id' => 42,
                'slug' => 'runtime-article',
                'category' => ['slug' => 'industry'],
                'created_at' => '2026-09-13T00:00:00+00:00',
            ];
            $this->assertSame('/{category}/{id}', normalizeArticlePermalinkPattern('/{category}/{id}'));
            $this->assertSame('/industry/42', renderArticlePermalinkPattern('/{category}/{id}', $article));
            $this->assertSame(
                ['category' => 'industry', 'id' => '42'],
                matchArticlePermalinkPattern('/{category}/{id}', '/industry/42'),
            );
            $this->assertNull(matchArticlePermalinkPattern('/{category}/{id}', '/api/42'));

            $article['category']['slug'] = 'api';
            $this->expectException(InvalidArgumentException::class);
            renderArticlePermalinkPattern('/{category}/{id}', $article);
        } finally {
            $zip->close();
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
        }
    }
}
