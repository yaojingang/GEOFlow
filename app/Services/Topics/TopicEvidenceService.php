<?php

namespace App\Services\Topics;

use App\Models\Article;
use App\Models\TopicBuildRun;
use App\Services\Site\SiteScopedArticleQuery;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Literal UTF-8 fragment locations accompany the independent semantic verification. */
final class TopicEvidenceService
{
    public function verificationMaterials(TopicBuildRun $run, array $draft, array $candidates): array
    {
        $selected = array_column($draft['articles'], 'article_id');
        $articles = app(SiteScopedArticleQuery::class)->queryForSiteKey($run->site_key)->useWritePdo()->whereIn('articles.id', $selected)->with(['task', 'latestRiskScan', 'latestTopicReview'])->get()->keyBy('id');
        foreach ($selected as $id) {
            $article = $articles->get($id);
            $candidate = collect($candidates)->firstWhere('article_id', $id);
            if (! $article || ! app(TopicService::class)->isEligibleScoped($article) || ! hash_equals((string) ($candidate['source_hash'] ?? ''), TopicService::contentHash($article))) {
                throw ValidationException::withMessages(['articles' => '选文在生成期间发生变化，请重新核对来源。']);
            }
        }
        $materials = [];
        $evidence = [];
        foreach ($articles as $id => $article) {
            $texts = [];
            $fragments = [];
            foreach ($draft['summary']['facts'] ?? [] as $index => $fact) {
                if (in_array((int) $id, array_map('intval', $fact['article_ids']), true)) {
                    $fragment = $this->fragment($article, (string) $fact['text']);
                    $evidence[$index][] = $fragment;
                    $fragments[$fragment['field'].':'.$fragment['start']] = $fragment;
                }
            }
            $query = implode(' ', [(string) $draft['intro'], (string) ($draft['summary']['one_sentence'] ?? ''), (string) ($draft['summary']['scope'] ?? ''), ...array_column($draft['articles'], 'reason'), ...array_column($draft['faq'] ?? [], 'answer'), ...array_column($draft['basic_info'] ?? [], 'value'), (string) ($draft['seo']['title'] ?? ''), (string) ($draft['seo']['description'] ?? '')]);
            $fragment = $this->fragment($article, $query);
            $fragments[$fragment['field'].':'.$fragment['start']] = $fragment;
            // Every fact locator is shown to the verifier, with bounded additional context.
            $materials[] = ['article_id' => (int) $id, 'title' => (string) $article->title, 'excerpt' => mb_substr((string) $article->excerpt, 0, 2000), 'supporting_fragments' => array_values($fragments)];
        }
        foreach ($draft['summary']['facts'] ?? [] as $index => $fact) {
            $draft['summary']['facts'][$index]['evidence'] = $evidence[$index] ?? [];
        }
        if (strlen(json_encode($materials, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 240000) {
            throw ValidationException::withMessages(['facts' => '事实依据超出本次校验容量，请减少事实或选文后重试。']);
        }

        return ['draft' => $draft, 'source_articles' => $materials];
    }

    public function assertCurrent(array $payload, Collection $articles): void
    {
        foreach ($payload['summary']['facts'] ?? [] as $index => $fact) {
            if (! $this->validFact($fact, $articles)) {
                throw ValidationException::withMessages(['summary.facts.'.$index.'.evidence' => '请为每篇事实来源补充有效原文片段；原文改变时请重新复制核对。']);
            }
        }
    }

    public function validFact(array $fact, Collection $articles): bool
    {
        $evidence = $fact['evidence'] ?? [];
        if ($evidence === [] || array_diff(array_map('intval', $fact['article_ids'] ?? []), array_column($evidence, 'article_id')) !== []) {
            return false;
        }
        foreach ($evidence as $fragment) {
            $article = $articles->firstWhere('id', (int) $fragment['article_id']);
            if (! $article || ! in_array((int) $fragment['article_id'], array_map('intval', $fact['article_ids'] ?? []), true)) {
                return false;
            }
            $field = $fragment['field'] ?? '';
            if (! in_array($field, ['title', 'excerpt', 'content'], true)) {
                return false;
            }
            $start = (int) ($fragment['start'] ?? -1);
            $end = (int) ($fragment['end'] ?? 0);
            $original = (string) $article->$field;
            if ($start < 0 || $end <= $start || $end > mb_strlen($original, 'UTF-8')) {
                return false;
            }
            $text = mb_substr($original, $start, $end - $start, 'UTF-8');
            $submittedText = (string) ($fragment['text'] ?? '');
            $sameText = str_replace(["\r\n", "\r"], "\n", $text) === str_replace(["\r\n", "\r"], "\n", $submittedText);
            if (! $sameText || ! hash_equals(hash('sha256', $text), strtolower((string) ($fragment['sha256'] ?? '')))) {
                return false;
            }
        }

        return true;
    }

    /** Resolve editor-supplied literal quotes within the current site's selected articles. */
    public function resolveQuotes(string $siteKey, array $input): array
    {
        foreach ($input['summary']['facts'] ?? [] as $index => $fact) {
            if (! array_key_exists('evidence_quotes_text', $fact)) {
                continue;
            }
            $quotes = trim((string) $fact['evidence_quotes_text']);
            unset($input['summary']['facts'][$index]['evidence_quotes_text']);
            if ($quotes === '') {
                continue;
            }
            $evidence = [];
            foreach (preg_split('/\R/u', $quotes) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (! preg_match('/^\s*(\d+)\s*[:：]\s*(.+)$/u', $line, $match)) {
                    throw ValidationException::withMessages(['summary.facts.'.$index.'.evidence' => '原文片段请按“文章编号：原文”填写，每篇一行。']);
                }
                $id = (int) $match[1];
                $text = trim($match[2]);
                if (mb_strlen($text) > 2000 || ! in_array($id, array_map('intval', $fact['article_ids'] ?? []), true)) {
                    throw ValidationException::withMessages(['summary.facts.'.$index.'.evidence' => '原文片段需对应本条引用文章，单段不超过 2000 字。']);
                }
                $article = app(SiteScopedArticleQuery::class)->queryForSiteKey($siteKey)->where('articles.id', $id)->first();
                $found = null;
                if ($article) {
                    foreach (['content', 'excerpt', 'title'] as $field) {
                        $start = mb_strpos((string) $article->$field, $text, 0, 'UTF-8');
                        if ($start !== false) {
                            $found = ['article_id' => $id, 'field' => $field, 'start' => $start, 'end' => $start + mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
                            break;
                        }
                    }
                }
                if ($found === null) {
                    throw ValidationException::withMessages(['summary.facts.'.$index.'.evidence' => '文章 #'.$id.' 中没有找到这段原文，请复制原文后重试。']);
                }
                $evidence[] = $found;
            }
            $input['summary']['facts'][$index]['evidence'] = $evidence;
        }

        return $input;
    }

    private function fragment(Article $article, string $query): array
    {
        $terms = app(TopicMatchingRules::class)->titleTerms(mb_substr($query, 0, 1000));
        preg_match_all('/[\p{Han}]{2,}/u', $query, $han);
        foreach ($han[0] ?? [] as $phrase) {
            for ($i = 0; $i < min(100, mb_strlen($phrase) - 1); $i += 2) {
                $terms[] = mb_substr($phrase, $i, 2);
            }
        }
        $terms = array_slice(array_values(array_unique($terms)), 0, 100);
        $field = 'content';
        $original = (string) $article->content;
        if (trim($original) === '') {
            $field = 'excerpt';
            $original = (string) $article->excerpt;
        }
        if (trim($original) === '') {
            $field = 'title';
            $original = (string) $article->title;
        }
        $starts = [0];
        foreach ($terms as $term) {
            $position = mb_stripos($original, $term, 0, 'UTF-8');
            if ($position !== false) {
                $starts[] = max(0, $position - 600);
            }
        }
        $best = 0;
        $bestScore = -1;
        foreach (array_unique($starts) as $start) {
            $window = mb_substr($original, $start, 1800, 'UTF-8');
            $score = 0;
            foreach ($terms as $term) {
                if (mb_stripos($window, $term, 0, 'UTF-8') !== false) {
                    $score++;
                }
            }if ($score > $bestScore) {
                $best = $start;
                $bestScore = $score;
            }
        }
        $text = mb_substr($original, $best, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => $field, 'start' => $best, 'end' => $best + mb_strlen($text, 'UTF-8'), 'sha256' => hash('sha256', $text), 'text' => $text];
    }
}
