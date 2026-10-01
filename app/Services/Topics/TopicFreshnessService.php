<?php

namespace App\Services\Topics;

use App\Models\Article;
use App\Models\Topic;
use App\Models\TopicRevision;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TopicFreshnessService
{
    public const MODES = ['evergreen', 'composed_at', 'annual', 'version', 'as_of', 'event', 'rolling', 'none', 'monthly', 'recent'];

    /** @return array<string, mixed> */
    public static function rules(string $prefix = 'freshness'): array
    {
        return [
            $prefix => ['nullable', 'array:mode,year,month,version,coverage_note,valid_until,timezone,effective_from,effective_to,public_from,last_verified_at,next_review_at,title_template,expiry_action,public_updates'],
            $prefix.'.mode' => ['nullable', Rule::in(self::MODES)],
            $prefix.'.year' => ['nullable', 'integer', 'between:2000,2100'],
            $prefix.'.month' => ['nullable', 'integer', 'between:1,12'],
            $prefix.'.version' => ['nullable', 'string', 'max:100'],
            $prefix.'.coverage_note' => ['nullable', 'string', 'max:2000'],
            $prefix.'.timezone' => ['nullable', 'string', 'max:100'],
            $prefix.'.valid_until' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'.effective_from' => ['nullable', 'string', 'max:40'],
            $prefix.'.effective_to' => ['nullable', 'string', 'max:40'],
            $prefix.'.public_from' => ['nullable', 'string', 'max:40'],
            $prefix.'.last_verified_at' => ['nullable', 'string', 'max:40'],
            $prefix.'.next_review_at' => ['nullable', 'string', 'max:40'],
            $prefix.'.title_template' => ['nullable', 'string', 'max:500'],
            $prefix.'.expiry_action' => ['nullable', Rule::in(['suppress_claims', 'historical'])],
            $prefix.'.public_updates' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $data = Validator::make(['freshness' => $input], self::rules())->validate()['freshness'];
        $normalized = array_replace([
            'mode' => 'evergreen', 'year' => null, 'month' => null, 'version' => null,
            'coverage_note' => '', 'valid_until' => null, 'timezone' => 'Asia/Shanghai',
            'effective_from' => null, 'effective_to' => null, 'public_from' => null,
            'last_verified_at' => null, 'next_review_at' => null, 'title_template' => '',
            'expiry_action' => 'suppress_claims', 'public_updates' => '',
        ], array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== ''));
        foreach (['year', 'month'] as $key) {
            $normalized[$key] = isset($normalized[$key]) ? (int) $normalized[$key] : null;
        }
        $timezone = $normalized['timezone'];
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw ValidationException::withMessages(['freshness.timezone' => '请填写有效的 IANA 时区，例如 Asia/Shanghai。']);
        }
        $dates = $this->dates($normalized);
        if ($dates['effective_from'] !== null && $dates['effective_to'] !== null
            && $dates['effective_to']->lessThanOrEqualTo($dates['effective_from'])) {
            throw ValidationException::withMessages(['freshness.effective_to' => '有效期结束应晚于开始时间；结束边界采用排他区间。']);
        }
        $template = $normalized['title_template'];
        if ($template !== '' && ! str_contains($template, '{title}')) {
            throw ValidationException::withMessages(['freshness.title_template' => '标题模板须包含 {title}，以保留完整专题名称。']);
        }
        $allowedTokens = match ($normalized['mode']) {
            'annual' => ['title', 'year'], 'monthly' => ['title', 'year', 'month'],
            'version' => ['title', 'version'], 'as_of' => ['title', 'as_of'],
            'composed_at' => ['title', 'composed_at'], 'event' => ['title', 'year', 'month'], default => ['title'],
        };
        preg_match_all('/\{([^}]+)\}/u', $template, $tokens);
        if (array_diff($tokens[1], $allowedTokens) !== [] || preg_match('/(?<!\d)\d{4}(?!\d)/u', $template)) {
            throw ValidationException::withMessages(['freshness.title_template' => '标题时间和版本须由当前模式的受控变量生成。']);
        }
        if (preg_match('/最新|最全|第一/u', $template)
            || preg_match('/\{(?!title\}|year\}|month\}|version\}|as_of\}|composed_at\})[^}]*\}/u', $template)) {
            throw ValidationException::withMessages(['freshness.title_template' => '请使用受控标题变量，去除缺少人工证据确认的比较性宣称。']);
        }

        if ((preg_match('/持续更新|持续维护|动态更新|实时更新|continuously\s+updated|live\s+updates?/iu', $template) && $normalized['mode'] !== 'rolling')
            || (preg_match('/截至|截止|as\s+of|\bverified\b|(?<!可)复核|(?<!可)核验/iu', $template) && $normalized['mode'] === 'composed_at')
            || (preg_match('/近期|recent/iu', $template) && ! in_array($normalized['mode'], ['recent', 'rolling'], true))) {
            throw ValidationException::withMessages(['freshness.title_template' => '标题文案须与时效模式一致：持续更新需要复核记录和下一次复核时间，整理时间请使用“整理”。']);
        }

        return $normalized;
    }

    public function assertSearchClaims(array $payload): void
    {
        $freshness = $payload['freshness'] ?? [];
        $mode = $freshness['mode'] ?? 'evergreen';
        foreach (['title', 'description'] as $key) {
            $text = trim((string) ($payload['seo'][$key] ?? ''));
            if ($text === '') {
                continue;
            }
            $invalid = preg_match('/最新|最全|第一|\blatest\b|\bbest\b|\bnumber\s*one\b/iu', $text) === 1;
            if (preg_match('/持续更新|持续维护|动态更新|实时更新|continuously\s+updated|live\s+updates?/iu', $text) && $mode !== 'rolling') {
                $invalid = true;
            }
            if (preg_match('/截至|截止|as\s+of|\bverified\b|(?<!可)复核|(?<!可)核验/iu', $text) && (empty($freshness['last_verified_at']) || $mode === 'composed_at')) {
                $invalid = true;
            }
            if (preg_match('/近期|recent/iu', $text) && ! in_array($mode, ['recent', 'rolling'], true)) {
                $invalid = true;
            }
            preg_match_all('/(?<!\d)(\d{4})(?!\d)/u', $text, $years);
            foreach ($years[1] as $year) {
                if ((int) $year > (int) now($freshness['timezone'] ?? 'Asia/Shanghai')->format('Y') && (! in_array($mode, ['annual', 'monthly', 'event'], true) || (int) $year !== (int) ($freshness['year'] ?? 0))) {
                    $invalid = true;
                }
            }
            if (preg_match('/截至|截止|as\s+of|\bverified\b|(?<!可)复核|(?<!可)核验/iu', $text) && ! empty($freshness['last_verified_at'])) {
                try {
                    $verifiedDate = CarbonImmutable::parse($freshness['last_verified_at'])->setTimezone($freshness['timezone'] ?? 'Asia/Shanghai');
                    $verifiedYear = (int) $verifiedDate->format('Y');
                    preg_match_all('/(?<!\d)(\d{4})\s*[-\/.年]\s*(\d{1,2})(?:\s*[-\/.月]\s*(\d{1,2}))?/u', $text, $claims, PREG_SET_ORDER);
                    foreach ($claims as $claim) {
                        $year = (int) $claim[1];
                        $month = (int) $claim[2];
                        $day = (int) ($claim[3] ?? 1);
                        if (! checkdate($month, $day, $year) || CarbonImmutable::create($year, $month, $day, 0, 0, 0, $verifiedDate->timezoneName)->greaterThan($verifiedDate->startOfDay())) {
                            $invalid = true;
                        }
                    }
                    foreach ($years[1] as $year) {
                        if ((int) $year > $verifiedYear) {
                            $invalid = true;
                        }
                    }
                } catch (Throwable) {
                    $invalid = true;
                }
            }
            if ($invalid) {
                throw ValidationException::withMessages(['seo.'.$key => '搜索文案中的时间、核验和比较宣称需要与专题实际依据一致，请调整文案或时效设置。']);
            }
        }
    }

    /** @param array<string, mixed> $freshness @param Collection<int, Article> $articles @return array<string, mixed> */
    public function snapshot(array $freshness, Collection $articles, ?CarbonImmutable $composedAt = null): array
    {
        $freshness = $this->normalize($freshness);
        $dates = $this->dates($freshness);
        $snapshot = $freshness;
        foreach ($dates as $field => $date) {
            $snapshot[$field] = $date?->utc()->toIso8601String();
        }
        $snapshot['composed_at'] = $composedAt?->utc()->toIso8601String();
        try {
            $this->assertConfiguration($freshness);
            $snapshot['supported'] = $this->hasBasis($snapshot, $articles);
        } catch (ValidationException) {
            $snapshot['supported'] = false;
        }
        $snapshot['source_article_ids'] = $articles->modelKeys();
        $snapshot['frozen_at'] = CarbonImmutable::now('UTC')->toIso8601String();

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function evaluate(array $snapshot, ?CarbonImmutable $clock = null): array
    {
        $clock ??= CarbonImmutable::now('UTC');
        $mode = $snapshot['mode'] ?? null;
        $state = 'unknown';
        $from = $to = $review = $verified = $publicFrom = null;
        try {
            if (! in_array($mode, self::MODES, true) || ! ($snapshot['supported'] ?? false)) {
                throw new \InvalidArgumentException;
            }
            $policy = $this->normalize($this->policyInputs($snapshot));
            $parsed = $this->dates($policy);
            $from = $parsed['effective_from'];
            $to = $parsed['effective_to'];
            $review = $parsed['next_review_at'];
            $verified = $parsed['last_verified_at'];
            $publicFrom = $parsed['public_from'];
            $required = match ($mode) {
                'annual' => ['year', 'effective_to'], 'monthly' => ['year', 'month', 'effective_to'],
                'version' => ['version', 'effective_to'], 'as_of' => ['last_verified_at', 'effective_to'],
                'event' => empty($policy['valid_until']) ? ['effective_from', 'effective_to'] : ['effective_to'],
                'rolling' => ['last_verified_at', 'next_review_at', 'public_updates'],
                'recent' => ['effective_to'], 'composed_at' => ['composed_at'], default => [],
            };
            if (! in_array($mode, ['evergreen', 'none', 'composed_at'], true)) {
                $required[] = 'coverage_note';
            }
            foreach ($required as $field) {
                if (empty($parsed[$field] ?? $snapshot[$field] ?? null)) {
                    throw new \InvalidArgumentException;
                }
            }
            if ($verified !== null && $verified->greaterThan($clock)) {
                throw new \InvalidArgumentException;
            }
            if ($mode === 'composed_at') {
                $this->date($snapshot['composed_at'], $policy['timezone'], 'composed_at', false);
            }
            if (in_array($mode, ['evergreen', 'none', 'composed_at'], true)) {
                $state = 'evergreen';
            } elseif (($to !== null && $clock->greaterThanOrEqualTo($to))
                || ($mode === 'rolling' && (($review !== null && $clock->greaterThanOrEqualTo($review))
                    || ($verified !== null && $clock->greaterThanOrEqualTo($verified->addDays(90)))))) {
                $state = 'expired';
            } elseif ($from !== null && $clock->lessThan($from)) {
                $state = 'scheduled';
            } else {
                $state = 'active';
            }
        } catch (Throwable) {
            $state = 'unknown';
        }
        $eventHint = $mode === 'event' && $state !== 'unknown' ? match (true) {
            $to !== null && $clock->greaterThanOrEqualTo($to) => '结束时间已过',
            $from !== null && $clock->lessThan($from) => '开始时间未到',
            default => '活动时段内',
        } : null;
        $boundaries = array_filter([$from, $to, $review, $mode === 'rolling' ? $verified?->addDays(90) : null, $publicFrom], fn ($date): bool => $date !== null && $date->greaterThan($clock));
        usort($boundaries, static fn ($a, $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return [
            'freshness_status' => $state, 'event_hint' => $eventHint,
            'public_allowed' => $publicFrom === null || $clock->greaterThanOrEqualTo($publicFrom),
            'suppress_claims' => in_array($state, ['unknown', 'expired'], true),
            'next_transition_at' => ($boundaries[0] ?? null)?->toIso8601String(),
            'coverage_note' => $snapshot['coverage_note'] ?? '',
            'message' => match ($state) {
                'unknown' => '时效依据待核验，当前展示基础标题与来源文章。',
                'expired' => ($eventHint ? $eventHint.'。' : '').'此版本覆盖期已到期，来源文章按历史资料保留。',
                'scheduled' => $eventHint ?? '有效期开始时间未到。',
                default => $eventHint,
            },
        ];
    }

    /** @param array<string, mixed> $policy */
    public function assertConfiguration(array $policy): void
    {
        $policy = $this->normalize($policy);
        $mode = $policy['mode'];
        if (in_array($mode, ['evergreen', 'none', 'composed_at'], true)) {
            return;
        }
        $dates = $this->dates($policy);
        $required = match ($mode) {
            'annual' => ['year', 'effective_to'], 'monthly' => ['year', 'month', 'effective_to'],
            'version' => ['version', 'effective_to'], 'as_of' => ['last_verified_at', 'effective_to'],
            'event' => empty($policy['valid_until']) ? ['effective_from', 'effective_to'] : ['effective_to'],
            'rolling' => ['last_verified_at', 'next_review_at', 'public_updates'],
            default => ['effective_to'],
        };
        preg_match_all('/\{(year|month|version|as_of)\}/u', $policy['title_template'], $titleTokens);
        foreach ($titleTokens[1] as $token) {
            $required[] = $token === 'as_of' ? 'last_verified_at' : $token;
        }
        foreach (['coverage_note', ...$required] as $field) {
            if (empty($dates[$field] ?? $policy[$field] ?? null)) {
                throw ValidationException::withMessages(['freshness.'.$field => '此时效模式需要真实的覆盖范围、日期或版本，请补充该字段。']);
            }
        }
        if ($dates['last_verified_at']?->isFuture()) {
            throw ValidationException::withMessages(['freshness.last_verified_at' => '实际复核时间不能晚于当前时间。']);
        }
        if ($mode === 'rolling' && $dates['last_verified_at']->lessThanOrEqualTo(now()->subDays(90))) {
            throw ValidationException::withMessages(['freshness.last_verified_at' => '持续更新需要最近 90 天内的真实内容复核。']);
        }
        if ($mode === 'rolling' && $dates['next_review_at']->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['freshness.next_review_at' => '请约定未来的下一次内容复核时间。']);
        }
        if (in_array($mode, ['annual', 'monthly'], true)) {
            $boundary = CarbonImmutable::create($policy['year'], $mode === 'monthly' ? $policy['month'] : 1, 1, 0, 0, 0, $policy['timezone']);
            $boundary = $mode === 'monthly' ? $boundary->addMonth() : $boundary->addYear();
            if ($dates['effective_to']->greaterThan($boundary)) {
                throw ValidationException::withMessages(['freshness.effective_to' => '有效期不能超出来源覆盖的年度或月份。']);
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function assertPublishable(array $snapshot, bool $checkPublicFrom = true): void
    {
        $state = $this->evaluate($snapshot);
        if ($state['freshness_status'] === 'unknown') {
            throw ValidationException::withMessages(['freshness.coverage_note' => '时效版本缺少日期、版本或来源覆盖依据，请补充核验信息。']);
        }
        if ($state['freshness_status'] === 'expired') {
            throw ValidationException::withMessages(['freshness.effective_to' => '此时效版本已到期，请重新核验并发布新版本。']);
        }
        if ($checkPublicFrom && ! $state['public_allowed']) {
            throw ValidationException::withMessages(['freshness.public_from' => '允许公开时间尚未到达：'.$snapshot['public_from'].'。工作草稿已保留。']);
        }
        if ($checkPublicFrom && $state['freshness_status'] === 'scheduled' && $snapshot['mode'] !== 'event') {
            throw ValidationException::withMessages(['freshness.effective_from' => '有效期尚未开始，请在允许时间发布此版本。']);
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function title(string $base, array $snapshot, string $status): string
    {
        if (in_array($status, ['unknown', 'expired'], true)) {
            return $base;
        }
        $timezone = $snapshot['timezone'] ?? 'Asia/Shanghai';
        $format = static fn (?string $date): string => $date ? CarbonImmutable::parse($date)->setTimezone($timezone)->format('Y年n月') : '';
        $template = $snapshot['title_template'] ?: match ($snapshot['mode']) {
            'annual' => '{year}年 {title}', 'monthly' => '{year}年{month}月 {title}',
            'version' => '{title}（{version}版）', 'as_of' => '截至{as_of}的{title}',
            'composed_at' => '{composed_at}整理：{title}', 'rolling' => '{title}：持续更新',
            'recent' => '近期 {title}', default => '{title}',
        };

        return trim(strtr($template, ['{title}' => $base, '{year}' => (string) ($snapshot['year'] ?? ''),
            '{month}' => (string) ($snapshot['month'] ?? ''), '{version}' => (string) ($snapshot['version'] ?? ''),
            '{as_of}' => $format($snapshot['last_verified_at'] ?? null), '{composed_at}' => $format($snapshot['composed_at'] ?? null)]));
    }

    /** @param array<string, mixed> $freshness */
    public function publicFromReached(array $freshness): bool
    {
        try {
            $normalized = $this->normalize($this->policyInputs($freshness));
            $date = $this->dates($normalized)['public_from'];

            $from = $this->dates($normalized)['effective_from'];

            return ($date === null || CarbonImmutable::now('UTC')->greaterThanOrEqualTo($date))
                && ($normalized['mode'] === 'event' || $from === null || CarbonImmutable::now('UTC')->greaterThanOrEqualTo($from));
        } catch (ValidationException) {
            return false;
        }
    }

    /** @param array<string, mixed> $policy */
    public function expirationBoundary(array $policy): ?CarbonImmutable
    {
        $policy = $this->normalize($this->policyInputs($policy));
        if (in_array($policy['mode'], ['evergreen', 'none', 'composed_at'], true)) {
            return null;
        }
        $dates = $this->dates($policy);
        $boundaries = array_filter([$dates['effective_to'], $policy['mode'] === 'rolling' ? $dates['next_review_at'] : null,
            $policy['mode'] === 'rolling' ? $dates['last_verified_at']?->addDays(90) : null]);
        usort($boundaries, static fn ($a, $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $boundaries[0] ?? null;
    }

    public function recordState(Topic $topic, TopicRevision $revision, array $state): bool
    {
        return DB::transaction(function () use ($topic, $revision, $state): bool {
            $locked = Topic::query()->whereKey($topic->id)->lockForUpdate()->first();
            if ($locked === null || $locked->public_revision_id !== $revision->id) {
                return false;
            }
            $changed = $locked->freshness_revision_id !== $revision->id || $locked->freshness_status !== $state['freshness_status'];
            if ($changed) {
                DB::table('topic_freshness_changes')->insert([
                    'topic_id' => $locked->id, 'topic_revision_id' => $revision->id,
                    'previous_status' => $locked->freshness_status, 'status' => $state['freshness_status'],
                    'event_hint' => $state['event_hint'], 'coverage_note' => $state['coverage_note'],
                    'changed_at' => CarbonImmutable::now('UTC'), 'reminder_at' => in_array($state['freshness_status'], ['unknown', 'expired'], true) ? CarbonImmutable::now('UTC') : null,
                ]);
            }
            DB::table('topics')->where('id', $locked->id)->update([
                'freshness_revision_id' => $revision->id, 'freshness_status' => $state['freshness_status'],
                'freshness_checked_at' => CarbonImmutable::now('UTC'), 'freshness_next_check_at' => $state['next_transition_at'] ? CarbonImmutable::parse($state['next_transition_at'])->utc()->format('Y-m-d H:i:s') : null,
            ]);

            return $changed;
        }, 3);
    }

    /** @param array<string, mixed> $snapshot @param Collection<int, Article> $articles */
    private function hasBasis(array $snapshot, Collection $articles): bool
    {
        $mode = $snapshot['mode'];
        if (in_array($mode, ['none', 'evergreen'], true)) {
            return true;
        }
        if ($mode === 'composed_at') {
            return ! empty($snapshot['composed_at']);
        }
        if (trim($snapshot['coverage_note']) === '' || $articles->count() < 2) {
            return false;
        }
        $verified = empty($snapshot['last_verified_at']) ? null : CarbonImmutable::parse($snapshot['last_verified_at']);
        if ($verified !== null && $verified->isFuture()) {
            return false;
        }
        if ($mode === 'rolling') {
            return $verified !== null && $verified->greaterThan(now()->subDays(90))
                && ! empty($snapshot['next_review_at']) && trim($snapshot['public_updates']) !== '';
        }
        if (empty($snapshot['effective_to'])) {
            return false;
        }
        if (in_array($mode, ['annual', 'monthly'], true)) {
            return $snapshot['year'] !== null && ($mode !== 'monthly' || $snapshot['month'] !== null)
                && $articles->filter(fn (Article $article): bool => $article->published_at !== null
                    && (int) $article->published_at->year === (int) $snapshot['year']
                    && ($mode !== 'monthly' || (int) $article->published_at->month === (int) $snapshot['month']))->count() >= 2;
        }
        if ($mode === 'version') {
            return filled($snapshot['version']);
        }
        if ($mode === 'as_of') {
            return $verified !== null;
        }
        if ($mode === 'event') {
            return ! empty($snapshot['effective_from']) || ! empty($snapshot['valid_until']);
        }

        return $articles->filter(fn (Article $article): bool => $article->published_at?->between(now()->subDays(90), now()) ?? false)->count() >= 2;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function policyInputs(array $snapshot): array
    {
        $keys = array_map(fn ($key) => substr($key, strlen('freshness.')), array_filter(array_keys(self::rules()), fn ($key) => str_starts_with($key, 'freshness.')));

        return array_intersect_key($snapshot, array_flip($keys));
    }

    /** @param array<string, mixed> $freshness @return array<string, ?CarbonImmutable> */
    private function dates(array $freshness): array
    {
        $dates = [];
        foreach (['effective_from', 'effective_to', 'public_from', 'last_verified_at', 'next_review_at'] as $field) {
            $value = $freshness[$field] ?? null;
            if ($field === 'effective_to' && empty($value)) {
                $value = $freshness['valid_until'] ?? null;
            }
            $dates[$field] = empty($value) ? null : $this->date($value, $freshness['timezone'], $field, $field === 'effective_to');
        }

        return $dates;
    }

    public function dayBoundary(string $value, string $timezone): CarbonImmutable
    {
        return $this->date($value, $timezone, 'valid_until', true);
    }

    private function date(string $value, string $timezone, string $field, bool $exclusiveEnd): CarbonImmutable
    {
        try {
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:\d{2})?)?$/D', $value, $match)) {
                throw new \InvalidArgumentException;
            }
            if (! empty($match[4])) {
                $date = new DateTimeImmutable($value);
                if ($date->format('Y-m-d H:i:s') !== $match[1].' '.$match[2].':'.($match[3] ?? '00')
                    || (preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D', $match[4]) !== 1 && $match[4] !== 'Z')) {
                    throw new \InvalidArgumentException;
                }

                return CarbonImmutable::instance($date)->utc();
            }
            $local = $match[1].' '.($match[2] ?? '00:00').':'.($match[3] ?? '00');
            $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
            if ($naive === false || $naive->format('Y-m-d H:i:s') !== $local) {
                throw new \InvalidArgumentException;
            }
            if ($exclusiveEnd && empty($match[2])) {
                $naive = $naive->modify('+1 day');
                $local = $naive->format('Y-m-d H:i:s');
            }
            $zone = new DateTimeZone($timezone);
            $transitions = $zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800);
            $offsets = array_unique(array_column($transitions, 'offset'));
            $matches = [];
            foreach ($offsets as $offset) {
                $candidate = CarbonImmutable::createFromTimestampUTC($naive->getTimestamp() - $offset);
                if ($candidate->setTimezone($timezone)->format('Y-m-d H:i:s') === $local) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) !== 1) {
                throw new \InvalidArgumentException;
            }

            return $matches[0];
        } catch (Throwable) {
            throw ValidationException::withMessages(['freshness.'.$field => '日期无效或存在夏令时歧义，请核对日期并为歧义时间明确填写 UTC 偏移。']);
        }
    }
}
