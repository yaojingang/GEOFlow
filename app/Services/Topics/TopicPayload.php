<?php

namespace App\Services\Topics;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TopicPayload
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $data = Validator::make($input, [
            'matching_rules' => ['nullable', 'array'],
            'seo' => ['nullable', 'array:title,description'], 'seo.title' => ['nullable', 'string', 'max:200'], 'seo.description' => ['nullable', 'string', 'max:500'],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'array:one_sentence,facts,scope,reading_advice'],
            'summary.one_sentence' => ['nullable', 'string', 'max:2000'],
            'summary.scope' => ['nullable', 'string', 'max:2000'],
            'summary.reading_advice' => ['nullable', 'string', 'max:4000'],
            'summary.facts' => ['nullable', 'array', 'max:30'],
            'summary.facts.*' => ['array:text,article_ids,evidence'],
            'summary.facts.*.text' => ['required', 'string', 'max:2000'],
            'summary.facts.*.article_ids' => ['required', 'array', 'min:1'],
            'summary.facts.*.article_ids.*' => ['integer', 'min:1'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'source_overrides' => ['nullable', 'array:excluded_article_ids'],
            'source_overrides.excluded_article_ids' => ['nullable', 'array', 'max:1000'],
            'source_overrides.excluded_article_ids.*' => ['integer', 'min:1', 'distinct'],
            'articles' => ['nullable', 'array', 'max:200'],
            'articles.*' => ['array:article_id,group,reason'],
            'articles.*.article_id' => ['required', 'integer', 'min:1', 'distinct'],
            'articles.*.group' => ['nullable', 'string', 'max:100'],
            'articles.*.reason' => ['nullable', 'string', 'max:2000'],
            'template_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'basic_info' => ['nullable', 'array', 'max:30'],
            'basic_info.*' => ['array:label,value'],
            'basic_info.*.label' => ['required', 'string', 'max:100'],
            'basic_info.*.value' => ['required', 'string', 'max:2000'],
            'faq' => ['nullable', 'array', 'max:30'],
            'faq.*' => ['array:question,answer,article_ids'],
            'faq.*.question' => ['required', 'string', 'max:1000'],
            'faq.*.answer' => ['required', 'string', 'max:4000'],
            'faq.*.article_ids' => ['required', 'array', 'min:1'],
            'faq.*.article_ids.*' => ['integer', 'min:1'],
        ] + TopicFreshnessService::rules() + self::evidenceRules())->validate();

        $title = preg_replace('/^\s+|\s+$/u', '', $data['title']) ?? trim($data['title']);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => '请填写专题标题。']);
        }

        foreach ($data['summary']['facts'] ?? [] as $index => $fact) {
            foreach ($fact['evidence'] ?? [] as $position => $evidence) {
                if (! in_array((int) $evidence['article_id'], array_map('intval', $fact['article_ids']), true)) {
                    throw ValidationException::withMessages(['summary.facts.'.$index.'.evidence.'.$position.'.article_id' => '定位片段需要对应本条事实引用的文章。']);
                }
            }
        }

        $scoreValidator = Validator::make(['score' => $input['score'] ?? null], $this->scoreRules());
        $score = $scoreValidator->fails() ? null : ($scoreValidator->validated()['score'] ?? null);
        if ($score !== null) {
            $score['enabled'] = (bool) ($score['enabled'] ?? false);
        }

        return [
            'title' => $title,
            'seo' => ['title' => trim((string) ($data['seo']['title'] ?? '')), 'description' => trim((string) ($data['seo']['description'] ?? ''))],
            'intro' => trim((string) ($data['intro'] ?? '')),
            'summary' => [
                'one_sentence' => trim((string) ($data['summary']['one_sentence'] ?? '')),
                'facts' => array_map(static fn (array $fact): array => array_replace($fact, ['article_ids' => array_values(array_unique(array_map('intval', $fact['article_ids'])))]), $data['summary']['facts'] ?? []),
                'scope' => trim((string) ($data['summary']['scope'] ?? '')),
                'reading_advice' => trim((string) ($data['summary']['reading_advice'] ?? '')),
            ],
            'tags' => array_values($data['tags'] ?? []),
            'articles' => array_map(static fn (array $article): array => [
                'article_id' => (int) $article['article_id'],
                'group' => trim((string) ($article['group'] ?? '')),
                'reason' => trim((string) ($article['reason'] ?? '')),
            ], $data['articles'] ?? []),
            'matching_rules' => app(TopicMatchingRules::class)->normalize($input['matching_rules'] ?? []),
            'source_overrides' => ['excluded_article_ids' => array_values(array_map('intval', $data['source_overrides']['excluded_article_ids'] ?? []))],
            'template_key' => $data['template_key'] ?? 'default',
            'freshness' => app(TopicFreshnessService::class)->normalize($data['freshness'] ?? []),
            'basic_info' => array_values($data['basic_info'] ?? []),
            'score' => $score,
            'faq' => array_values($data['faq'] ?? []),
        ];
    }

    public static function evidenceRules(string $prefix = 'summary.facts.*.evidence'): array
    {
        return [$prefix => ['nullable', 'array', 'max:20'], $prefix.'.*' => ['array:article_id,field,start,end,sha256,text'], $prefix.'.*.article_id' => ['required', 'integer', 'min:1'], $prefix.'.*.field' => ['required', 'in:title,excerpt,content'], $prefix.'.*.start' => ['required', 'integer', 'min:0'], $prefix.'.*.end' => ['required', 'integer', 'gt:'.$prefix.'.*.start'], $prefix.'.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/Di'], $prefix.'.*.text' => ['required', 'string', 'max:2000']];
    }

    public static function titleKey(string $title): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title), 'UTF-8'));
    }

    /** Stable declared coverage distinguishes topics without using changing source IDs or review dates. */
    public static function topicKey(string $title, array $payload = []): string
    {
        $normalize = static fn (mixed $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value), 'UTF-8');
        $scope = $normalize($payload['summary']['scope'] ?? '');
        $freshness = $payload['freshness'] ?? [];
        $mode = $freshness['mode'] ?? 'evergreen';
        $coverage = match ($mode) {
            'annual' => ['year' => (int) ($freshness['year'] ?? 0)],
            'monthly' => ['year' => (int) ($freshness['year'] ?? 0), 'month' => (int) ($freshness['month'] ?? 0)],
            'version' => ['version' => $normalize($freshness['version'] ?? '')],
            default => [],
        };
        if (! in_array($mode, ['none', 'evergreen', 'composed_at'], true)) {
            $note = $normalize($freshness['coverage_note'] ?? '');
            if ($note !== '') {
                $coverage['scope_label'] = $note;
            }
        }
        if ($scope === '' && $coverage === []) {
            return self::titleKey($title);
        }

        return hash('sha256', json_encode([self::titleKey($title), $scope, $coverage], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $payload @param list<int> $articleIds */
    public function assertPublishable(array $payload, array $articleIds): void
    {
        if (trim((string) $payload['intro']) === '') {
            throw ValidationException::withMessages(['intro' => '发布专题前请填写导语。']);
        }
        if (count($articleIds) < 2) {
            throw ValidationException::withMessages(['articles' => '发布专题至少需要两篇当前站点可公开阅读的不同文章。']);
        }
        foreach (['summary.facts' => $payload['summary']['facts'] ?? [], 'faq' => $payload['faq'] ?? []] as $field => $facts) {
            $this->assertCitations($facts, $articleIds, $field);
        }
        app(TopicFreshnessService::class)->assertSearchClaims($payload);
        $freshness = $payload['freshness'];
        if ($freshness['mode'] !== 'none' && $freshness['valid_until'] !== null) {
            $timezone = $freshness['timezone'];
            $until = app(TopicFreshnessService::class)->dayBoundary($freshness['valid_until'], $timezone);
            $localNow = CarbonImmutable::now($timezone);
            if ($freshness['mode'] === 'recent' && $until->greaterThan($localNow->addDays(91)->startOfDay())) {
                throw ValidationException::withMessages(['freshness.valid_until' => '近期标记的有效期最长为 90 天，请缩短窗口或改用年度、月份或活动标记。']);
            }
            if ($until->lessThanOrEqualTo($localNow) || $until->greaterThan($localNow->addYear()->addDay()->startOfDay())) {
                throw ValidationException::withMessages(['freshness.valid_until' => '时效标记的有效期应从今天起，且最长为一年；请更新有效期或移除时效标记。']);
            }
            if (in_array($freshness['mode'], ['annual', 'monthly'], true) && $freshness['year'] !== null) {
                $month = $freshness['mode'] === 'monthly' ? $freshness['month'] : 12;
                if ($month !== null && $until->greaterThan(CarbonImmutable::create((int) $freshness['year'], (int) $month, 1, 0, 0, 0, $timezone)->addMonth())) {
                    throw ValidationException::withMessages(['freshness.valid_until' => '有效期不能超出所标记的年度或月份。']);
                }
            }
        }
    }

    /** @param array<string,mixed> $payload @param array<int|string,string> $sourceHashes */
    public static function ratingHash(array $payload, array $sourceHashes): string
    {
        ksort($sourceHashes);
        $substance = array_intersect_key($payload, array_flip(['title', 'intro', 'summary', 'tags', 'articles', 'freshness', 'basic_info', 'faq']));

        return hash('sha256', json_encode([$substance, $sourceHashes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $score @param list<int> $articleIds */
    public function validScoreData(array $score, array $articleIds, ?string $timezone = null): bool
    {
        if (! ($score['enabled'] ?? false)) {
            return false;
        }
        try {
            $score = Validator::make(['score' => $score], $this->scoreRules())->validate()['score'];
            $this->assertScore($score, $articleIds, $timezone);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload @param list<int> $articleIds @param array<string,mixed>|null $binding @param array<int|string,string> $sourceHashes @return array<string,mixed>|null */
    public function usableScore(array $payload, array $articleIds, ?array $binding, array $sourceHashes): ?array
    {
        $score = $payload['score'] ?? null;
        if (! ($score['enabled'] ?? false) || $binding === null
            || ! hash_equals((string) ($binding['baseline_hash'] ?? ''), self::ratingHash($payload, $sourceHashes))) {
            return null;
        }
        try {
            $score = Validator::make(['score' => $score], $this->scoreRules())->validate()['score'];
            $ratedAt = CarbonImmutable::parse($binding['rated_at']);
            if ($ratedAt->isFuture()) {
                return null;
            }
            $expiresAt = $this->scoreExpiresAt($payload, $ratedAt, isset($binding['expires_at']) ? null : ($binding['valid_until'] ?? null));
            if (isset($binding['expires_at'])) {
                $bindingBoundary = CarbonImmutable::parse($binding['expires_at']);
                if ($bindingBoundary->lessThan($expiresAt)) {
                    $expiresAt = $bindingBoundary;
                }
            }
            if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                return null;
            }
            $score['rated_at'] = $ratedAt->toIso8601String();
            $score['valid_until'] = $expiresAt->subMicrosecond()->setTimezone($payload['freshness']['timezone'] ?? config('app.timezone'))->toDateString();
            $score['expires_at'] = $expiresAt->toIso8601String();
            $this->assertScore($score, $articleIds, $payload['freshness']['timezone']);

            return $this->displayScore($score);
        } catch (ValidationException|InvalidFormatException|\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    public function scoreExpiresAt(array $payload, CarbonImmutable $ratedAt, ?string $bindingUntil = null): CarbonImmutable
    {
        $expiresAt = $ratedAt->addDays(90);
        $dates = [$payload['score']['valid_until'] ?? null, $bindingUntil];
        foreach ($payload['score']['evidence'] ?? [] as $evidence) {
            $dates[] = $evidence['valid_until'] ?? null;
        }
        if (($payload['freshness']['mode'] ?? 'none') !== 'none') {
            $dates[] = $payload['freshness']['valid_until'] ?? null;
        }
        foreach (array_filter($dates) as $date) {
            $boundary = app(TopicFreshnessService::class)->dayBoundary($date, $payload['freshness']['timezone'] ?? config('app.timezone'));
            if ($boundary->lessThan($expiresAt)) {
                $expiresAt = $boundary;
            }
        }

        $freshnessBoundary = app(TopicFreshnessService::class)->expirationBoundary($payload['freshness'] ?? []);
        if ($freshnessBoundary !== null && $freshnessBoundary->lessThan($expiresAt)) {
            $expiresAt = $freshnessBoundary;
        }

        return $expiresAt;
    }

    /** @return array<string,list<mixed>> */
    private function scoreRules(): array
    {
        return [
            'score' => ['nullable', 'array:enabled,type,source,name,dimensions,weights,total,evidence,valid_until,rated_at'],
            'score.enabled' => ['nullable', 'boolean'],
            'score.type' => ['nullable', Rule::in(['editorial', 'ai_assisted', 'external'])],
            'score.source' => ['nullable', 'string', 'max:1000'],
            'score.name' => ['nullable', 'string', 'max:255'],
            'score.dimensions' => ['nullable', 'array', 'max:30'],
            'score.dimensions.*' => ['array:name,score,max'],
            'score.dimensions.*.name' => ['required', 'string', 'max:100'],
            'score.dimensions.*.score' => ['required', 'numeric', 'between:0,10'],
            'score.dimensions.*.max' => ['nullable', 'numeric', 'in:10'],
            'score.weights' => ['nullable', 'array', 'max:30'],
            'score.weights.*' => ['numeric', 'min:0'],
            'score.total' => ['nullable', 'numeric', 'between:0,10'],
            'score.evidence' => ['nullable', 'array', 'max:30'],
            'score.evidence.*' => ['array:text,article_ids,valid_until'],
            'score.evidence.*.text' => ['required', 'string', 'max:2000'],
            'score.evidence.*.article_ids' => ['required', 'array', 'min:1'],
            'score.evidence.*.article_ids.*' => ['integer', 'min:1'],
            'score.evidence.*.valid_until' => ['nullable', 'date_format:Y-m-d'],
            'score.valid_until' => ['nullable', 'date_format:Y-m-d'],
            'score.rated_at' => ['nullable', 'date'],

        ];
    }

    /** @param array<string,mixed> $score @return array<string,mixed> */
    public function displayScore(array $score): array
    {
        $dimensions = array_values($score['dimensions']);
        $weights = $score['weights'];
        $totalWeight = array_sum($weights);
        $normalized = [];
        foreach ($dimensions as $index => $dimension) {
            $weight = $weights[$index] ?? $weights[$dimension['name']] ?? 0;
            $normalized[] = (float) $weight / $totalWeight;
        }

        return $score + [
            'max' => 10,
            'stars' => (float) $score['total'] / 2,
            'normalized_weights' => $normalized,
            'calculation' => '各维度均为 0–10 分，权重除以权重总和后加权计算。',
        ];
    }

    /** @param list<array<string,mixed>> $facts @param list<int> $articleIds */
    private function assertCitations(array $facts, array $articleIds, string $field): void
    {
        foreach ($facts as $index => $fact) {
            if (array_diff($fact['article_ids'], $articleIds) !== []) {
                throw ValidationException::withMessages([$field.'.'.$index.'.article_ids' => '引用文章必须属于专题，并且当前可在此站点公开阅读。']);
            }
        }
    }

    /** @param array<string,mixed> $score @param list<int> $articleIds */
    private function assertScore(array $score, array $articleIds, ?string $timezone = null): void
    {
        foreach (['source', 'name', 'dimensions', 'weights', 'evidence'] as $field) {
            if (empty($score[$field])) {
                throw ValidationException::withMessages(['score.'.$field => '启用评分时请填写评分来源、名称、维度、权重和文章证据。']);
            }
        }
        if (! isset($score['total'])) {
            throw ValidationException::withMessages(['score.total' => '请填写 0–10 分的总分。']);
        }
        $dimensions = array_values($score['dimensions']);
        $weights = $score['weights'];
        if (! is_finite((float) array_sum($weights)) || array_filter($weights, static fn (mixed $weight): bool => ! is_finite((float) $weight)) !== []) {
            throw ValidationException::withMessages(['score.weights' => '评分权重及权重总和应为有限数字，请缩小权重数值。']);
        }
        $names = array_column($dimensions, 'name');
        if (count($names) !== count(array_unique($names)) || count($weights) !== count($dimensions) || array_sum($weights) <= 0) {
            throw ValidationException::withMessages(['score.weights' => '每个维度需要一个非负权重，维度名称须唯一，且权重总和应大于零。']);
        }
        $calculated = 0.0;
        foreach ($dimensions as $index => $dimension) {
            $weight = $weights[$index] ?? $weights[$dimension['name']] ?? null;
            if ($weight === null) {
                throw ValidationException::withMessages(['score.weights' => '评分权重应按维度顺序填写，或使用对应的维度名称。']);
            }
            $calculated += (float) $dimension['score'] * (float) $weight / array_sum($weights);
        }
        if (abs($calculated - (float) $score['total']) > 0.05) {
            throw ValidationException::withMessages(['score.total' => '总分与维度加权计算不符，应为 '.round($calculated, 2).' 分。']);
        }
        if (isset($score['valid_until']) && app(TopicFreshnessService::class)->dayBoundary($score['valid_until'], $timezone ?? config('app.timezone'))->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['score.valid_until' => '评分已过期，请更新证据及有效期，或关闭评分。']);
        }
        $this->assertCitations($score['evidence'], $articleIds, 'score.evidence');
    }
}
