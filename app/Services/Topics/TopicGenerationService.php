<?php

namespace App\Services\Topics;

use App\Jobs\ProcessTopicBuildJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\GeoFlow\AiExecutionAccessGuard;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\Site\SiteScopedArticleQuery;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicGenerationService
{
    public function __construct(private readonly TopicService $topics, private readonly SiteScopedArticleQuery $articles, private readonly TopicAiComposer $composer, private readonly AiExecutionAccessGuard $access, private readonly AiExecutionContextFactory $identities, private readonly AiExecutionErrorSanitizer $errors) {}

    /** @param array<string,mixed> $input */
    public function prepare(Admin $actor, string $siteKey, array $input, string $requestKey, ?Topic $topic = null, ?Task $task = null): TopicBuildRun
    {
        $input['declared_scope'] = $this->identityPayload($input, $topic)['summary']['scope'];
        $input['matching_rules'] = app(TopicMatchingRules::class)->normalize($input['matching_rules'] ?? $topic?->draft_payload['matching_rules'] ?? $task?->topic_settings['matching_rules'] ?? []);
        $identity = ['model_access_admin_id' => (int) $actor->id, 'model_access_admin_role' => $this->identities->normalizedRole($actor), 'ai_config_access_version' => max(1, (int) $actor->ai_config_access_version), 'resolver_policy_version' => 1];
        if ($topic && $topic->site_key !== $siteKey) {
            abort(404);
        }
        $this->topics->assertValidSite($siteKey);
        app(TopicTemplateCatalog::class)->assertAvailableForSite($siteKey, $input['template_key'] ?? $topic?->draft_payload['template_key'] ?? 'default');
        $run = TopicBuildRun::firstOrCreate(['request_key' => $requestKey], [
            'dispatch_key' => (string) Str::uuid(), 'site_key' => $siteKey, 'topic_id' => $topic?->id, 'task_id' => $task?->id, 'title_id' => $input['title_id'] ?? null, 'task_run_id' => $input['task_run_id'] ?? null,
            'batch_id' => $input['batch_id'] ?? null, 'row_number' => $input['row_number'] ?? null, 'owner_admin_id' => $actor->id, 'identity' => $identity, 'model_id' => $input['model_id'] ?? $task?->ai_model_id,
            'expected_execution_lease_token' => $input['execution_lease_token'] ?? null, 'expected_control_version' => $topic?->control_version, 'expected_version' => $topic?->draft_version, 'config_version' => $task?->topic_config_version, 'input' => $input, 'status' => 'pending', 'phase' => 'waiting',
        ]);
        if ((int) $run->owner_admin_id !== (int) $actor->id || $run->site_key !== $siteKey || $run->task_id !== $task?->id || ($topic && $run->topic_id !== $topic->id) || $run->input !== $input) {
            throw ValidationException::withMessages(['request_key' => '该请求已用于其他内容，请刷新提交。']);
        }

        return $run;
    }

    /** Declared scope stays fixed across AI wording, retries and each entry point. */
    public function identityPayload(array $input, ?Topic $topic = null): array
    {
        if ($topic) {
            $scope = (string) ($topic->draft_payload['summary']['scope'] ?? '');
        } elseif (array_key_exists('declared_scope', $input)) {
            $scope = (string) $input['declared_scope'];
        } else {
            $parts = [];
            $categories = array_values(array_unique(array_map('intval', $input['filters']['category_ids'] ?? $input['category_ids'] ?? [])));
            sort($categories);
            if ($categories !== []) {
                $parts[] = '来源分类 '.implode('、', array_map(fn ($id) => '#'.$id, $categories));
            }
            foreach (['after' => '发布时间起（含）', 'before' => '发布时间止（含）'] as $key => $label) {
                if (! empty($input['filters'][$key])) {
                    $date = $this->filterDate((string) $input['filters'][$key]);
                    $parts[] = $label.'：'.$date.(strlen($date) > 10 ? '（'.config('app.timezone', 'UTC').'）' : '');
                }
            }
            foreach ($input['basic_info'] ?? [] as $row) {
                if (in_array($row['label'] ?? '', ['目标读者', '适合人群', '来源范围', '来源覆盖期'], true) && trim((string) ($row['value'] ?? '')) !== '') {
                    $parts[] = $row['label'].'：'.trim($row['value']);
                }
            }
            $explicit = trim((string) ($input['summary']['scope'] ?? ''));
            if ($explicit !== '') {
                $parts[] = $explicit;
            }
            $scope = implode('；', array_unique($parts));
        }

        if (mb_strlen($scope) > 2000) {
            throw ValidationException::withMessages(['summary.scope' => '分类、人群和来源范围的说明合计最多 2000 字，请缩短本行配置后继续。']);
        }

        return ['summary' => ['scope' => $scope], 'freshness' => $input['freshness'] ?? $topic?->draft_payload['freshness'] ?? []];
    }

    private function filterDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return $date;
        }

        return CarbonImmutable::parse($date)->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    public function enqueue(TopicBuildRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($current->status !== 'pending') {
                return;
            }
            if (! $current->dispatch_key) {
                $current->update(['dispatch_key' => (string) Str::uuid()]);
            }
            ProcessTopicBuildJob::dispatch((int) $current->id, $current->dispatch_key)->onQueue('geoflow')->afterCommit();
        });
    }

    /** @return list<array<string,mixed>> */
    public function candidates(string $siteKey, string $title, array $filters = []): array
    {
        return $this->candidateSearch($siteKey, $title, $filters)['candidates'];
    }

    /** @return array{candidates:array,report:array} */
    public function candidateSearch(string $siteKey, string $title, array $filters = [], ?callable $guard = null): array
    {
        $matcher = app(TopicMatchingRules::class);
        $rules = $matcher->normalize($filters['matching_rules'] ?? []);
        $baseTerms = array_values(array_unique(array_merge($matcher->titleTerms($title), $rules['related_terms'])));
        $query = $this->articles->queryForSiteKey($siteKey)->useWritePdo()->with(['author', 'category', 'task', 'latestRiskScan', 'latestTopicReview']);
        if (! empty($filters['excluded_article_ids'])) {
            $query->whereNotIn('articles.id', $filters['excluded_article_ids']);
        }
        if (! empty($filters['category_ids'])) {
            $query->whereIn('category_id', $filters['category_ids']);
        }
        foreach (['after' => '>=', 'before' => '<='] as $date => $operator) {
            if (! empty($filters[$date])) {
                $value = $this->filterDate((string) $filters[$date]);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                    $query->whereDate('published_at', $operator, $value);
                } else {
                    $query->where('published_at', $operator, $value);
                }
            }
        }
        $best = [];
        $hashes = [];
        $report = ['version' => TopicMatchingRules::VERSION, 'mode' => 'automatic', 'rules' => $rules, 'base_terms' => $baseTerms, 'weights' => TopicMatchingRules::WEIGHTS, 'scanned_count' => 0, 'eligible_count' => 0, 'matched_count' => 0, 'excluded_term_count' => 0, 'required_group_miss_count' => 0, 'candidate_limit' => 100, 'ai_limit' => 40, 'scan_complete' => false];
        $compare = static fn (array $a, array $b): int => $b['relevance'] <=> $a['relevance'] ?: $b['published_timestamp'] <=> $a['published_timestamp'] ?: $b['article_id'] <=> $a['article_id'];
        $query->chunkById(200, function ($articles) use (&$best, &$hashes, &$report, $matcher, $rules, $baseTerms, $compare, $guard): void {
            $guard?->__invoke();
            foreach ($articles as $article) {
                $report['scanned_count']++;
                if (! $this->topics->isEligibleScoped($article)) {
                    continue;
                }
                $report['eligible_count']++;
                $match = $matcher->match($article, $rules, $baseTerms);
                if (! $match['matched']) {
                    if ($match['reason'] === 'excluded_term') {
                        $report['excluded_term_count']++;
                    } elseif ($match['reason'] === 'required_group') {
                        $report['required_group_miss_count']++;
                    }

                    continue;
                }
                $report['matched_count']++;
                $bodyHash = TopicService::bodyHash($article);
                $hashes[$bodyHash] = true;
                $candidate = ['article_id' => (int) $article->id, 'title' => $article->title, 'excerpt' => mb_substr(strip_tags((string) $article->excerpt), 0, 400), 'content' => $match['content'], 'source_hash' => TopicService::contentHash($article), 'body_hash' => $bodyHash, 'relevance' => $match['score'], 'hits' => $match['hits'], 'published_timestamp' => $article->published_at?->getTimestamp() ?? 0];
                if (! isset($best[$bodyHash]) || $compare($candidate, $best[$bodyHash]) < 0) {
                    $best[$bodyHash] = $candidate;
                }
                uasort($best, $compare);
                $best = array_slice($best, 0, 100, true);
            }
        }, 'articles.id', 'id');
        $candidates = array_values($best);
        $report += ['distinct_count' => count($hashes), 'candidate_count' => count($candidates), 'ai_count' => min(40, count($candidates)), 'truncated' => count($hashes) > 100, 'ai_truncated' => count($candidates) > 40, 'matches' => array_map(static fn ($row): array => ['article_id' => $row['article_id'], 'score' => $row['relevance'], 'hits' => $row['hits']], $candidates)];
        $report['scan_complete'] = true;

        return ['candidates' => $candidates, 'report' => $report];
    }

    public function process(int $runId, ?string $dispatchKey = null): TopicBuildRun
    {
        $token = (string) Str::uuid();
        $run = DB::transaction(function () use ($runId, $token, $dispatchKey): TopicBuildRun {
            $run = TopicBuildRun::query()->lockForUpdate()->findOrFail($runId);
            if ($dispatchKey !== null && $run->dispatch_key !== $dispatchKey) {
                return $run;
            }
            if (in_array($run->status, ['completed', 'needs_adoption', 'duplicate', 'cancelled'], true) || ($run->status === 'running' && $run->lease_expires_at?->isFuture())) {
                return $run;
            }
            $run->update(['status' => 'running', 'phase' => ($run->phase === 'publishing' || $run->status === 'publication_failed') ? 'publishing' : 'matching', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes(6), 'error' => null]);

            return $run;
        });
        if ($run->lease_token !== $token) {
            return $run;
        }
        try {
            $this->assertCurrent($run);
            $existing = $run->topic_id ? null : Topic::withTrashed()->where('site_key', $run->site_key)->where('normalized_title_key', TopicPayload::topicKey((string) $run->input['title'], $this->identityPayload($run->input)))->first();
            if ($existing) {
                $this->persistOwned($run, $token, ['status' => 'duplicate', 'topic_id' => $existing->id, 'phase' => 'finished', 'error' => $existing->trashed() ? '同主题专题在回收站，已保留其状态。' : '同主题专题已存在，已跳过。', 'finished_at' => now()]);
                $run->refresh();

                return $run;
            }
            $result = $run->result;
            if (! is_array($result)) {
                $filters = $run->input['filters'] ?? [];
                $filters['matching_rules'] = $run->input['matching_rules'] ?? app(TopicMatchingRules::class)->normalize([]);
                if ($run->topic_id) {
                    $filters['excluded_article_ids'] = Topic::query()->findOrFail($run->topic_id)->draft_payload['source_overrides']['excluded_article_ids'] ?? [];
                }
                $guard = function () use ($run, $token): void {
                    $current = $run->fresh();
                    if ($current->status !== 'running' || $current->lease_token !== $token) {
                        throw ValidationException::withMessages(['task' => '本次生成已取消或租约已改变，已停止后续匹配。']);
                    }
                    $this->assertCurrent($current);
                };
                if (isset($run->input['field']) && $run->input['field'] !== 'articles' && $run->topic_id) {
                    $topic = Topic::query()->findOrFail($run->topic_id);
                    $ids = array_column($topic->draft_payload['articles'], 'article_id');
                    $sources = $this->articles->queryForSiteKey($run->site_key)->useWritePdo()->with(['task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', $ids)->get();
                    if ($sources->count() !== count($ids)) {
                        throw ValidationException::withMessages(['articles' => '来源已变化，请先核对来源后获取单字段建议。']);
                    }
                    foreach ($sources as $a) {
                        if (! $this->topics->isEligibleScoped($a) || ! hash_equals((string) ($topic->draft_source_hashes[$a->id] ?? ''), TopicService::contentHash($a))) {
                            throw ValidationException::withMessages(['articles' => '来源已变化，请先核对来源后获取单字段建议。']);
                        }
                    }
                    $candidates = $sources->unique(fn ($a) => TopicService::bodyHash($a))->map(fn (Article $a): array => ['article_id' => $a->id, 'title' => $a->title, 'excerpt' => mb_substr(strip_tags((string) $a->excerpt), 0, 400), 'content' => mb_substr(strip_tags($a->content), 0, 1200), 'source_hash' => TopicService::contentHash($a)])->values()->all();
                    $report = ['version' => TopicMatchingRules::VERSION, 'mode' => 'manual_selection', 'rules' => $run->input['matching_rules'] ?? app(TopicMatchingRules::class)->normalize([]), 'weights' => TopicMatchingRules::WEIGHTS, 'scanned_count' => $sources->count(), 'eligible_count' => $sources->count(), 'matched_count' => count($candidates), 'distinct_count' => count($candidates), 'candidate_count' => count($candidates), 'ai_count' => count($candidates), 'candidate_limit' => 100, 'ai_limit' => 40, 'scan_complete' => true, 'truncated' => false, 'ai_truncated' => count($candidates) > 40, 'matches' => []];
                } else {
                    $selection = $this->candidateSearch($run->site_key, (string) $run->input['title'], $filters, $guard);
                    $candidates = $selection['candidates'];
                    $report = $selection['report'];
                }
                $candidates = array_slice($candidates, 0, 40);
                $report['ai_count'] = count($candidates);
                DB::transaction(function () use ($run, $token, $report): void {
                    $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
                    if ($current->status === 'running' && $current->lease_token === $token) {
                        $telemetry = $current->telemetry ?? [];
                        $telemetry[] = ['kind' => 'matching', 'at' => now()->toIso8601String(), 'report' => $report];
                        $current->update(['telemetry' => $telemetry]);
                    }
                });
                if (count($candidates) < 2) {
                    throw ValidationException::withMessages(['articles' => '本站相关公开文章不足两篇，可以手工补充选文后重试。']);
                }
                if (! $this->persistOwned($run, $token, ['phase' => 'generating'])) {
                    return $run->fresh();
                }
                $result = $this->composer->compose($run, $candidates, function () use ($run, $token): void {
                    $current = $run->fresh();
                    if ($current->status !== 'running' || $current->lease_token !== $token) {
                        throw ValidationException::withMessages(['task' => '本次生成已取消或租约已改变，已停止后续 AI 调用。']);
                    }
                    $this->assertCurrent($current);
                });
                if (array_key_exists('summary', $result)) {
                    $scope = (string) ($run->input['declared_scope'] ?? '');
                    $aiScope = trim((string) ($result['summary']['scope'] ?? ''));
                    if ($aiScope !== '' && $aiScope !== $scope) {
                        $result['summary']['reading_advice'] = mb_substr(trim(($result['summary']['reading_advice'] ?? '').' '.$aiScope), 0, 4000);
                    }
                    $result['summary']['scope'] = $scope;
                }
                $result['source_hashes'] = array_column($candidates, 'source_hash', 'article_id');
                if (! $this->persistOwned($run, $token, ['result' => $result, 'phase' => 'saving'])) {
                    return $run->fresh();
                }
            }
            $saved = DB::transaction(function () use ($run, $result, $token): TopicBuildRun {
                if ($run->task_id) {
                    Task::query()->whereKey($run->task_id)->lockForUpdate()->firstOrFail();
                    $this->assertExecutionLease($run, true);
                }
                if ($run->batch_id) {
                    TopicImportBatch::query()->whereKey($run->batch_id)->lockForUpdate()->firstOrFail();
                }
                $fresh = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($fresh->lease_token !== $token || $fresh->status !== 'running') {
                    return $fresh;
                }
                $this->assertCurrent($fresh);
                $ids = array_column($result['articles'], 'article_id');
                $current = $this->articles->queryForSiteKey($fresh->site_key)->useWritePdo()->with(['task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', $ids)->get()->filter(fn (Article $a): bool => $this->topics->isEligibleScoped($a))->keyBy('id');
                foreach ($ids as $id) {
                    if (! $current->has($id) || ! hash_equals((string) ($result['source_hashes'][$id] ?? ''), TopicService::contentHash($current[$id]))) {
                        throw ValidationException::withMessages(['articles' => '生成期间来源发生变化，请重新匹配文章。']);
                    }
                }
                $topic = $fresh->topic_id ? Topic::query()->lockForUpdate()->find($fresh->topic_id) : null;
                $this->assertCurrent($fresh);
                if ($fresh->topic_id && ! $topic) {
                    $fresh->update(['status' => 'cancelled', 'phase' => 'finished', 'error' => '专题已移入回收站，生成结果保持未公开。']);

                    return $fresh;
                }
                if ($topic && ((int) $topic->draft_version !== (int) $fresh->expected_version || ($fresh->input['suggestion_only'] ?? false))) {
                    $fresh->update(['status' => 'needs_adoption', 'phase' => 'finished', 'finished_at' => now()]);

                    return $fresh;
                }
                $payload = array_replace($topic?->draft_payload ?? [], array_intersect_key($result, array_flip(['intro', 'summary', 'tags', 'articles', 'source_hashes', 'seo', 'faq', 'basic_info'])), ['automatic_write' => true, 'title' => $fresh->input['title'], 'template_key' => $fresh->input['template_key'] ?? ($topic?->draft_payload['template_key'] ?? 'default')]);
                if (! $topic) {
                    $payload['matching_rules'] = $fresh->input['matching_rules'] ?? app(TopicMatchingRules::class)->normalize([]);
                }
                if (array_key_exists('basic_info', $fresh->input)) {
                    $payload['basic_info'] = $fresh->input['basic_info'];
                }
                if (array_key_exists('tags', $fresh->input)) {
                    $payload['tags'] = $fresh->input['tags'];
                }
                if (array_key_exists('freshness', $fresh->input)) {
                    $payload['freshness'] = $fresh->input['freshness'];
                }
                if ($fresh->phase === 'publishing' && $topic) {
                    return $fresh;
                }
                if (! $topic) {
                    $duplicate = Topic::withTrashed()->where('site_key', $fresh->site_key)->where('normalized_title_key', TopicPayload::topicKey($payload['title'], $payload))->first();
                    if ($duplicate) {
                        $fresh->update(['status' => 'duplicate', 'topic_id' => $duplicate->id, 'phase' => 'finished', 'finished_at' => now()]);

                        return $fresh;
                    }
                    $topic = $this->topics->create($fresh->site_key, $payload + ['task_id' => $fresh->task_id], (int) $fresh->owner_admin_id);
                } else {
                    $topic = $this->topics->save($topic, $payload, (int) $fresh->expected_version);
                }
                $fresh->update(['topic_id' => $topic->id, 'expected_version' => $topic->draft_version, 'expected_control_version' => $topic->control_version]);
                $after = $fresh->input['after'] ?? 'draft_only';
                if (in_array($after, ['auto_publish', 'review_then_publish'], true) && (! ($fresh->input['defer_publication'] ?? false) || $after === 'review_then_publish')) {
                    $fresh->update(['phase' => 'publishing']);

                    return $fresh;
                }
                $fresh->update(['status' => 'completed', 'phase' => 'finished', 'finished_at' => now()]);

                return $fresh;
            });
            if ($saved->phase === 'publishing' && $saved->status === 'running') {
                app(TopicThemeCompatibility::class)->ensure($saved->site_key, Admin::query()->findOrFail($saved->owner_admin_id));

                return DB::transaction(function () use ($saved, $token): TopicBuildRun {
                    if ($saved->task_id) {
                        Task::query()->whereKey($saved->task_id)->lockForUpdate()->firstOrFail();
                        $this->assertExecutionLease($saved, true);
                    }
                    if ($saved->batch_id) {
                        TopicImportBatch::query()->whereKey($saved->batch_id)->lockForUpdate()->firstOrFail();
                    }
                    $fresh = TopicBuildRun::query()->lockForUpdate()->findOrFail($saved->id);
                    if ($fresh->lease_token !== $token || $fresh->status !== 'running') {
                        return $fresh;
                    }$this->assertCurrent($fresh);
                    $topic = Topic::query()->lockForUpdate()->findOrFail($fresh->topic_id);
                    $this->assertCurrent($fresh);
                    $this->topics->publish($topic, (int) $fresh->expected_version, (int) $fresh->owner_admin_id, ($fresh->input['after'] ?? '') === 'review_then_publish', 'build:'.$fresh->id);
                    $fresh->update(['status' => 'completed', 'phase' => 'finished', 'finished_at' => now()]);

                    return $fresh;
                });
            }

            return $saved;
        } catch (\Throwable $e) {
            $fresh = $run->fresh();
            if ($fresh->lease_token !== $token || $fresh->status === 'cancelled') {
                return $fresh;
            }
            if ($e instanceof ValidationException && isset($e->errors()['title']) && ! $fresh->topic_id) {
                $duplicate = Topic::withTrashed()->where('site_key', $fresh->site_key)->where('normalized_title_key', TopicPayload::topicKey($fresh->input['title'], $this->identityPayload($fresh->input)))->first();
                if ($duplicate) {
                    $this->persistOwned($fresh, $token, ['status' => 'duplicate', 'topic_id' => $duplicate->id, 'phase' => 'finished', 'error' => '同主题专题已存在，已关联首次结果。', 'finished_at' => now()]);

                    return $fresh->fresh();
                }
            }
            $message = $e instanceof ValidationException ? implode(' ', array_map(fn (array $m): string => $m[0], $e->errors())) : $this->errors->sanitize($e, '生成暂未完成，请检查 AI 配置后重试。');
            $status = $fresh->phase === 'publishing' ? 'publication_failed' : ($e instanceof ValidationException && isset($e->errors()['articles']) ? 'waiting_content' : 'failed');
            if (! $fresh->topic_id && ! ($e instanceof ValidationException && (isset($e->errors()['task']) || isset($e->errors()['batch'])))) {
                DB::transaction(function () use ($fresh, $token): void {
                    if ($fresh->task_id) {
                        Task::query()->whereKey($fresh->task_id)->lockForUpdate()->firstOrFail();
                        $this->assertExecutionLease($fresh, true);
                    }
                    if ($fresh->batch_id) {
                        TopicImportBatch::query()->whereKey($fresh->batch_id)->lockForUpdate()->firstOrFail();
                    }
                    $r = TopicBuildRun::query()->lockForUpdate()->findOrFail($fresh->id);
                    if ($r->lease_token !== $token || $r->status !== 'running') {
                        return;
                    }
                    try {
                        $this->assertCurrent($r);
                    } catch (\Throwable) {
                        return;
                    }
                    $existing = Topic::withTrashed()->where('site_key', $r->site_key)->where('normalized_title_key', TopicPayload::topicKey($r->input['title'], $this->identityPayload($r->input)))->first();
                    if ($existing) {
                        return;
                    }
                    $draft = $this->topics->create($r->site_key, array_replace(['title' => $r->input['title'], 'template_key' => $r->input['template_key'] ?? 'default', 'task_id' => $r->task_id], $this->identityPayload($r->input), array_intersect_key($r->input, array_flip(['basic_info', 'tags', 'freshness', 'matching_rules']))), (int) $r->owner_admin_id);
                    $r->update(['topic_id' => $draft->id, 'expected_version' => $draft->draft_version, 'expected_control_version' => $draft->control_version]);
                });
                $fresh = $fresh->fresh();
            }
            if ($e instanceof ValidationException && (isset($e->errors()['task']) || isset($e->errors()['batch']))) {
                $status = 'cancelled';
            }
            $this->persistOwned($fresh, $token, ['status' => $status, 'phase' => 'finished', 'error' => $message, 'finished_at' => now()]);

            return $fresh->fresh();
        }
    }

    public function cancel(TopicBuildRun $run): void
    {
        TopicBuildRun::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'phase' => 'finished', 'lease_token' => null, 'finished_at' => now()]);
    }

    public function canRetry(TopicBuildRun $run): bool
    {
        return in_array($run->status, ['failed', 'waiting_content', 'publication_failed'], true)
            || ($run->status === 'cancelled' && $run->task_id && Task::query()->whereKey($run->task_id)->where('status', 'active')->where('schedule_enabled', 1)->where('topic_config_version', $run->config_version)->exists())
            || ($run->status === 'running' && ($run->lease_expires_at === null || $run->lease_expires_at->lessThanOrEqualTo(now())));
    }

    public function retry(TopicBuildRun $run): void
    {
        if ($run->task_id) {
            app(TopicTaskService::class)->retryGeneration($run);

            return;
        }
        $ready = DB::transaction(function () use ($run): ?TopicBuildRun {
            $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $this->canRetry($current)) {
                return null;
            }
            $attributes = ['dispatch_key' => (string) Str::uuid(), 'status' => 'pending', 'phase' => $current->phase === 'publishing' || $current->status === 'publication_failed' ? 'publishing' : 'waiting', 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => null, 'error' => null];
            if ($current->result) {
                try {
                    $this->assertSources($current);
                } catch (ValidationException) {
                    $attributes['result'] = null;
                    $attributes['phase'] = 'waiting';
                }
            }
            $current->update($attributes);

            return $current;
        });
        if ($ready) {
            $this->enqueue($ready);
        }
    }

    private function persistOwned(TopicBuildRun $run, string $token, array $attributes): bool
    {
        return DB::transaction(function () use ($run, $token, $attributes): bool {
            $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($current->status !== 'running' || $current->lease_token !== $token) {
                return false;
            }
            $current->update($attributes);

            return true;
        });
    }

    public function adopt(TopicBuildRun $run, Topic $topic, int $expected, ?array $selectedFields = null): Topic
    {
        $allowed = ['intro', 'summary', 'tags', 'articles', 'seo', 'faq', 'basic_info'];
        $field = $run->input['field'] ?? null;
        $fields = $selectedFields ?? (in_array($field, $allowed, true) ? [$field] : array_values(array_intersect($allowed, array_keys($run->result ?? []))));
        if ($fields === [] || array_diff($fields, $allowed) !== [] || ($field && $fields !== [$field])) {
            throw ValidationException::withMessages(['fields' => '请选择本次生成允许采用的内容块。']);
        }
        $this->access->assertPersistedAdminSnapshot($run->identity);

        return DB::transaction(function () use ($run, $topic, $expected, $fields): Topic {
            $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($current->topic_id !== $topic->id || $current->status !== 'needs_adoption') {
                abort(409, '本次建议已处理，请刷新页面。');
            }
            if (array_diff($fields, array_keys($current->result ?? [])) !== []) {
                throw ValidationException::withMessages(['fields' => '本次建议没有所选内容块，请重新生成或选择已有内容。']);
            }
            $this->assertSources($current);
            $topic = Topic::query()->findOrFail($topic->id);
            $payload = array_replace($topic->draft_payload, array_intersect_key($current->result ?? [], array_flip($fields)));
            $ids = array_column($payload['articles'] ?? [], 'article_id');
            $excluded = $payload['source_overrides']['excluded_article_ids'] ?? [];
            if (array_intersect($ids, $excluded) !== []) {
                throw ValidationException::withMessages(['fields' => '建议包含永久排除的文章，请先在选文中明确解除排除，再获取建议。']);
            }
            foreach (['summary.facts', 'faq', 'score.evidence'] as $path) {
                foreach (data_get($payload, $path, []) as $claim) {
                    if (array_diff($claim['article_ids'] ?? [], $ids) !== []) {
                        throw ValidationException::withMessages(['fields' => '采用后有事实、问答或评分依据引用未选文章。请同时选择依赖的选文，或先手工调整引用。']);
                    }
                }
            }
            $payload['source_hashes'] = $topic->draft_source_hashes ?? [];
            if (in_array('articles', $fields, true)) {
                foreach ($ids as $id) {
                    $payload['source_hashes'][$id] = $current->result['source_hashes'][$id] ?? '';
                }
            }
            $saved = $this->topics->save($topic, $payload, $expected);
            $current->update(['status' => 'completed', 'finished_at' => now()]);

            return $saved;
        });
    }

    public function assertSources(TopicBuildRun $run): void
    {
        $result = $run->result ?? [];
        $ids = array_column($result['articles'] ?? [], 'article_id');
        $current = $this->articles->queryForSiteKey($run->site_key)->useWritePdo()->with(['task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! $current->has($id) || ! $this->topics->isEligibleScoped($current[$id]) || ! hash_equals((string) ($result['source_hashes'][$id] ?? ''), TopicService::contentHash($current[$id]))) {
                throw ValidationException::withMessages(['articles' => '来源已变化，请获取新建议。']);
            }
        }
    }

    private function assertExecutionLease(TopicBuildRun $run, bool $lock = false): void
    {
        if (! $run->task_id) {
            return;
        }
        $q = TaskRun::query()->whereKey($run->task_run_id)->where('task_id', $run->task_id)->where('status', 'running')->where('execution_lease_token', $run->expected_execution_lease_token);
        if ($lock) {
            $q->lockForUpdate();
        }
        if (! $run->expected_execution_lease_token || ! $q->first()) {
            throw ValidationException::withMessages(['task' => '原执行已结束或被新的执行接管，本次结果保持未提交。']);
        }
    }

    private function assertCurrent(TopicBuildRun $run): void
    {
        if ($run->task_id) {
            $this->topics->assertPublishingEnabled($run->site_key);
        }
        $this->assertExecutionLease($run);
        if ($run->topic_id) {
            $topic = Topic::query()->find($run->topic_id);
            if (! $topic || (int) $topic->control_version !== (int) $run->expected_control_version) {
                throw ValidationException::withMessages(['task' => '专题已撤回、暂停维护或移入回收站，本次结果保持未提交。']);
            }
        }
        $this->access->assertPersistedAdminSnapshot($run->identity, $run->task_id ? (int) $run->task_id : null);
        if ($run->task_id) {
            $task = Task::query()->find($run->task_id);
            if (! $task || $task->status !== 'active' || ! $task->schedule_enabled || $task->topic_config_version !== (int) $run->config_version) {
                throw ValidationException::withMessages(['task' => '任务已暂停或设置已更新，本次结果保持未提交。']);
            }
        }
        if ($run->batch_id) {
            $batch = TopicImportBatch::query()->find($run->batch_id);
            if (! $batch || $batch->status === 'cancelled' || $batch->generation !== (int) ($run->input['batch_generation'] ?? 0)) {
                throw ValidationException::withMessages(['batch' => '批次已停止，本次结果保持未提交。']);
            }
        }
    }
}
