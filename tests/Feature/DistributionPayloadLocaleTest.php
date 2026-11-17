<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\ArticleTranslationService;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 验证分发载荷按渠道语言取译文，原文（中文）保持不变。
 */
class DistributionPayloadLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function article(array $attributes = []): Article
    {
        $category = Category::query()->create(['name' => '分类', 'slug' => 'cat-'.uniqid()]);
        $author = Author::query()->create(['name' => '作者']);

        return Article::query()->create(array_merge([
            'title' => '中文标题',
            'slug' => 'slug-'.uniqid(),
            'excerpt' => '中文摘要',
            'content' => "# 中文标题\n\n".str_repeat('中文正文内容。', 40),
            'meta_description' => '中文描述',
            'locale' => 'zh',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'publication_intent' => 'none',
        ], $attributes));
    }

    public function test_payload_defaults_to_chinese_source(): void
    {
        $article = $this->article();

        $payload = app(DistributionPayloadBuilder::class)->build($article);

        $this->assertSame('中文标题', $payload['article']['title']);
        $this->assertSame('zh', $payload['article']['content_locale']);
        $this->assertSame('zh', $payload['article']['default_locale']);
        $this->assertStringContainsString('中文正文内容', $payload['article']['content']);
    }

    public function test_payload_uses_translation_for_target_locale(): void
    {
        $article = $this->article();
        ArticleTranslation::query()->create([
            'article_id' => $article->id,
            'locale' => 'ru',
            'title' => 'Русский заголовок',
            'excerpt' => 'Русская выдержка',
            'content' => "# Русский заголовок\n\n".str_repeat('Русский текст. ', 40),
            'meta_description' => 'Русское описание',
            'source_hash' => app(ArticleTranslationService::class)->sourceHash($article),
            'translated_at' => now(),
        ]);

        $payload = app(DistributionPayloadBuilder::class)->build($article->fresh(), 'ru');

        $this->assertSame('Русский заголовок', $payload['article']['title']);
        $this->assertSame('ru', $payload['article']['content_locale']);
        $this->assertSame('zh', $payload['article']['default_locale']);
        $this->assertStringContainsString('Русский текст', $payload['article']['content']);
        $this->assertStringNotContainsString('中文正文内容', $payload['article']['content']);
    }

    public function test_payload_falls_back_to_source_when_translation_missing(): void
    {
        $article = $this->article();

        $payload = app(DistributionPayloadBuilder::class)->build($article, 'ru');

        // 无译文时回落原文，且不会把中文误标为俄语。
        $this->assertSame('中文标题', $payload['article']['title']);
        $this->assertSame('zh', $payload['article']['content_locale']);
    }

    public function test_channel_locale_defaults_to_chinese(): void
    {
        // 落库后由数据库默认值补齐为 zh；内存实例允许为空，分发侧统一回落 zh。
        $channel = DistributionChannel::query()->create([
            'name' => '渠道',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        $this->assertSame('zh', (string) $channel->fresh()->locale);
    }
}
