<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Support\TopicAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class TopicRunController extends Controller
{
    public function __construct(private readonly TopicGenerationService $generation) {}

    private function run(int $id): TopicBuildRun
    {
        $run = TopicBuildRun::query()->findOrFail($id);
        TopicAdminContext::site(request(), $run->site_key);
        abort_unless($run->owner_admin_id === TopicAdminContext::actor()->id || TopicAdminContext::actor()->isSuperAdmin(), 403);

        return $run;
    }

    private function dto(TopicBuildRun $run): array
    {
        return ['id' => $run->id, 'topic_id' => $run->topic_id, 'status' => $run->status, 'phase' => $run->phase, 'error' => $run->error, 'field' => $run->input['field'] ?? null, 'matching_report' => $this->matchingReport($run), 'can_retry' => $this->generation->canRetry($run), 'lease_expired' => $run->status === 'running' && $this->generation->canRetry($run), 'suggestions' => $run->status === 'needs_adoption' ? array_intersect_key($run->result ?? [], array_flip(['intro', 'summary', 'tags', 'articles', 'seo', 'faq', 'basic_info'])) : null, 'topic_url' => $run->topic_id ? route(Topic::withTrashed()->find($run->topic_id)?->trashed() ? 'admin.topics.history' : 'admin.topics.edit', ['topic' => $run->topic_id]) : null];
    }

    private function matchingReport(TopicBuildRun $run): ?array
    {
        $report = collect($run->telemetry ?? [])->last(fn ($entry) => ($entry['kind'] ?? null) === 'matching')['report'] ?? null;
        if (! is_array($report)) {
            return null;
        }
        $safe = array_intersect_key($report, array_flip(['version', 'mode', 'rules', 'base_terms', 'weights', 'scanned_count', 'eligible_count', 'matched_count', 'excluded_term_count', 'required_group_miss_count', 'distinct_count', 'candidate_count', 'ai_count', 'candidate_limit', 'ai_limit', 'scan_complete', 'truncated', 'ai_truncated']));
        $safe['matches'] = array_map(static fn (array $row): array => ['article_id' => (int) $row['article_id'], 'score' => (int) $row['score'], 'hits' => array_map(static fn (array $hit): array => array_intersect_key($hit, array_flip(['term', 'field', 'weight'])), $row['hits'] ?? [])], array_slice($report['matches'] ?? [], 0, 100));

        return $safe;
    }

    public function status(int $run): JsonResponse
    {
        return response()->json($this->dto($this->run($run)))->header('Cache-Control', 'private, no-store');
    }

    public function show(int $run): View
    {
        $model = $this->run($run);
        $topic = Topic::withTrashed()->find($model->topic_id);
        $sourceIds = array_unique(array_merge(array_column($topic?->draft_payload['articles'] ?? [], 'article_id'), array_column($model->result['articles'] ?? [], 'article_id'), array_column($this->matchingReport($model)['matches'] ?? [], 'article_id')));
        $sourceNames = app(SiteScopedArticleQuery::class)->queryForSiteKey($model->site_key)->useWritePdo()->with(['task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', $sourceIds)->get()->filter(fn (Article $a): bool => app(TopicService::class)->isEligibleScoped($a))->pluck('title', 'id')->all();

        return view('admin.topics.run', TopicAdminContext::viewData($model->site_key) + ['run' => $model, 'runData' => $this->dto($model), 'topic' => $topic, 'current' => $topic?->draft_payload ?? [], 'sourceNames' => $sourceNames]);
    }

    public function action(Request $request, int $run, string $action): RedirectResponse
    {
        $model = $this->run($run);
        if ($action === 'cancel') {
            $this->generation->cancel($model);
        } elseif ($action === 'retry') {
            if ($model->batch_id) {
                app(TopicBatchService::class)->retry(TopicImportBatch::query()->findOrFail($model->batch_id), false, [(int) $model->row_number]);

                return redirect()->route('admin.topics.batches.show', ['batch' => $model->batch_id]);
            }
            $this->generation->retry($model);
        } elseif ($action === 'ignore') {
            abort_unless(TopicBuildRun::query()->whereKey($model->id)->where('status', 'needs_adoption')->update(['status' => 'cancelled', 'finished_at' => now()]) === 1, 409, '本次建议已处理，请刷新页面。');
        } elseif ($action === 'adopt') {
            $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'fields' => [empty($model->input['field']) ? 'required' : 'nullable', 'array', 'min:1', 'max:7'], 'fields.*' => ['required', 'distinct', 'in:intro,summary,tags,articles,seo,faq,basic_info']]);
            $topic = Topic::query()->findOrFail($model->topic_id);
            Gate::forUser(TopicAdminContext::actor())->authorize('update', $topic);
            $this->generation->adopt($model, $topic, $request->integer('expected_version'), $request->input('fields'));

            return redirect()->route('admin.topics.edit', ['topic' => $topic->id])->with('message', 'AI 建议已采用到工作稿。')->with('topic_form_saved', true);
        }

        return redirect()->route('admin.topics.runs.show', ['run' => $run]);
    }
}
