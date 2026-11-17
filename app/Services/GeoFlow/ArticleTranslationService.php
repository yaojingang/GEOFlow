<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 文章译文服务：中文原文永久保留在 articles，译文按语言落库到 article_translations。
 *
 * 设计要点：
 * - 审查始终基于中文原文，译文只在分发/前台展示时使用；
 * - 译文带 source_hash，原文变更后自动判定过期并重新翻译；
 * - 翻译失败不阻断业务，调用方回落原文并记录 translation_error。
 */
final class ArticleTranslationService
{
    private const CACHE_TTL_DAYS = 30;

    private const MIN_SOURCE_CHARS = 20;

    /** 译文残留源语言字符的容忍上限，超过判定翻译失败。 */
    private const MAX_RESIDUAL_RATIO = 0.02;

    private const LOCALE_NAMES = [
        'ru' => '俄语',
        'en' => '英语',
        'zh' => '中文',
        'zh_CN' => '中文',
        'zh_TW' => '繁体中文',
        'ja' => '日语',
        'es' => '西班牙语',
        'pt_BR' => '葡萄牙语',
    ];

    public function __construct(
        private readonly ArticleContentGenerationService $generation,
    ) {}

    /**
     * 中文原文是否需要翻译到目标语言。
     */
    public function needsTranslation(Article $article, ?string $targetLocale): bool
    {
        $target = $this->normalizeLocale($targetLocale);
        if ($target === null || $target === 'zh') {
            return false;
        }

        $source = $this->normalizeLocale((string) ($article->locale ?? 'zh')) ?? 'zh';
        if ($source === $target) {
            return false;
        }

        // 只翻译中文原文；非中文原文（历史俄语存量）不再二次翻译。
        return $source === 'zh';
    }

    /**
     * 取译文；存在且未过期直接返回，否则调用模型翻译并落库。
     *
     * 返回 null 表示不可用，调用方应回落原文。
     */
    public function resolve(Article $article, ?string $targetLocale, bool $force = false): ?ArticleTranslation
    {
        if (! $this->needsTranslation($article, $targetLocale)) {
            return null;
        }

        $target = $this->normalizeLocale($targetLocale);
        if ($target === null) {
            return null;
        }

        $sourceHash = $this->sourceHash($article);
        $existing = $this->findTranslation($article, $target);
        if (! $force && $existing instanceof ArticleTranslation && $existing->isCurrentFor($sourceHash)) {
            return $existing;
        }

        return $this->translate($article, $target, $sourceHash);
    }

    /**
     * 强制重新翻译（忽略既有译文）。
     */
    public function retranslate(Article $article, ?string $targetLocale): ?ArticleTranslation
    {
        return $this->resolve($article, $targetLocale, true);
    }

    /**
     * 原文内容指纹，用于判定译文是否过期。
     */
    public function sourceHash(Article $article): string
    {
        return hash('sha256', json_encode([
            'title' => (string) $article->title,
            'excerpt' => (string) ($article->excerpt ?? ''),
            'content' => (string) $article->content,
            'meta_description' => (string) ($article->meta_description ?? ''),
            'locale' => (string) ($article->locale ?? 'zh'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function findTranslation(Article $article, string $locale): ?ArticleTranslation
    {
        if ($article->relationLoaded('translations')) {
            return $article->translations->firstWhere('locale', $locale);
        }

        return ArticleTranslation::query()
            ->where('article_id', (int) $article->id)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * 批量取译文，供列表页一次性预加载，避免 N+1。
     *
     * @param  iterable<Article>  $articles
     * @return array<int,ArticleTranslation>
     */
    public function translationsFor(iterable $articles, ?string $locale): array
    {
        $target = $this->normalizeLocale($locale);
        if ($target === null || $target === 'zh') {
            return [];
        }

        $ids = [];
        foreach ($articles as $article) {
            if (! $article instanceof Article) {
                continue;
            }
            if ($this->needsTranslation($article, $target)) {
                $ids[] = (int) $article->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return ArticleTranslation::query()
            ->whereIn('article_id', array_values(array_unique($ids)))
            ->where('locale', $target)
            ->whereNull('translation_error')
            ->get()
            ->keyBy('article_id')
            ->all();
    }

    /**
     * 列表展示标题：命中译文用译文标题，否则用原文标题。
     *
     * @param  array<int,ArticleTranslation>  $translations
     */
    public function displayTitle(Article $article, array $translations): string
    {
        $translation = $translations[(int) $article->id] ?? null;

        return $translation instanceof ArticleTranslation && trim((string) $translation->title) !== ''
            ? (string) $translation->title
            : (string) $article->title;
    }

    /**
     * 目标语言是否受支持。
     */
    public function supports(string $locale): bool
    {
        return array_key_exists($this->normalizeLocale($locale) ?? '', self::LOCALE_NAMES);
    }

    /** @return array<string,string> */
    public function supportedLocales(): array
    {
        return [
            'zh' => '中文（原文）',
            'ru' => '俄语',
            'en' => '英语',
            'ja' => '日语',
            'es' => '西班牙语',
            'pt_BR' => '葡萄牙语',
        ];
    }

    public function normalizeLocale(?string $locale): ?string
    {
        $locale = trim((string) $locale);
        if ($locale === '') {
            return null;
        }

        $locale = str_replace('-', '_', $locale);
        $map = [
            'zh_cn' => 'zh', 'zh_hans' => 'zh', 'cn' => 'zh', 'zh_tw' => 'zh_TW', 'zh_hant' => 'zh_TW',
            'ru_ru' => 'ru', 'en_us' => 'en', 'en_gb' => 'en', 'ja_jp' => 'ja',
            'es_es' => 'es', 'pt_br' => 'pt_BR', 'pt' => 'pt_BR',
        ];

        return $map[strtolower($locale)] ?? $locale;
    }

    private function translate(Article $article, string $locale, string $sourceHash): ?ArticleTranslation
    {
        $source = [
            'title' => (string) $article->title,
            'excerpt' => (string) ($article->excerpt ?? ''),
            'content' => (string) $article->content,
            'meta_description' => (string) ($article->meta_description ?? ''),
        ];

        if (mb_strlen(trim($source['content'])) < self::MIN_SOURCE_CHARS) {
            return $this->persistFailure($article, $locale, $sourceHash, 'source_content_too_short');
        }

        $model = $this->resolveModel($article);
        if (! $model instanceof AiModel) {
            return $this->persistFailure($article, $locale, $sourceHash, 'translation_model_unavailable');
        }

        $cacheKey = 'article-translation:'.$locale.':'.(int) $article->id.':'.substr($sourceHash, 0, 32);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['title'], $cached['content'])) {
            return $this->persist($article, $locale, $sourceHash, $cached);
        }

        try {
            $response = $this->generation->generate(
                $model,
                $this->buildPrompt($source, $locale),
                null,
                $this->instructions($locale),
                (int) config('geoflow.translation_timeout_seconds', 300),
            );
        } catch (Throwable $exception) {
            Log::warning('Article translation failed.', [
                'article_id' => (int) $article->id,
                'locale' => $locale,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);

            return $this->persistFailure($article, $locale, $sourceHash, 'translation_request_failed');
        }

        $decoded = $this->decode((string) ($response->text ?? ''));
        if ($decoded === null) {
            return $this->persistFailure($article, $locale, $sourceHash, 'translation_output_invalid');
        }

        $error = $this->validate($decoded, $source, $locale);
        if ($error !== null) {
            return $this->persistFailure($article, $locale, $sourceHash, $error);
        }

        Cache::put($cacheKey, $decoded, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->persist($article, $locale, $sourceHash, $decoded);
    }

    /**
     * 校验译文完整性，返回错误码或 null。
     *
     * @param  array{title:string,excerpt:string,content:string,meta_description:string}  $decoded
     * @param  array{title:string,excerpt:string,content:string,meta_description:string}  $source
     */
    private function validate(array $decoded, array $source, string $locale): ?string
    {
        if (trim($decoded['title']) === '' || trim($decoded['content']) === '') {
            return 'translation_output_empty';
        }

        if (mb_strlen($decoded['title']) > 500) {
            return 'translation_title_too_long';
        }

        // 译文长度不应显著短于原文，否则大概率被截断。
        $sourceLength = mb_strlen(trim($source['content']));
        $targetLength = mb_strlen(trim($decoded['content']));
        if ($sourceLength > 0 && $targetLength < $sourceLength * 0.4) {
            return 'translation_output_truncated';
        }

        // 小标题数量不应少于原文，进一步排除截断。
        $sourceHeadings = preg_match_all('/^#{2,3} /mu', $source['content']) ?: 0;
        $targetHeadings = preg_match_all('/^#{2,3} /mu', $decoded['content']) ?: 0;
        if ($sourceHeadings > 0 && $targetHeadings < $sourceHeadings) {
            return 'translation_output_truncated';
        }

        // 结尾完整性：只在明显被截断时判定失败（无任何结尾标点且不以完整字符收尾）。
        $trimmed = rtrim(trim($decoded['content']));
        $tail = $trimmed === '' ? '' : mb_substr($trimmed, -1);
        if ($tail !== '' && preg_match('/[\p{L}\p{N}]/u', $tail) !== 1
            && preg_match('/[.!?。！？…»"\')\]\|*_`~]/u', $tail) !== 1) {
            return 'translation_output_truncated';
        }

        // 末行若被硬截断（大量未闭合的 Markdown 结构），判定失败。
        if ($tail !== '' && preg_match('/[\p{L}\p{N}]/u', $tail) === 1
            && preg_match('/[\s,，、;；:：]$/u', $trimmed) === 1) {
            return 'translation_output_truncated';
        }

        // 中文原文译成外文后不应残留大量汉字。
        if ($locale !== 'zh' && $locale !== 'zh_TW') {
            $residual = preg_match_all('/\p{Han}/u', $decoded['content']) ?: 0;
            if ($targetLength > 0 && ($residual / $targetLength) > self::MAX_RESIDUAL_RATIO) {
                return 'translation_residual_source_language';
            }
        }

        return null;
    }

    /**
     * @param  array{title:string,excerpt:string,content:string,meta_description:string}  $fields
     */
    private function persist(Article $article, string $locale, string $sourceHash, array $fields): ArticleTranslation
    {
        return DB::transaction(function () use ($article, $locale, $sourceHash, $fields): ArticleTranslation {
            /** @var ArticleTranslation $translation */
            $translation = ArticleTranslation::query()->updateOrCreate(
                ['article_id' => (int) $article->id, 'locale' => $locale],
                [
                    'title' => mb_substr(trim($fields['title']), 0, 500),
                    'excerpt' => trim($fields['excerpt']) !== '' ? trim($fields['excerpt']) : null,
                    'content' => trim($fields['content']),
                    'meta_description' => trim($fields['meta_description']) !== ''
                        ? mb_substr(trim($fields['meta_description']), 0, 255)
                        : null,
                    'source_hash' => $sourceHash,
                    'translation_error' => null,
                    'translated_at' => now(),
                ],
            );

            if ($article->relationLoaded('translations')) {
                $article->unsetRelation('translations');
            }

            return $translation;
        }, 3);
    }

    private function persistFailure(Article $article, string $locale, string $sourceHash, string $error): ?ArticleTranslation
    {
        Log::warning('Article translation unavailable.', [
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'error' => $error,
        ]);

        $translation = ArticleTranslation::query()
            ->where('article_id', (int) $article->id)
            ->where('locale', $locale)
            ->first();

        // 已有可用译文时保留，不因一次失败而丢弃。
        if ($translation instanceof ArticleTranslation
            && $translation->translation_error === null
            && trim((string) $translation->content) !== '') {
            return null;
        }

        return $translation;
    }

    private function resolveModel(Article $article): ?AiModel
    {
        $modelId = (int) ($article->task?->ai_model_id ?? 0);
        if ($modelId > 0) {
            $model = AiModel::query()
                ->whereKey($modelId)
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->first();
            if ($model instanceof AiModel) {
                return $model;
            }
        }

        $configuredId = (int) (DB::table('site_settings')
            ->where('setting_key', 'translation_model_id')
            ->value('setting_value') ?? 0);
        if ($configuredId > 0) {
            $model = AiModel::query()
                ->whereKey($configuredId)
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->first();
            if ($model instanceof AiModel) {
                return $model;
            }
        }

        return AiModel::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->where(function ($query): void {
                $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first();
    }

    private function instructions(string $locale): string
    {
        $language = self::LOCALE_NAMES[$locale] ?? $locale;

        return '你是专业的本地化译者。把中文文章完整翻译成'.$language
            .'，保持 Markdown 结构、标题层级、表格、列表、数字、单位和链接不变。'
            .'只输出要求的字段，不要输出任何解释、前言、语言标签或翻译说明。'
            .'禁止出现 GEO、SEO、AIGC、LLM、AI Search、文档类型、内容类型等内部术语，'
            .'也不要出现 [K1]、【K1】 这类内部引用编号。';
    }

    /**
     * @param  array{title:string,excerpt:string,content:string,meta_description:string}  $source
     */
    private function buildPrompt(array $source, string $locale): string
    {
        $language = self::LOCALE_NAMES[$locale] ?? $locale;

        return <<<PROMPT
将以下中文文章翻译成{$language}，用于内容发布。

要求：
1. 保留 Markdown 结构与标题层级，不要增删章节。
2. 数字、价格、单位、车型名称、专有名词保持准确；必要时保留原文括注。
3. 译文要自然、专业、可发布，不要逐字直译。
4. 不要输出内部术语（GEO/SEO/AIGC/LLM/文档类型等），也不要输出引用编号。
5. 严格按以下分隔符输出四个部分，不要添加任何其他内容：

===TITLE===
[译好的标题，单行]

===EXCERPT===
[译好的摘要，2-3 句，纯文本]

===META===
[译好的 SEO 描述，一句话，不超过 160 字符]

===CONTENT===
[译好的完整 Markdown 正文]

待翻译内容：

标题：{$source['title']}

摘要：{$source['excerpt']}

正文：
{$source['content']}
PROMPT;
    }

    /**
     * @return array{title:string,excerpt:string,content:string,meta_description:string}|null
     */
    private function decode(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // 去掉模型泄漏的推理过程，只保留从第一个分隔符开始的结果。
        $start = strpos($text, '===TITLE===');
        if ($start !== false && $start > 0) {
            $text = substr($text, $start);
        }

        $text = preg_replace('/^```[a-zA-Z]*\s*\n/', '', $text) ?? $text;
        $text = preg_replace('/\n```\s*$/', '', $text) ?? $text;

        $parts = [
            'title' => $this->extractSection($text, 'TITLE', 'EXCERPT'),
            'excerpt' => $this->extractSection($text, 'EXCERPT', 'META'),
            'meta_description' => $this->extractSection($text, 'META', 'CONTENT'),
            'content' => $this->extractSection($text, 'CONTENT', null),
        ];

        if (trim($parts['content']) === '') {
            return null;
        }

        $parts['title'] = trim(preg_replace('/^\s*#+\s*/u', '', $parts['title']) ?? $parts['title']);

        return $parts;
    }

    private function extractSection(string $text, string $name, ?string $next): string
    {
        $start = '===\s*'.$name.'\s*===';
        $pattern = $next === null
            ? '/'.$start.'(.*)$/su'
            : '/'.$start.'(.*?)===\s*'.$next.'\s*===/su';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }
}
