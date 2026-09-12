<?php

namespace App\Services\Admin\Analytics;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\AiVisibilityTopic;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityTermCleaner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiVisibilityAnalyticsService
{
    private const DAILY_SAMPLE_TARGET = 5;

    public function __construct(
        private readonly AiVisibilityConfigurationResolver $configuration,
        private readonly AiVisibilityTermCleaner $termCleanup,
    ) {}

    /**
     * @return array{configured: bool, kpis: array<string, float>, sampled_runs: int}
     */
    public function snapshot(int $days = 60): array
    {
        $days = max(1, min(90, $days));
        $filter = AiVisibilityAnalyticsFilter::forDays($days);
        $configuration = $this->configurationStatus();
        if (! Schema::hasTable('ai_visibility_runs') || ! Schema::hasTable('ai_visibility_sources')) {
            return [
                'configured' => (bool) ($configuration['configured'] ?? false),
                'kpis' => $this->emptyKpis(),
                'sampled_runs' => 0,
            ];
        }

        $start = $filter->start();
        $end = $filter->end();
        $sampledRunIds = $this->sampledRunIds($start, $end, $filter);
        if ($sampledRunIds === []) {
            return [
                'configured' => (bool) ($configuration['configured'] ?? false),
                'kpis' => $this->emptyKpis(),
                'sampled_runs' => 0,
            ];
        }

        $brandAliases = $this->brandAliases();
        $ownedHosts = $this->ownedHosts();
        $runs = AiVisibilityRun::query()
            ->with(['sources' => fn ($query) => $query
                ->select($this->sourceColumns())
                ->orderByRaw('COALESCE(rank, 999999) asc')
                ->orderBy('id')])
            ->whereIn('id', $sampledRunIds)
            ->select('id', 'keyword', 'provider_type', 'ai_visibility_topic_id', 'answer_text', 'analysis_json', 'completed_at', 'created_at')
            ->get()
            ->map(fn (AiVisibilityRun $run): array => $this->analyzeRunForKpis($run, $brandAliases, $ownedHosts));

        return [
            'configured' => (bool) ($configuration['configured'] ?? false),
            'kpis' => $this->kpis($this->dailyKeywordMetrics($runs)),
            'sampled_runs' => $runs->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int|AiVisibilityAnalyticsFilter $filter = 14): array
    {
        $filter = is_int($filter) ? AiVisibilityAnalyticsFilter::forDays($filter) : $filter;
        $days = $filter->days();
        $start = $filter->start();
        $end = $filter->end();
        $configuration = $this->configurationStatus();

        if (! Schema::hasTable('ai_visibility_runs') || ! Schema::hasTable('ai_visibility_sources')) {
            return $this->emptyOverview(false, $start, $end, $configuration);
        }

        $periodRuns = $this->periodRunsQuery($start, $end, $filter);
        $runCount = (int) (clone $periodRuns)->count();
        if ($runCount === 0) {
            return $this->emptyOverview(true, $start, $end, $configuration);
        }

        $completedRunCount = (int) (clone $periodRuns)
            ->where('status', AiVisibilityRun::STATUS_COMPLETED)
            ->count();
        $sampledRunIds = $this->sampledRunIds($start, $end, $filter);
        $sampledRuns = AiVisibilityRun::query()
            ->with(['sources' => fn ($query) => $query
                ->select($this->sourceColumns())
                ->orderByRaw('COALESCE(rank, 999999) asc')
                ->orderBy('id')])
            ->whereIn('id', $sampledRunIds)
            ->select('id', 'keyword', 'provider_type', 'provider_key', 'model_id', 'ai_visibility_topic_id', 'answer_text', 'analysis_json', 'completed_at', 'created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $brandAliases = $this->brandAliases();
        $ownedHosts = $this->ownedHosts();
        $analyzedRuns = $sampledRuns
            ->map(fn (AiVisibilityRun $run): array => $this->analyzeRun($run, $brandAliases, $ownedHosts))
            ->values();

        $dailyKeywordMetrics = $this->dailyKeywordMetrics($analyzedRuns);
        $kpis = $this->kpis($dailyKeywordMetrics);
        $sourcePreferences = $this->sourcePreferences($analyzedRuns);
        $topicNames = $this->topicNames();
        $todayRuns = $this->periodRunsQuery(now()->copy()->startOfDay(), now()->copy()->endOfDay(), $filter);
        $todayRunCount = (int) (clone $todayRuns)->count();
        $todayCompletedRunCount = (int) (clone $todayRuns)
            ->where('status', AiVisibilityRun::STATUS_COMPLETED)
            ->count();
        $todayKeywordCount = (int) ((clone $todayRuns)
            ->whereRaw("TRIM(COALESCE(keyword, '')) <> ''")
            ->selectRaw('COUNT(DISTINCT LOWER(TRIM(keyword))) as aggregate')
            ->toBase()
            ->value('aggregate') ?? 0);
        $latestCompletedAt = $sampledRuns
            ->pluck('completed_at')
            ->filter()
            ->max();

        return [
            'ready' => true,
            'configured' => (bool) ($configuration['configured'] ?? false),
            'configuration' => $configuration,
            'daily_sample_target' => self::DAILY_SAMPLE_TARGET,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
            ],
            'brand' => [
                'name' => $brandAliases[0] ?? 'GEOFlow',
                'aliases' => $brandAliases,
                'owned_hosts' => $ownedHosts,
            ],
            'kpis' => $kpis,
            'polling' => [
                'runs' => $runCount,
                'completed_runs' => $completedRunCount,
                'sampled_runs' => $sampledRuns->count(),
                'success_rate' => $this->percent($completedRunCount, $runCount),
                'today_runs' => $todayRunCount,
                'today_completed_runs' => $todayCompletedRunCount,
                'today_keyword_count' => $todayKeywordCount,
                'today_target_samples' => $todayKeywordCount * self::DAILY_SAMPLE_TARGET,
                'latest_completed_at' => $latestCompletedAt,
            ],
            'trend' => $this->trend($dailyKeywordMetrics, $start, $days),
            'keywords' => $this->keywordMetrics($dailyKeywordMetrics),
            'terms' => $this->termCloud($analyzedRuns),
            'sources' => $sourcePreferences,
            'source_distribution' => $this->sourceDistribution($analyzedRuns, $topicNames),
            'attention_sources' => $this->attentionSources($sourcePreferences),
            'competitors' => $this->competitorEvidence($analyzedRuns, $topicNames),
            'latest_runs' => $this->latestRuns($analyzedRuns),
        ];
    }

    private function periodRunsQuery(Carbon $start, Carbon $end, ?AiVisibilityAnalyticsFilter $filter = null): Builder
    {
        return AiVisibilityRun::query()
            ->where(function (Builder $query) use ($start, $end): void {
                $query
                    ->whereBetween('completed_at', [$start, $end])
                    ->orWhere(function (Builder $query) use ($start, $end): void {
                        $query
                            ->whereNull('completed_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->when($filter !== null && $filter->keyword !== '', fn (Builder $query) => $query->where('keyword', $filter->keyword))
            ->when($filter !== null && $filter->topicId > 0, fn (Builder $query) => $query->where('ai_visibility_topic_id', $filter->topicId))
            ->when($filter !== null && $filter->provider !== 'all', fn (Builder $query) => $query->where('provider_type', $filter->provider));
    }

    /** @return list<string> */
    private function sourceColumns(): array
    {
        return [
            'id',
            'ai_visibility_run_id',
            'title',
            'url',
            'domain',
            'site_name',
            'snippet',
            'summary',
            'content_excerpt',
            'rank',
            'authority_level',
        ];
    }

    /**
     * @return list<int>
     */
    private function sampledRunIds(Carbon $start, Carbon $end, ?AiVisibilityAnalyticsFilter $filter = null): array
    {
        $rankedRuns = $this->periodRunsQuery($start, $end, $filter)
            ->where('status', AiVisibilityRun::STATUS_COMPLETED)
            ->select('id')
            ->selectRaw(
                'ROW_NUMBER() OVER (
                    PARTITION BY DATE(COALESCE(completed_at, created_at)), LOWER(TRIM(keyword))
                    ORDER BY created_at, id
                ) AS sample_rank'
            );

        return DB::query()
            ->fromSub($rankedRuns, 'ranked_ai_visibility_runs')
            ->where('sample_rank', '<=', self::DAILY_SAMPLE_TARGET)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOverview(bool $ready, Carbon $start, Carbon $end, ?array $configuration = null): array
    {
        $configuration ??= $this->configurationStatus();
        $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;

        return [
            'ready' => $ready,
            'configured' => (bool) ($configuration['configured'] ?? false),
            'configuration' => $configuration,
            'daily_sample_target' => self::DAILY_SAMPLE_TARGET,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
            ],
            'brand' => [
                'name' => $this->brandAliases()[0] ?? 'GEOFlow',
                'aliases' => $this->brandAliases(),
                'owned_hosts' => $this->ownedHosts(),
            ],
            'kpis' => $this->emptyKpis(),
            'polling' => [
                'runs' => 0,
                'completed_runs' => 0,
                'sampled_runs' => 0,
                'success_rate' => 0.0,
                'today_runs' => 0,
                'today_completed_runs' => 0,
                'today_keyword_count' => 0,
                'today_target_samples' => 0,
                'latest_completed_at' => null,
            ],
            'trend' => $this->trend(collect(), $start, $days),
            'keywords' => [],
            'terms' => [],
            'sources' => [],
            'source_distribution' => [],
            'attention_sources' => [],
            'competitors' => [],
            'latest_runs' => [],
        ];
    }

    /**
     * @return array<string, float>
     */
    private function emptyKpis(): array
    {
        return [
            'brand_visibility' => 0.0,
            'top1_rate' => 0.0,
            'top3_rate' => 0.0,
            'positive_rate' => 0.0,
            'negative_rate' => 0.0,
            'sentiment_score' => 0.0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $metricRows
     * @return array<string, float>
     */
    private function kpis(Collection $metricRows): array
    {
        if ($metricRows->isEmpty()) {
            return $this->emptyKpis();
        }

        return [
            'brand_visibility' => round((float) $metricRows->avg('brand_visibility'), 1),
            'top1_rate' => round((float) $metricRows->avg('top1_rate'), 1),
            'top3_rate' => round((float) $metricRows->avg('top3_rate'), 1),
            'positive_rate' => round((float) $metricRows->avg('positive_rate'), 1),
            'negative_rate' => round((float) $metricRows->avg('negative_rate'), 1),
            'sentiment_score' => round((float) $metricRows->avg('sentiment_score'), 1),
        ];
    }

    /**
     * @param  list<string>  $brandAliases
     * @param  list<string>  $ownedHosts
     * @return array<string, mixed>
     */
    private function analyzeRun(AiVisibilityRun $run, array $brandAliases, array $ownedHosts): array
    {
        $sources = $run->sources
            ->values()
            ->map(function (AiVisibilitySource $source, int $index) use ($brandAliases, $ownedHosts): array {
                $rank = (int) ($source->rank ?: $index + 1);
                $domain = $this->sourceDomain($source);
                $brandText = implode(' ', array_filter([
                    $source->title,
                    $source->site_name,
                    $source->domain,
                    $source->url,
                    $source->snippet,
                    $source->summary,
                    $source->content_excerpt,
                ], static fn (mixed $value): bool => trim((string) $value) !== ''));
                $termText = implode(' ', array_filter([
                    $source->title,
                    $source->snippet,
                    $source->summary,
                    $source->content_excerpt,
                ], static fn (mixed $value): bool => trim((string) $value) !== ''));
                $brandMentioned = $this->domainMatches($domain, $ownedHosts) || $this->containsAny($brandText, $brandAliases);

                return [
                    'id' => (int) $source->id,
                    'title' => trim((string) $source->title),
                    'url' => trim((string) $source->url),
                    'domain' => $domain,
                    'site_name' => trim((string) $source->site_name),
                    'authority_level' => trim((string) $source->authority_level),
                    'authority_label' => $this->authorityLabel($source->authority_level),
                    'rank' => $rank,
                    'brand_mentioned' => $brandMentioned,
                    'text' => $brandText,
                    'term_text' => $termText,
                ];
            });

        $answerText = trim((string) $run->answer_text);
        $answerMentionsBrand = $this->containsAny($answerText, $brandAliases);
        $brandSourceRanks = $sources
            ->where('brand_mentioned', true)
            ->pluck('rank')
            ->map(fn (mixed $rank): int => (int) $rank)
            ->filter(fn (int $rank): bool => $rank > 0);
        $sentiment = $this->sentiment($run);

        return [
            'id' => (int) $run->id,
            'date' => $this->runDate($run),
            'keyword' => trim((string) $run->keyword),
            'provider_type' => (string) $run->provider_type,
            'provider_key' => (string) $run->provider_key,
            'model_id' => (string) $run->model_id,
            'topic_id' => $run->ai_visibility_topic_id === null ? 0 : (int) $run->ai_visibility_topic_id,
            'answer_text' => $answerText,
            'answer_mentions_brand' => $answerMentionsBrand,
            'brand_visible' => $answerMentionsBrand || $brandSourceRanks->contains(fn (int $rank): bool => $rank <= 3),
            'top1' => $brandSourceRanks->contains(fn (int $rank): bool => $rank === 1),
            'top3' => $brandSourceRanks->contains(fn (int $rank): bool => $rank <= 3),
            'best_brand_rank' => $brandSourceRanks->min(),
            'sentiment' => $sentiment['label'],
            'sentiment_score' => $sentiment['score'],
            'terms' => $this->termsForRun($answerText, $sources, $brandAliases),
            'sources' => $sources->map(fn (array $source): array => array_diff_key($source, ['text' => true, 'term_text' => true]))->all(),
            'source_terms' => $sources->map(fn (array $source): array => [
                'domain' => $source['domain'],
                'rank' => $source['rank'],
                'terms' => $this->extractTerms($source['term_text'], $brandAliases),
            ])->all(),
            'analysis' => is_array($run->analysis_json) ? $run->analysis_json : [],
            'created_at' => $run->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  list<string>  $brandAliases
     * @param  list<string>  $ownedHosts
     * @return array<string, mixed>
     */
    private function analyzeRunForKpis(AiVisibilityRun $run, array $brandAliases, array $ownedHosts): array
    {
        $brandSourceRanks = $run->sources
            ->values()
            ->map(function (AiVisibilitySource $source, int $index) use ($brandAliases, $ownedHosts): ?int {
                $domain = $this->sourceDomain($source);
                $brandText = implode(' ', array_filter([
                    $source->title,
                    $source->site_name,
                    $source->domain,
                    $source->url,
                    $source->snippet,
                    $source->summary,
                    $source->content_excerpt,
                ], static fn (mixed $value): bool => trim((string) $value) !== ''));

                if (! $this->domainMatches($domain, $ownedHosts) && ! $this->containsAny($brandText, $brandAliases)) {
                    return null;
                }

                return (int) ($source->rank ?: $index + 1);
            })
            ->filter(fn (?int $rank): bool => $rank !== null && $rank > 0);
        $sentiment = $this->sentiment($run);

        return [
            'date' => $this->runDate($run),
            'keyword' => trim((string) $run->keyword),
            'provider_type' => (string) $run->provider_type,
            'brand_visible' => $this->containsAny((string) $run->answer_text, $brandAliases)
                || $brandSourceRanks->contains(fn (int $rank): bool => $rank <= 3),
            'top1' => $brandSourceRanks->contains(fn (int $rank): bool => $rank === 1),
            'top3' => $brandSourceRanks->contains(fn (int $rank): bool => $rank <= 3),
            'sentiment' => $sentiment['label'],
            'sentiment_score' => $sentiment['score'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @return Collection<int, array<string, mixed>>
     */
    private function dailyKeywordMetrics(Collection $runs): Collection
    {
        return $runs
            ->groupBy(fn (array $run): string => $run['date'].'|'.Str::lower((string) $run['keyword']))
            ->map(function (Collection $rows): array {
                $metric = $this->metricRow($rows);
                $first = $rows->first();

                return [
                    ...$metric,
                    'date' => (string) $first['date'],
                    'keyword' => (string) $first['keyword'],
                    'providers' => $rows
                        ->pluck('provider_type')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dailyKeywordMetrics
     * @return list<array<string, mixed>>
     */
    private function trend(Collection $dailyKeywordMetrics, Carbon $start, int $days): array
    {
        $start = $start->copy()->startOfDay();

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($start, $dailyKeywordMetrics): array {
                $date = $start->copy()->addDays($offset)->toDateString();
                $rows = $dailyKeywordMetrics
                    ->where('date', $date)
                    ->values();

                if ($rows->isEmpty()) {
                    return [
                        'date' => $date,
                        'label' => Carbon::parse($date)->format('m-d'),
                        'samples' => 0,
                        'visibility' => 0.0,
                        'top1' => 0.0,
                        'top3' => 0.0,
                        'sentiment' => 0.0,
                    ];
                }

                return [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('m-d'),
                    'samples' => (int) $rows->sum('samples'),
                    'visibility' => round((float) $rows->avg('brand_visibility'), 1),
                    'top1' => round((float) $rows->avg('top1_rate'), 1),
                    'top3' => round((float) $rows->avg('top3_rate'), 1),
                    'sentiment' => round((float) $rows->avg('sentiment_score'), 1),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dailyKeywordMetrics
     * @return list<array<string, mixed>>
     */
    private function keywordMetrics(Collection $dailyKeywordMetrics): array
    {
        return $dailyKeywordMetrics
            ->groupBy(fn (array $run): string => Str::lower((string) $run['keyword']))
            ->map(function (Collection $rows): array {
                return [
                    'keyword' => (string) $rows->first()['keyword'],
                    'samples' => (int) $rows->sum('samples'),
                    'brand_visibility' => round((float) $rows->avg('brand_visibility'), 1),
                    'top1_rate' => round((float) $rows->avg('top1_rate'), 1),
                    'top3_rate' => round((float) $rows->avg('top3_rate'), 1),
                    'positive_rate' => round((float) $rows->avg('positive_rate'), 1),
                    'negative_rate' => round((float) $rows->avg('negative_rate'), 1),
                    'sentiment_score' => round((float) $rows->avg('sentiment_score'), 1),
                    'providers' => $rows
                        ->pluck('providers')
                        ->flatten()
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'dates' => $rows
                        ->pluck('date')
                        ->unique()
                        ->count(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [
                    $right['samples'],
                    $right['brand_visibility'],
                    $right['top3_rate'],
                ] <=> [
                    $left['samples'],
                    $left['brand_visibility'],
                    $left['top3_rate'],
                ];
            })
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metricRow(Collection $rows): array
    {
        return [
            'samples' => $rows->count(),
            'brand_visibility' => $this->percent($rows->where('brand_visible', true)->count(), $rows->count()),
            'top1_rate' => $this->percent($rows->where('top1', true)->count(), $rows->count()),
            'top3_rate' => $this->percent($rows->where('top3', true)->count(), $rows->count()),
            'positive_rate' => $this->percent($rows->where('sentiment', 'positive')->count(), $rows->count()),
            'negative_rate' => $this->percent($rows->where('sentiment', 'negative')->count(), $rows->count()),
            'sentiment_score' => round((float) $rows->avg('sentiment_score') * 100, 1),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function termCloud(Collection $runs): array
    {
        $terms = [];

        foreach ($runs as $run) {
            foreach ($run['terms'] as $term => $weight) {
                $terms[$term] = ($terms[$term] ?? 0.0) + (float) $weight;
            }
        }

        arsort($terms);

        if ($terms === []) {
            return [];
        }

        $cleaned = $this->cleanedTopics($terms);
        if ($cleaned !== null) {
            return $cleaned;
        }

        $max = max((float) reset($terms), 1.0);

        return collect($terms)
            ->take(24)
            ->map(fn (float $weight, string $term): array => [
                'term' => $term,
                'weight' => round($weight, 2),
                'size' => (int) round(12 + (($weight / $max) * 8)),
            ])
            ->values()
            ->all();
    }

    /**
     * 用系统 AI 清洗词云候选主题，结果按候选词签名缓存；
     * 未启用、模型不可用、清洗失败或清洗结果为空时返回 null，走启发式兜底。
     *
     * @param  array<string, float>  $terms
     * @return list<array<string, mixed>>|null
     */
    private function cleanedTopics(array $terms): ?array
    {
        if (! filter_var(config('geoflow.ai_visibility.term_cleanup_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $candidates = collect($terms)
            ->take(max(10, min(200, (int) config('geoflow.ai_visibility.term_cleanup_candidates', 60))))
            ->all();
        if ($candidates === []) {
            return null;
        }

        $cacheKey = 'geoflow:ai_visibility:term_cloud:'.hash('sha256', (string) json_encode($candidates, JSON_UNESCAPED_UNICODE));
        $ttl = max(60, (int) config('geoflow.ai_visibility.term_cleanup_cache_ttl', 43200));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached['ok'] === true ? $cached['topics'] : null;
        }

        $topics = $this->termCleanup->clean($candidates);
        if ($topics === null || $topics === []) {
            // 失败只短缓存，避免数据未变化时每次渲染都等待一次注定失败的 AI 调用。
            Cache::put($cacheKey, ['ok' => false], min($ttl, 600));

            return null;
        }

        $shaped = $this->shapeTopics($topics, $terms);
        if ($shaped === []) {
            Cache::put($cacheKey, ['ok' => false], min($ttl, 600));

            return null;
        }

        Cache::put($cacheKey, ['ok' => true, 'topics' => $shaped], $ttl);

        return $shaped;
    }

    /**
     * 主题权重取其归属的原始候选词权重之和，每个候选词只计入一次。
     *
     * @param  list<array{name: string, terms: list<string>}>  $topics
     * @param  array<string, float>  $terms
     * @return list<array<string, mixed>>
     */
    private function shapeTopics(array $topics, array $terms): array
    {
        $shaped = [];

        foreach ($topics as $topic) {
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $weight = 0.0;
            foreach ((array) ($topic['terms'] ?? []) as $source) {
                $source = (string) $source;
                if (isset($terms[$source])) {
                    $weight += (float) $terms[$source];
                    unset($terms[$source]);
                }
            }

            if ($weight <= 0.0) {
                continue;
            }

            $shaped[] = ['term' => $name, 'weight' => $weight];
        }

        usort($shaped, fn (array $left, array $right): int => $right['weight'] <=> $left['weight']);

        $max = (float) ($shaped[0]['weight'] ?? 0.0);
        $max = max($max, 1.0);

        return collect($shaped)
            ->take(24)
            ->map(fn (array $topic): array => [
                'term' => $topic['term'],
                'weight' => round((float) $topic['weight'], 2),
                'size' => (int) round(12 + (((float) $topic['weight'] / $max) * 8)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function sourcePreferences(Collection $runs): array
    {
        $sources = [];

        foreach ($runs as $run) {
            foreach ($run['sources'] as $source) {
                $domain = (string) ($source['domain'] ?: __('admin.growth_center.ai_visibility.unknown_source'));
                $sources[$domain] ??= [
                    'domain' => $domain,
                    'mentions' => 0,
                    'brand_mentions' => 0,
                    'top1_count' => 0,
                    'top3_count' => 0,
                    'rank_sum' => 0,
                    'rank_count' => 0,
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'keywords' => [],
                    'latest_title' => '',
                    'latest_url' => '',
                    'last_seen' => '',
                ];

                $rank = max(1, (int) ($source['rank'] ?? 999));
                $sources[$domain]['mentions']++;
                $sources[$domain]['rank_sum'] += $rank;
                $sources[$domain]['rank_count']++;
                $sources[$domain]['latest_title'] = $source['title'] ?: $sources[$domain]['latest_title'];
                $sources[$domain]['latest_url'] = $source['url'] ?: $sources[$domain]['latest_url'];
                $sources[$domain]['last_seen'] = (string) $run['date'];
                $sources[$domain]['keywords'][(string) $run['keyword']] = true;

                if ((bool) ($source['brand_mentioned'] ?? false)) {
                    $sources[$domain]['brand_mentions']++;
                }

                if ($rank === 1) {
                    $sources[$domain]['top1_count']++;
                }

                if ($rank <= 3) {
                    $sources[$domain]['top3_count']++;
                }

                if ($run['sentiment'] === 'positive') {
                    $sources[$domain]['positive_count']++;
                } elseif ($run['sentiment'] === 'negative') {
                    $sources[$domain]['negative_count']++;
                }
            }
        }

        return collect($sources)
            ->map(function (array $source): array {
                $rankCount = max(1, (int) $source['rank_count']);
                $source['avg_rank'] = round((float) $source['rank_sum'] / $rankCount, 1);
                $source['brand_coverage'] = $this->percent((int) $source['brand_mentions'], (int) $source['mentions']);
                $source['top3_rate'] = $this->percent((int) $source['top3_count'], (int) $source['mentions']);
                $source['keyword_count'] = count($source['keywords']);
                $source['keywords'] = array_slice(array_keys($source['keywords']), 0, 3);
                $source['action'] = $this->sourceAction($source);

                unset($source['rank_sum'], $source['rank_count']);

                return $source;
            })
            ->sort(function (array $left, array $right): int {
                return [
                    $right['mentions'],
                    $right['top3_count'],
                    $left['avg_rank'],
                ] <=> [
                    $left['mentions'],
                    $left['top3_count'],
                    $right['avg_rank'],
                ];
            })
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function attentionSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn (array $source): bool => in_array($source['action'], ['content_gap', 'reputation_attention', 'strengthen_owned'], true))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @param  array<int, string>  $topicNames
     * @return list<array<string, mixed>>
     */
    private function sourceDistribution(Collection $runs, array $topicNames = []): array
    {
        $rows = [];
        foreach ($runs as $run) {
            $topicName = (string) ($topicNames[(int) ($run['topic_id'] ?? 0)] ?? '未归类');
            foreach ($run['sources'] as $source) {
                $name = (string) ($source['site_name'] ?: $source['domain'] ?: __('admin.growth_center.ai_visibility.unknown_source'));
                $key = $topicName.'|'.$name;
                $rows[$key] ??= ['name' => $name, 'topic' => $topicName, 'count' => 0, 'domains' => [], 'authority' => []];
                $rows[$key]['count']++;
                if ($source['domain'] !== '') {
                    $rows[$key]['domains'][$source['domain']] = true;
                }
                $label = (string) ($source['authority_label'] ?? '');
                if ($label !== '') {
                    $rows[$key]['authority'][$label] = ($rows[$key]['authority'][$label] ?? 0) + 1;
                }
            }
        }

        return collect($rows)->map(function (array $row): array {
            $row['domains'] = array_keys($row['domains']);
            arsort($row['authority']);
            $row['top_authority'] = (string) (array_key_first($row['authority']) ?? '');

            return $row;
        })->sortByDesc('count')->values()->take(20)->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @param  array<int, string>  $topicNames
     * @return list<array<string, mixed>>
     */
    private function competitorEvidence(Collection $runs, array $topicNames = []): array
    {
        $rows = [];
        foreach ($runs as $run) {
            $topicName = (string) ($topicNames[(int) ($run['topic_id'] ?? 0)] ?? '未归类');
            foreach (($run['analysis']['competitors'] ?? []) as $competitor) {
                if (! is_array($competitor) || trim((string) ($competitor['name'] ?? '')) === '') {
                    continue;
                }
                $name = trim((string) $competitor['name']);
                $key = $topicName.'|'.$name;
                $rows[$key] ??= ['name' => $name, 'topic' => $topicName, 'mention_count' => 0, 'evidence' => []];
                foreach (($competitor['evidence'] ?? []) as $evidence) {
                    if (is_array($evidence) && trim((string) ($evidence['url'] ?? '')) !== '') {
                        $rows[$key]['evidence'][(string) $evidence['url']] = $evidence;
                    }
                }
                $rows[$key]['mention_count'] = count($rows[$key]['evidence']);
            }
        }

        return collect($rows)->map(fn (array $row): array => [...$row, 'evidence' => array_values($row['evidence'])])->sortByDesc('mention_count')->values()->take(20)->all();
    }

    /**
     * @return array<int, string>
     */
    private function topicNames(): array
    {
        if (! Schema::hasTable('ai_visibility_topics')) {
            return [];
        }

        return AiVisibilityTopic::query()->pluck('name', 'id')->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function latestRuns(Collection $runs): array
    {
        return $runs
            ->sortByDesc('created_at')
            ->take(5)
            ->map(fn (array $run): array => [
                'id' => $run['id'],
                'keyword' => $run['keyword'],
                'provider_type' => $run['provider_type'],
                'date' => $run['date'],
                'brand_visible' => $run['brand_visible'],
                'top1' => $run['top1'],
                'top3' => $run['top3'],
                'sentiment' => $run['sentiment'],
                'best_brand_rank' => $run['best_brand_rank'],
                'answer_excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $run['answer_text']) ?: '', 180),
                'source_count' => count($run['sources']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sources
     * @param  list<string>  $brandAliases
     * @return array<string, float>
     */
    private function termsForRun(string $answerText, Collection $sources, array $brandAliases): array
    {
        $terms = [];

        foreach ($this->extractTerms($answerText, $brandAliases) as $term) {
            $terms[$term] = ($terms[$term] ?? 0.0) + 1.5;
        }

        foreach ($sources as $source) {
            $rank = max(1, min(5, (int) ($source['rank'] ?? 5)));
            $weight = round((6 - $rank) / 5, 2);
            foreach ($this->extractTerms((string) ($source['term_text'] ?? ''), $brandAliases) as $term) {
                $terms[$term] = ($terms[$term] ?? 0.0) + $weight;
            }
        }

        arsort($terms);

        return array_slice($terms, 0, 30, true);
    }

    /**
     * @param  list<string>  $brandAliases
     * @return list<string>
     */
    private function extractTerms(string $text, array $brandAliases): array
    {
        $text = $this->sanitizeTermText($text);

        $stopWords = [
            'the', 'and', 'for', 'with', 'from', 'into', 'that', 'this', 'you', 'are', 'was', 'were', 'api', 'http', 'https', 'top',
            'www', 'com', 'cn', 'net', 'org', 'demo', 'example', 'html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'index', 'default',
            'juejin', 'infoq', 'sspai', 'deepseek', 'doubao', 'tencent', 'cloud',
            'will', 'your', 'can', 'has', 'have', 'how', 'what', 'when', 'which', 'who', 'why', 'not', 'but', 'all', 'any', 'also',
            'more', 'most', 'other', 'some', 'such', 'than', 'then', 'they', 'them', 'their', 'there', 'these', 'those', 'about',
            'after', 'before', 'between', 'both', 'each', 'only', 'over', 'under', 'very', 'well', 'best', 'get', 'use', 'used',
            'using', 'new', 'one', 'two', 'three', 'may', 'should', 'would', 'could', 'its', 'our', 'out', 'per', 'via', 'see',
            '以及', '对应', '这个', '相关', '可以', '进行', '通过', '一个', '一些', '包括', '结果', '信息', '数据', '内容', '分析', '信源',
            '我们', '你们', '他们', '它们', '自己', '什么', '怎么', '为什么', '因为', '所以', '如果', '虽然', '但是', '然后', '于是',
            '其中', '关于', '对于', '除了', '还有', '这些', '那些', '那个', '可能', '应该', '需要', '能够', '目前', '现在', '时候',
            '方面', '情况', '并且', '或者', '以及', '不仅', '而且', '不过', '以上', '下面', '如下', '例如', '比如', '一般', '非常',
        ];
        $domainTerms = [
            'AI 搜索', 'AI 可见度', 'AI 营销', 'GEO 运营', 'Top 1', 'Top 3',
            '内容工程', '信源投放', '生成式引擎优化', '企业知识库', '知识库', '多站点分发',
            '品牌可见度', '结构化输出', '联网搜索', '第三方信源', '公开案例', '客户案例',
            '白皮书', '案例页', '对比内容', '权威信源', '引用证据', '可引用资料',
            '工具选型', '行业语境', '品牌提及', '投放建议', '搜索覆盖', '排名稳定性',
            'RAG', 'FAQ',
        ];
        $brandAliasSet = collect($brandAliases)
            ->map(fn (string $alias): string => Str::lower($alias))
            ->filter()
            ->values()
            ->all();

        $terms = [];
        foreach ($domainTerms as $term) {
            if (str_contains(Str::lower($text), Str::lower($term))) {
                $terms[] = $term;
            }
        }

        preg_match_all('/[A-Za-z][A-Za-z]{2,}/u', $text, $matches);
        $terms = array_merge($terms, $matches[0] ?? []);
        $terms = array_merge($terms, $this->chineseTerms($text));

        return collect($terms)
            ->map(fn (string $term): string => $this->normalizeTerm($term, $domainTerms))
            ->filter(function (string $term) use ($stopWords, $brandAliasSet): bool {
                if ($term === '' || in_array($term, $stopWords, true)) {
                    return false;
                }

                if (mb_strlen($term) > 12) {
                    return false;
                }

                foreach ($brandAliasSet as $alias) {
                    if ($alias !== '' && ($term === $alias || str_contains($alias, $term))) {
                        return false;
                    }
                }

                return true;
            })
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * 清洗用于主题提取的文本：抓取摘要里常混有 URL、HTML 和 JSON-LD，
     * 直接分词会把域名片段、标签属性当成主题。
     */
    private function sanitizeTermText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? $text;
        $text = preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $text) ?? $text;
        $text = preg_replace('~[\w.+-]+@[\w.-]+~u', ' ', $text) ?? $text;
        $text = preg_replace('/\b[\w-]+(?:\.[\w-]+)+\b/u', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * 无分词库时的中文候选词提取：保留 2-6 字的连续汉字串；长句则取 2 字
     * 片段（汉语双字词为主），3-4 字片段仅保留文中重复出现过的，过滤常用虚词。
     *
     * @return list<string>
     */
    private function chineseTerms(string $text): array
    {
        static $stopWords = [
            '的了', '是在', '和与', '及或', '有无', '不为', '对的', '从被', '把让', '向于', '至到', '由以', '而且', '但也',
            '都还', '就又', '再很', '更最', '太挺', '非常', '十分', '比较', '这些', '那些', '这个', '那个', '我们', '你们',
            '他们', '它们', '自己', '什么', '怎么', '如何', '为什么', '因为', '所以', '如果', '虽然', '但是', '然后', '于是',
            '其中', '之一', '关于', '对于', '除了', '以及', '还有', '可以', '可能', '应该', '需要', '能够', '进行', '通过',
            '一个', '一些', '每次', '目前', '现在', '时候', '方面', '情况', '并且', '或者', '不仅', '不过', '以上',
            '下面', '如下', '例如', '比如', '一般', '没有', '就是', '还是', '也是', '都是', '的话', '来说', '而言',
            '相关', '包括', '结果', '信息', '数据', '内容', '分析', '选择', '建议', '问题', '使用', '提供', '支持', '发展',
        ];

        preg_match_all('/\p{Han}{2,}/u', $text, $matches);
        $candidates = [];

        foreach ($matches[0] ?? [] as $run) {
            $length = mb_strlen($run, 'UTF-8');
            if ($length <= 6) {
                $candidates[] = $run;

                continue;
            }

            for ($offset = 0; $offset + 2 <= $length; $offset++) {
                $candidates[] = mb_substr($run, $offset, 2, 'UTF-8');
            }

            $counts = [];
            for ($size = 3; $size <= 4; $size++) {
                for ($offset = 0; $offset + $size <= $length; $offset++) {
                    $gram = mb_substr($run, $offset, $size, 'UTF-8');
                    $counts[$gram] = ($counts[$gram] ?? 0) + 1;
                }
            }

            foreach ($counts as $gram => $count) {
                if ($count >= 2) {
                    $candidates[] = $gram;
                }
            }
        }

        return collect($candidates)
            ->filter(fn (string $term): bool => ! in_array($term, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $domainTerms
     */
    private function normalizeTerm(string $term, array $domainTerms): string
    {
        $term = trim($term);

        foreach ($domainTerms as $domainTerm) {
            if (Str::lower($term) === Str::lower($domainTerm)) {
                return $domainTerm;
            }
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $term) === 1) {
            $upperTerm = Str::upper($term);

            return in_array($upperTerm, ['AI', 'GEO', 'RAG', 'FAQ'], true)
                ? $upperTerm
                : Str::lower($term);
        }

        return $term;
    }

    /**
     * @return array{label: string, score: int}
     */
    private function sentiment(AiVisibilityRun $run): array
    {
        $analysis = is_array($run->analysis_json) ? $run->analysis_json : [];
        $label = $this->normalizeSentimentLabel(
            data_get($analysis, 'sentiment')
            ?? data_get($analysis, 'brand_sentiment')
            ?? data_get($analysis, 'brand.sentiment')
        );

        if ($label !== null) {
            return [
                'label' => $label,
                'score' => $label === 'positive' ? 1 : ($label === 'negative' ? -1 : 0),
            ];
        }

        $text = Str::lower(implode(' ', array_filter([
            $run->answer_text,
            $run->sources->pluck('title')->implode(' '),
            $run->sources->pluck('snippet')->implode(' '),
            $run->sources->pluck('summary')->implode(' '),
        ], static fn (mixed $value): bool => trim((string) $value) !== '')));

        $positiveScore = $this->keywordScore($text, [
            '推荐', '领先', '优势', '权威', '可靠', '官方', '适合', '高效', '优质', 'positive', 'recommended', 'best', 'leading', 'trusted', 'reliable', 'strong',
        ]);
        $negativeScore = $this->keywordScore($text, [
            '风险', '投诉', '负面', '问题', '不足', '错误', '不推荐', '低效', 'negative', 'complaint', 'risk', 'issue', 'weak', 'bad', 'scam',
        ]);

        if ($positiveScore > $negativeScore) {
            return ['label' => 'positive', 'score' => 1];
        }

        if ($negativeScore > $positiveScore) {
            return ['label' => 'negative', 'score' => -1];
        }

        return ['label' => 'neutral', 'score' => 0];
    }

    private function normalizeSentimentLabel(mixed $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        return match ($value) {
            'positive', 'pos', '正面', '积极', '利好' => 'positive',
            'negative', 'neg', '负面', '消极', '利空' => 'negative',
            'neutral', 'mixed', '中性', '混合' => 'neutral',
            default => null,
        };
    }

    /**
     * @param  list<string>  $keywords
     */
    private function keywordScore(string $text, array $keywords): int
    {
        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($text, Str::lower($keyword))) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function containsAny(string $text, array $aliases): bool
    {
        $text = Str::lower($text);

        foreach ($aliases as $alias) {
            $alias = Str::lower(trim($alias));
            if ($alias !== '' && str_contains($text, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ownedHosts
     */
    private function domainMatches(string $domain, array $ownedHosts): bool
    {
        $domain = $this->normalizeHost($domain);

        foreach ($ownedHosts as $host) {
            if ($domain === $host || str_ends_with($domain, '.'.$host)) {
                return true;
            }
        }

        return false;
    }

    private function sourceDomain(AiVisibilitySource $source): string
    {
        $domain = trim((string) $source->domain);
        if ($domain === '' && trim((string) $source->url) !== '') {
            $domain = (string) parse_url((string) $source->url, PHP_URL_HOST);
        }

        if ($domain === '') {
            $domain = trim((string) $source->site_name);
        }

        return $this->normalizeHost($domain);
    }

    private function authorityLabel(mixed $level): string
    {
        return match ((string) $level) {
            '1' => '非常权威', '2' => '正常权威', '3' => '一般权威', '4' => '一般不权威', default => ''
        };
    }

    private function normalizeHost(string $host): string
    {
        $host = Str::lower(trim($host));
        $host = preg_replace('/^https?:\/\//', '', $host) ?? $host;
        $host = preg_replace('/\/.*$/', '', $host) ?? $host;
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return trim($host);
    }

    /**
     * @return list<string>
     */
    private function brandAliases(): array
    {
        $siteAliases = collect([
            config('geoflow.site_name'),
            config('geoflow.site_full_name'),
            'GEOFlow',
        ])
            ->map(fn (mixed $alias): string => trim((string) $alias))
            ->filter(fn (string $alias): bool => $alias !== '' && mb_strlen($alias) >= 2);

        $appName = trim((string) config('app.name'));
        if ($appName !== '' && (Str::lower($appName) !== 'laravel' || $siteAliases->contains(fn (string $alias): bool => Str::lower($alias) === 'laravel'))) {
            $siteAliases->push($appName);
        }

        return $siteAliases
            ->unique(fn (string $alias): string => Str::lower($alias))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function ownedHosts(): array
    {
        return collect([
            config('geoflow.site_url'),
            config('app.url'),
        ])
            ->map(fn (mixed $url): string => $this->normalizeHost((string) parse_url((string) $url, PHP_URL_HOST) ?: (string) $url))
            ->filter(fn (string $host): bool => $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceAction(array $source): string
    {
        if ((int) $source['negative_count'] > (int) $source['positive_count'] && (int) $source['brand_mentions'] > 0) {
            return 'reputation_attention';
        }

        if ((int) $source['brand_mentions'] === 0 && (int) $source['top3_count'] > 0) {
            return 'content_gap';
        }

        if ((int) $source['brand_mentions'] > 0 && (float) $source['top3_rate'] >= 30) {
            return 'maintain';
        }

        if ((int) $source['brand_mentions'] > 0) {
            return 'strengthen_owned';
        }

        return 'monitor';
    }

    /**
     * @return array{configured: bool, doubao_search_configured: bool, ark_configured: bool, deepseek_configured: bool}
     */
    private function configurationStatus(): array
    {
        return $this->configuration->status(SystemAiIdentity::forVisibilityAnalytics());
    }

    private function runDate(AiVisibilityRun $run): string
    {
        return ($run->completed_at ?? $run->created_at ?? now())->toDateString();
    }

    private function percent(int|float $numerator, int|float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 1);
    }
}
