<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Services\GeoFlow\ArticleTranslationService;
use PHPUnit\Framework\TestCase;

/**
 * 只覆盖不依赖数据库的纯逻辑：语言判定、语言规范化、源内容指纹。
 */
class ArticleTranslationServiceLocaleTest extends TestCase
{
    private function service(): ArticleTranslationService
    {
        // 这些方法不触发 AI 调用或数据库访问，用反射构造避开依赖。
        return (new \ReflectionClass(ArticleTranslationService::class))
            ->newInstanceWithoutConstructor();
    }

    public function test_normalizes_common_locale_variants(): void
    {
        $service = $this->service();

        $this->assertSame('zh', $service->normalizeLocale('zh_CN'));
        $this->assertSame('zh', $service->normalizeLocale('ZH-CN'));
        $this->assertSame('ru', $service->normalizeLocale('ru_RU'));
        $this->assertSame('en', $service->normalizeLocale('en_US'));
        $this->assertSame('pt_BR', $service->normalizeLocale('pt-BR'));
        $this->assertNull($service->normalizeLocale('   '));
    }

    public function test_supports_only_configured_target_locales(): void
    {
        $service = $this->service();

        $this->assertTrue($service->supports('ru'));
        $this->assertTrue($service->supports('zh_CN'));
        $this->assertFalse($service->supports('ko'));
    }

    public function test_chinese_source_needs_translation_for_other_locales(): void
    {
        $service = $this->service();
        $article = new Article(['title' => '中文标题', 'content' => '正文', 'locale' => 'zh']);

        $this->assertTrue($service->needsTranslation($article, 'ru'));
        $this->assertTrue($service->needsTranslation($article, 'en'));
        $this->assertFalse($service->needsTranslation($article, 'zh'));
        $this->assertFalse($service->needsTranslation($article, 'zh_CN'));
        $this->assertFalse($service->needsTranslation($article, null));
        $this->assertFalse($service->needsTranslation($article, ''));
    }

    public function test_russian_source_is_never_retranslated(): void
    {
        $service = $this->service();
        $article = new Article(['title' => 'Русский заголовок', 'content' => 'Текст', 'locale' => 'ru']);

        // 历史俄语存量文章不再二次翻译，避免来回改写。
        $this->assertFalse($service->needsTranslation($article, 'ru'));
        $this->assertFalse($service->needsTranslation($article, 'en'));
    }

    public function test_source_hash_changes_with_content(): void
    {
        $service = $this->service();
        $first = new Article(['title' => '标题', 'content' => '正文', 'locale' => 'zh']);
        $second = new Article(['title' => '标题', 'content' => '正文已改', 'locale' => 'zh']);

        $this->assertSame($service->sourceHash($first), $service->sourceHash($first));
        $this->assertNotSame($service->sourceHash($first), $service->sourceHash($second));
    }
}
