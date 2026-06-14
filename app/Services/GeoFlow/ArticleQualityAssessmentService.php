<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use Illuminate\Support\Str;

class ArticleQualityAssessmentService
{
    /**
     * @return array{
     *   score:int,
     *   grade:string,
     *   status:string,
     *   detected_language:string,
     *   issues:list<array{key:string,severity:string,message_key:string}>,
     *   items:list<array{key:string,score:int,max:int,status:string,detail:string}>
     * }
     */
    public function assess(Article $article, array $generationTrace = []): array
    {
        $title = trim((string) $article->title);
        $content = trim((string) $article->content);
        $plain = $this->plainText($content);
        $keywords = $this->keywords((string) ($article->keywords ?: $article->original_keyword));
        $knowledge = is_array($generationTrace['knowledge'] ?? null) ? $generationTrace['knowledge'] : [];
        $traceImages = collect($generationTrace['images'] ?? [])->filter(fn ($image) => is_array($image))->values();
        $imageCount = $this->articleImageCount($article, $traceImages->count());
        $detectedLanguage = $this->detectLanguage($title."\n".$plain);
        $expectedLanguage = $this->expectedLanguage($generationTrace);

        $items = [
            $this->checkLanguage($title, $plain, $detectedLanguage, $expectedLanguage),
            $this->checkKnowledgeReference($knowledge),
            $this->checkKeywords($title, $plain, $keywords),
            $this->checkFactBasis($plain, $knowledge),
            $this->checkStructure($content, $plain),
            $this->checkFaq($content, $plain),
            $this->checkImages($imageCount, $traceImages->count(), (string) data_get($generationTrace, 'task.image_tag_filter', '')),
            $this->checkUniqueness($plain),
        ];

        $score = (int) collect($items)->sum('score');
        $issues = collect($items)
            ->filter(fn (array $item): bool => in_array($item['status'], ['warning', 'failed'], true))
            ->map(fn (array $item): array => [
                'key' => (string) $item['key'],
                'severity' => $item['status'] === 'failed' ? 'high' : 'medium',
                'message_key' => 'admin.articles.quality.suggestions.'.(string) $item['key'],
            ])
            ->values()
            ->all();

        return [
            'score' => max(0, min(100, $score)),
            'grade' => $this->grade($score),
            'status' => $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'poor'),
            'detected_language' => $detectedLanguage,
            'issues' => $issues,
            'items' => $items,
        ];
    }

    private function checkLanguage(string $title, string $plain, string $detectedLanguage, string $expectedLanguage): array
    {
        $sample = $title."\n".$plain;
        $han = preg_match_all('/\p{Han}/u', $sample);
        $latinWords = preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $sample);
        $mixed = $han > 20 && $latinWords > 40;
        $score = $mixed ? 7 : 15;
        $status = $score >= 12 ? 'passed' : 'warning';
        if ($expectedLanguage !== 'unknown') {
            if ($detectedLanguage === 'unknown') {
                $score = min($score, 8);
                $status = 'warning';
            } elseif ($this->primaryLanguage($detectedLanguage) !== $this->primaryLanguage($expectedLanguage)) {
                $score = 0;
                $status = 'failed';
            }
        }

        $detail = $expectedLanguage !== 'unknown'
            ? $detectedLanguage.' / expected '.$expectedLanguage
            : $detectedLanguage;

        return $this->item('language', $score, 15, $status, $detail);
    }

    private function checkKnowledgeReference(array $knowledge): array
    {
        $contextLength = (int) ($knowledge['context_length'] ?? 0);
        $chunks = collect($knowledge['chunks'] ?? [])->filter(fn ($chunk) => is_array($chunk))->count();
        $score = $contextLength > 300 || $chunks > 0 ? 15 : ($contextLength > 0 ? 8 : 0);

        return $this->item('knowledge', $score, 15, $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) $contextLength);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function checkKeywords(string $title, string $plain, array $keywords): array
    {
        if ($keywords === []) {
            return $this->item('keywords', 6, 15, 'warning', '0/0');
        }

        $haystack = mb_strtolower($title."\n".$plain, 'UTF-8');
        $matched = collect($keywords)
            ->filter(fn (string $keyword): bool => $keyword !== '' && Str::contains($haystack, mb_strtolower($keyword, 'UTF-8')))
            ->count();
        $ratio = $matched / max(1, count($keywords));
        $score = $ratio >= 0.8 ? 15 : ($ratio >= 0.4 ? 10 : ($matched > 0 ? 6 : 0));

        return $this->item('keywords', $score, 15, $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), $matched.'/'.count($keywords));
    }

    private function checkFactBasis(string $plain, array $knowledge): array
    {
        $entities = collect($knowledge['entities'] ?? [])->filter(fn ($entity) => is_array($entity))->count();
        $cases = collect($knowledge['cases'] ?? [])->filter(fn ($case) => is_array($case))->count();
        $chunks = collect($knowledge['chunks'] ?? [])->filter(fn ($chunk) => is_array($chunk))->count();
        $hasNumbers = preg_match('/\d+(?:[.,]\d+)?\s*(?:%|年|月|天|小时|kg|mm|cm|m|pcs|units|次|倍)?/u', $plain) === 1;
        $evidenceCount = $entities + $cases + $chunks + ($hasNumbers ? 1 : 0);
        $score = $evidenceCount >= 2 ? 15 : ($evidenceCount === 1 ? 9 : 0);

        return $this->item('facts', $score, 15, $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) $evidenceCount);
    }

    private function checkStructure(string $content, string $plain): array
    {
        $wordCount = $this->wordCount($plain);
        $headingCount = preg_match_all('/^\s{0,3}#{2,4}\s+\S+/mu', $content);
        $paragraphCount = collect(preg_split('/\R{2,}/u', $plain) ?: [])->map(fn ($p) => trim($p))->filter()->count();
        $score = 0;
        $score += $wordCount >= 500 ? 6 : ($wordCount >= 250 ? 4 : 1);
        $score += $headingCount >= 3 ? 5 : ($headingCount >= 1 ? 3 : 0);
        $score += $paragraphCount >= 4 ? 4 : ($paragraphCount >= 2 ? 2 : 0);

        return $this->item('structure', min(15, $score), 15, $score >= 12 ? 'passed' : ($score >= 7 ? 'warning' : 'failed'), (string) $wordCount);
    }

    private function checkFaq(string $content, string $plain): array
    {
        $hasFaqHeading = preg_match('/(?:FAQ|FAQs|Q&A|常见问题|问答|常见问答)/iu', $content) === 1;
        $questionMarks = preg_match_all('/[?？]/u', $plain);
        $score = $hasFaqHeading && $questionMarks >= 2 ? 10 : ($hasFaqHeading || $questionMarks >= 2 ? 6 : 0);

        return $this->item('faq', $score, 10, $score >= 8 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) $questionMarks);
    }

    private function checkImages(int $articleImageCount, int $traceImageCount, string $imageTagFilter): array
    {
        $count = max($articleImageCount, $traceImageCount);
        $score = $count > 0 ? 10 : 0;
        if ($score > 0 && $imageTagFilter === '') {
            $score = 8;
        }

        return $this->item('images', $score, 10, $score >= 8 ? 'passed' : 'failed', (string) $count);
    }

    private function checkUniqueness(string $plain): array
    {
        $paragraphs = collect(preg_split('/\R{2,}/u', $plain) ?: [])
            ->map(fn ($paragraph) => preg_replace('/\s+/u', ' ', trim((string) $paragraph)) ?: '')
            ->filter(fn (string $paragraph): bool => mb_strlen($paragraph, 'UTF-8') >= 20)
            ->values();
        if ($paragraphs->count() < 3) {
            return $this->item('uniqueness', 3, 5, 'warning', '0');
        }

        $duplicates = $paragraphs->count() - $paragraphs->unique()->count();
        $ratio = $duplicates / max(1, $paragraphs->count());
        $score = $ratio <= 0.05 ? 5 : ($ratio <= 0.2 ? 3 : 0);

        return $this->item('uniqueness', $score, 5, $score >= 5 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) round($ratio * 100));
    }

    private function plainText(string $content): string
    {
        $content = preg_replace('/```.*?```/su', ' ', $content) ?? $content;
        $content = strip_tags($content);
        $content = preg_replace('/[#>*_`\[\]()-]+/u', ' ', $content) ?? $content;
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

        return trim($content);
    }

    /**
     * @return list<string>
     */
    private function keywords(string $keywords): array
    {
        return collect(preg_split('/[,，;；、\n]+/u', $keywords) ?: [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique(fn (string $keyword) => mb_strtolower($keyword, 'UTF-8'))
            ->values()
            ->all();
    }

    private function detectLanguage(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $han = preg_match_all('/\p{Han}/u', $normalized);
        $latin = preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $normalized);
        if ($han > $latin * 0.8 && $han > 10) {
            return 'zh';
        }
        if ($latin <= 20) {
            return 'unknown';
        }

        return 'en';
    }

    private function expectedLanguage(array $generationTrace): string
    {
        $code = (string) data_get($generationTrace, 'language.code', '');
        $code = $this->normalizeLanguageCode($code);
        if ($code !== '') {
            return $code;
        }

        $title = (string) data_get($generationTrace, 'title.text', '');
        $keyword = (string) data_get($generationTrace, 'title.keyword', '');
        $detected = $this->detectLanguage($title."\n".$keyword);

        return $detected === 'unknown' ? 'unknown' : $detected;
    }

    private function normalizeLanguageCode(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));
        if ($code === '') {
            return '';
        }
        $aliases = [
            'zh' => 'zh',
            'zh-cn' => 'zh',
            'zh-hans' => 'zh',
            'zh-tw' => 'zh',
            'zh-hant' => 'zh',
            'en-us' => 'en',
            'en-gb' => 'en',
        ];

        $normalized = $aliases[$code] ?? (str_contains($code, '-') ? (string) strtok($code, '-') : $code);

        return in_array($normalized, ['zh', 'en'], true) ? $normalized : '';
    }

    private function primaryLanguage(string $code): string
    {
        return $this->normalizeLanguageCode($code) ?: $code;
    }

    private function wordCount(string $plain): int
    {
        $latinWords = preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9\'-]*\b/u', $plain);
        $han = preg_match_all('/\p{Han}/u', $plain);

        return max($latinWords, (int) ceil($han / 2));
    }

    private function articleImageCount(Article $article, int $fallback): int
    {
        if (isset($article->article_images_count)) {
            return (int) $article->article_images_count;
        }
        if ($article->relationLoaded('articleImages')) {
            return $article->articleImages->count();
        }

        return $fallback;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'E',
        };
    }

    /**
     * @return array{key:string,score:int,max:int,status:string,detail:string}
     */
    private function item(string $key, int $score, int $max, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'score' => max(0, min($max, $score)),
            'max' => $max,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
