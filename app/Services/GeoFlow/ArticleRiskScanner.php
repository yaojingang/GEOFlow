<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleRiskScan;
use App\Models\SensitiveWord;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JsonException;
use LengthException;
use RuntimeException;

class ArticleRiskScanner
{
    public const SCAN_ALGORITHM_VERSION = '4';

    public const MAX_CONTENT_CHARACTERS = 200000;

    public const MAX_EXCERPT_CHARACTERS = 10000;

    public const MAX_RULE_COUNT = 1000;

    private const RULE_CACHE_KEY_PREFIX = 'geoflow.article-risk-scanner.rules.v2.';

    private const FIELDS = [
        'title',
        'excerpt',
        'content',
        'keywords',
        'meta_description',
    ];

    private const FIELD_LIMITS = [
        'title' => 255,
        'excerpt' => self::MAX_EXCERPT_CHARACTERS,
        'content' => self::MAX_CONTENT_CHARACTERS,
        'keywords' => 500,
        'meta_description' => 500,
    ];

    /**
     * @param  array<string, mixed>  $content
     * @return array{status: string, match_count: int, matches: array<int, array{word: string, field: string, count: int, severity: string, category: string, suggestion: ?string, snippet: string}>, content_hash: string, dictionary_hash: string}
     *
     * @throws JsonException
     */
    public function scan(array $content): array
    {
        $normalizedContent = $this->normalizedContent($content);
        $fieldSurfaces = [];

        foreach (self::FIELDS as $field) {
            $fieldSurfaces[$field] = $this->scanSurfaces($field, $normalizedContent[$field]);
        }

        $rules = $this->rules();
        $matches = [];
        $matchCount = 0;
        $status = 'clean';

        foreach ($rules as $rule) {
            $normalizedWord = $this->normalize((string) $rule['word']);
            $needle = $this->fold($normalizedWord);
            $severity = $this->fold((string) $rule['severity']);
            $needles = [$needle];

            if ($severity === 'blocked') {
                $compactNeedle = $this->fold($this->withoutVisibleSeparators($normalizedWord));
                if ($compactNeedle !== '' && $compactNeedle !== $needle) {
                    $needles[] = $compactNeedle;
                } elseif ($compactNeedle !== '') {
                    $needles = [$compactNeedle];
                }
            }

            if ($needle === '') {
                continue;
            }

            $fields = $rule['applies_to'] === [] ? self::FIELDS : $rule['applies_to'];

            foreach (self::FIELDS as $field) {
                if (! in_array($field, $fields, true)) {
                    continue;
                }

                $matchedSurface = null;
                $matchedFolded = null;
                $matchedNeedle = null;
                $count = 0;

                foreach ($fieldSurfaces[$field] as $surface) {
                    $foldedSurfaces = [$surface['folded']];
                    if ($severity === 'blocked') {
                        $foldedSurfaces[] = $surface['compact_folded'];
                    }

                    foreach ($foldedSurfaces as $foldedSurface) {
                        foreach ($needles as $candidateNeedle) {
                            $surfaceCount = substr_count($foldedSurface['value'], $candidateNeedle);
                            if ($surfaceCount > $count) {
                                $count = $surfaceCount;
                                $matchedSurface = $surface;
                                $matchedFolded = $foldedSurface;
                                $matchedNeedle = $candidateNeedle;
                            }
                        }
                    }
                }

                if ($count === 0 || $matchedSurface === null || $matchedFolded === null || $matchedNeedle === null) {
                    continue;
                }

                $matchCount += $count;
                $status = $severity === 'blocked' ? 'blocked' : ($status === 'clean' ? 'warning' : $status);
                $matches[] = [
                    'word' => (string) $rule['word'],
                    'field' => $field,
                    'count' => $count,
                    'severity' => (string) $rule['severity'],
                    'category' => (string) $rule['category'],
                    'suggestion' => $rule['suggestion'],
                    'snippet' => $this->snippet(
                        $matchedSurface['value'],
                        $matchedFolded['value'],
                        $matchedNeedle,
                        $matchedFolded['original_offsets']
                    ),
                ];
            }
        }

        return [
            'status' => $status,
            'match_count' => $matchCount,
            'matches' => $matches,
            'content_hash' => $this->hash($normalizedContent),
            'dictionary_hash' => $this->dictionaryHash($rules),
        ];
    }

    /** @throws JsonException */
    public function record(Article $article, string $trigger, ?int $adminId = null): ArticleRiskScan
    {
        $result = $this->scan($this->articleContent($article));

        return $article->riskScans()->create([
            ...$result,
            'trigger' => $trigger,
            'admin_id' => $adminId,
            'scanned_at' => now(),
        ]);
    }

    /** @throws JsonException */
    public function isFresh(Article $article, ArticleRiskScan $scan): bool
    {
        if ((int) $scan->article_id !== (int) $article->getKey()) {
            return false;
        }

        $contentHash = $this->contentHash($this->articleContent($article));

        return hash_equals($scan->content_hash, $contentHash)
            && hash_equals($scan->dictionary_hash, $this->dictionaryHash($this->rules()));
    }

    public function clearRuleCache(): void
    {
        SensitiveWord::bumpRuleCacheVersion();
    }

    /** @param array<string, mixed> $content */
    public function contentHash(array $content): string
    {
        return $this->hash($this->normalizedContent($content));
    }

    /**
     * @return array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>}>
     */
    private function rules(): array
    {
        $cacheKey = self::RULE_CACHE_KEY_PREFIX.SensitiveWord::ruleCacheVersion();

        return Cache::remember($cacheKey, now()->addHour(), function (): array {
            $rules = SensitiveWord::query()
                ->where('is_enabled', true)
                ->orderBy('word')
                ->orderBy('id')
                ->limit(self::MAX_RULE_COUNT + 1)
                ->get(['word', 'severity', 'category', 'suggestion', 'applies_to'])
                ->map(function (SensitiveWord $rule): array {
                    $appliesTo = array_values(array_intersect(
                        self::FIELDS,
                        is_array($rule->applies_to) ? $rule->applies_to : []
                    ));

                    return [
                        'word' => (string) $rule->word,
                        'severity' => (string) $rule->severity,
                        'category' => (string) $rule->category,
                        'suggestion' => $rule->suggestion === null ? null : (string) $rule->suggestion,
                        'applies_to' => $appliesTo,
                    ];
                })
                ->all();

            if (count($rules) > self::MAX_RULE_COUNT) {
                throw new RuntimeException('Active sensitive-word rules exceed the supported scan limit.');
            }

            return $rules;
        });
    }

    /**
     * @return list<array{value: string, folded: array{value: string, original_offsets: array<int, int>}, compact_folded: array{value: string, original_offsets: array<int, int>}}>
     */
    private function scanSurfaces(string $field, string $value): array
    {
        $surfaces = [$value];
        $visible = $this->visibleText($field, $value);

        if ($visible !== '' && $visible !== $value) {
            $surfaces[] = $visible;
        }

        return array_map(fn (string $surface): array => [
            'value' => $surface,
            'folded' => $this->foldWithOriginalOffsetMap($surface),
            'compact_folded' => $this->compactFoldWithOriginalOffsetMap($surface),
        ], $surfaces);
    }

    private function visibleText(string $field, string $value): string
    {
        if ($field === 'content') {
            $value = ArticleHtmlPresenter::markdownToHtml($value);
            $value = preg_replace('/<\/?(?:address|article|aside|blockquote|br|div|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tr|ul)\b[^>]*>/iu', ' ', $value) ?? $value;
            $value = strip_tags($value);
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\p{Default_Ignorable_Code_Point}+/u', '', $value) ?? $value;

        return $this->normalize($value);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{title: string, excerpt: string, content: string, keywords: string, meta_description: string}
     */
    private function normalizedContent(array $content): array
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            $value = $content[$field] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            if (mb_strlen($value, 'UTF-8') > self::FIELD_LIMITS[$field]) {
                throw new LengthException("Article risk field [{$field}] exceeds the supported scan limit.");
            }
            $normalized[$field] = $this->normalize($value);
        }

        return $normalized;
    }

    /**
     * @return array{title: mixed, excerpt: mixed, content: mixed, keywords: mixed, meta_description: mixed}
     */
    private function articleContent(Article $article): array
    {
        return [
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content' => $article->content,
            'keywords' => $article->keywords,
            'meta_description' => $article->meta_description,
        ];
    }

    private function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }

        return Str::squish($value);
    }

    private function fold(string $value): string
    {
        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * @return array{value: string, original_offsets: array<int, int>}
     */
    private function foldWithOriginalOffsetMap(string $value): array
    {
        $originalOffsets = [];
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $originalOffset => $character) {
            $foldedLength = mb_strlen($this->fold($character), 'UTF-8');

            for ($foldedOffset = 0; $foldedOffset < $foldedLength; $foldedOffset++) {
                $originalOffsets[] = $originalOffset;
            }
        }

        return [
            'value' => $this->fold($value),
            'original_offsets' => $originalOffsets,
        ];
    }

    /**
     * @return array{value: string, original_offsets: array<int, int>}
     */
    private function compactFoldWithOriginalOffsetMap(string $value): array
    {
        $foldedValue = '';
        $originalOffsets = [];
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $originalOffset => $character) {
            if (preg_match('/^[\p{Z}\p{P}]$/u', $character) === 1) {
                continue;
            }

            $foldedCharacter = $this->fold($character);
            $foldedValue .= $foldedCharacter;

            for ($foldedOffset = 0; $foldedOffset < mb_strlen($foldedCharacter, 'UTF-8'); $foldedOffset++) {
                $originalOffsets[] = $originalOffset;
            }
        }

        return [
            'value' => $foldedValue,
            'original_offsets' => $originalOffsets,
        ];
    }

    private function withoutVisibleSeparators(string $value): string
    {
        return preg_replace('/[\p{Z}\p{P}]+/u', '', $value) ?? $value;
    }

    /** @param array<int, int> $originalOffsets */
    private function snippet(string $value, string $foldedValue, string $foldedNeedle, array $originalOffsets): string
    {
        $position = mb_strpos($foldedValue, $foldedNeedle, 0, 'UTF-8');

        if ($position === false) {
            return Str::limit($value, 120);
        }

        $foldedMatchEnd = $position + mb_strlen($foldedNeedle, 'UTF-8') - 1;
        $originalMatchStart = $originalOffsets[$position];
        $originalMatchEnd = $originalOffsets[$foldedMatchEnd];
        $originalMatchLength = $originalMatchEnd - $originalMatchStart + 1;
        $start = max(0, $originalMatchStart - 40);
        $snippet = mb_substr($value, $start, $originalMatchLength + 80, 'UTF-8');

        return ($start > 0 ? '…' : '').$snippet.(($start + mb_strlen($snippet, 'UTF-8')) < mb_strlen($value, 'UTF-8') ? '…' : '');
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>}>  $rules
     */
    private function dictionaryHash(array $rules): string
    {
        return $this->hash([
            'scanner_version' => self::SCAN_ALGORITHM_VERSION,
            'rules' => $rules,
        ]);
    }
}
