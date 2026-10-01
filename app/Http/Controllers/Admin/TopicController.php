<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TopicEditorRequest;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Models\TopicSourceInvalidation;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Api\IdempotencyService;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSiteSettings;
use App\Services\Topics\TopicThemeCompatibility;
use App\Services\Topics\TopicViewBuilder;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\TopicAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TopicController extends Controller
{
    public function __construct(private readonly TopicService $topics, private readonly TopicGenerationService $generation, private readonly TopicSiteSettings $settings, private readonly SiteScopedArticleQuery $articlesQuery, private readonly AdminAiModelAccessResolver $models) {}

    public function index(Request $request): View
    {
        $site = TopicAdminContext::site($request);
        Gate::forUser(TopicAdminContext::actor())->authorize('viewAny', Topic::class);
        $query = Topic::query()->where('site_key', $site)->with(['task', 'publicRevision.articles', 'pendingRevision']);
        $status = (string) $request->query('status', '');
        if ($status === 'trash') {
            $query->onlyTrashed();
        } elseif ($status === 'pending') {
            $query->whereNotNull('pending_revision_id')->whereNull('approved_revision_id');
        } elseif ($status === 'scheduled') {
            $query->whereNotNull('approved_revision_id');
        } elseif ($status === 'published') {
            $query->whereNotNull('public_revision_id');
        } elseif ($status === 'withdrawn') {
            $query->whereNull('public_revision_id')->whereNotNull('withdrawn_at');
        } elseif ($status === 'draft') {
            $query->whereNull('public_revision_id')->whereNull('pending_revision_id')->whereNull('withdrawn_at');
        } elseif ($status === 'missing_sources') {
            $query->whereIn('id', TopicSourceInvalidation::query()->select('topic_id')->where(fn ($q) => $q->whereNull('topic_revision_id')->orWhereColumn('topic_revision_id', 'topics.public_revision_id')));
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('task_id')) {
            $query->where('task_id', $request->integer('task_id'));
        }
        $topics = $query->latest('id')->paginate(25)->withQueryString();
        $states = [];
        $topicDetails = [];
        $origins = TopicBuildRun::query()->whereIn('topic_id', $topics->pluck('id'))->where(fn ($q) => $q->where('owner_admin_id', TopicAdminContext::actor()->id)->when(TopicAdminContext::actor()->isSuperAdmin(), fn ($q) => $q->orWhereNotNull('owner_admin_id')))->latest('id')->get(['id', 'topic_id', 'task_id', 'batch_id', 'status'])->groupBy('topic_id');
        foreach ($topics as $topic) {
            $states[$topic->id] = $this->state($topic);
            if (! $topic->trashed()) {
                $draft = $this->topics->previewView($topic);
                $public = $this->topics->publicView($topic);
                $topicDetails[$topic->id] = ['count' => $draft['article_count'], 'scope' => trim((string) ($topic->draft_payload['summary']['scope'] ?? '')), 'intro' => mb_substr($draft['summary']['one_sentence'] ?: $draft['intro'], 0, 100), 'public_updated' => $public['modified_at'] ?? null, 'batch_id' => ($origins[$topic->id] ?? collect())->firstWhere('batch_id', '!=', null)?->batch_id, 'failed_run_id' => ($origins[$topic->id] ?? collect())->first(fn ($r) => in_array($r->status, ['failed', 'waiting_content', 'publication_failed'], true))?->id, 'origin' => $topic->task_id ? '任务' : (($origins[$topic->id] ?? collect())->contains(fn ($r) => $r->batch_id !== null) ? '批量创建' : '手工创建')];
                if ($topic->public_revision_id && ! $public) {
                    $states[$topic->id] = '来源待补充，公开页暂停';
                }
            }

        }

        return view('admin.topics.index', TopicAdminContext::viewData($site) + compact('topics', 'states', 'status', 'topicDetails') + ['models' => $this->models->usableQuery(TopicAdminContext::actor())->get(['id', 'name']), 'topicTasks' => Task::query()->where('content_type', 'topic')->where('target_site_key', $site)->orderBy('name')->get(['id', 'name'])]);
    }

    public function create(Request $request): View
    {
        return $this->editor(null, TopicAdminContext::site($request));
    }

    public function edit(Request $request, int $topic): View
    {
        $model = $this->topic($topic);

        return $this->editor($model, $model->site_key);
    }

    private function editor(?Topic $topic, string $site): View
    {
        $payload = $topic?->draft_payload ?? ['title' => '', 'intro' => '', 'articles' => [], 'summary' => [], 'tags' => [], 'template_key' => $this->settings->get($site)['default_template']];
        $selected = $this->articlesQuery->queryForSiteKey($site)->useWritePdo()->with(['category', 'author', 'task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', array_merge(array_column($payload['articles'] ?? [], 'article_id'), $payload['source_overrides']['excluded_article_ids'] ?? []))->get()->filter(fn (Article $a): bool => $this->topics->isEligibleScoped($a))->keyBy('id');
        $models = $this->models->usableQuery(TopicAdminContext::actor())->where(fn ($q) => $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat'))->get(['id', 'name']);
        $runs = $topic?->id ? TopicBuildRun::query()->where('topic_id', $topic->id)->where(fn ($q) => $q->where('owner_admin_id', TopicAdminContext::actor()->id)->when(TopicAdminContext::actor()->isSuperAdmin(), fn ($q) => $q->orWhereNotNull('owner_admin_id')))->latest()->limit(10)->get() : collect();

        $publicUrl = $topic?->public_revision_id ? ($this->topics->publicView($topic)['url'] ?? null) : null;

        return view('admin.topics.edit', TopicAdminContext::viewData($site) + compact('topic', 'payload', 'selected', 'models', 'runs', 'publicUrl') + ['sourceOptions' => $this->sourceOptions($selected, $site)] + ['formToken' => (string) Str::uuid(), 'listReturn' => $this->listReturn(request(), $site), 'state' => $topic ? $this->state($topic) : '草稿', 'settings' => $this->settings->get($site)]);
    }

    public function store(TopicEditorRequest $request): RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        $this->assertGenerationModel($request);
        if ($request->input('action') === 'publish') {
            return $this->saveAndPublish($request, null, $site);
        }
        $topic = $this->topics->create($site, $request->payload(), TopicAdminContext::actor()->id);

        return $this->afterSave($request, $topic, true);
    }

    public function update(TopicEditorRequest $request, int $topic): RedirectResponse
    {
        $model = $this->topic($topic, 'update');
        $this->assertGenerationModel($request);
        if ($request->input('action') === 'publish') {
            return $this->saveAndPublish($request, $model, $model->site_key);
        }
        $payload = $request->payload();
        if ($request->input('action') === 'verify_sources') {
            $ids = array_column($payload['articles'] ?? [], 'article_id');
            $sources = $this->articlesQuery->queryForSiteKey($model->site_key)->useWritePdo()->with(['task', 'latestRiskScan', 'latestTopicReview'])->whereIn('id', $ids)->get();
            $payload['source_hashes'] = $sources->filter(fn (Article $a) => $this->topics->isEligibleScoped($a))->mapWithKeys(fn (Article $a) => [$a->id => TopicService::contentHash($a)])->all();
        }
        $saved = $this->topics->save($model, $payload, $request->integer('expected_version'));

        return $this->afterSave($request, $saved, false);
    }

    private function assertGenerationModel(TopicEditorRequest $request): void
    {
        if ($request->input('action') === 'generate') {
            $this->models->assertUsable(TopicAdminContext::actor(), AiModel::query()->findOrFail($request->integer('model_id')));
        }
    }

    private function saveAndPublish(TopicEditorRequest $request, ?Topic $topic, string $site): RedirectResponse
    {
        $actor = TopicAdminContext::actor();
        $operation = Request::create($request->url(), 'POST', $request->except(['_token', '_method', 'request_key', 'list_return']));
        $operation->headers->set('X-Idempotency-Key', $request->string('request_key')->toString());
        $executed = false;
        try {
            $response = IdempotencyService::executeJson($operation, 'admin.topics.save_publish:'.$actor->id, function () use ($request, $topic, $site, $actor, &$executed): JsonResponse {
                $executed = true;
                $saved = $topic
                    ? $this->topics->save($topic, $request->payload(), $request->integer('expected_version'))
                    : $this->topics->create($site, $request->payload(), $actor->id);
                Gate::forUser($actor)->authorize('publish', $saved);
                try {
                    app(TopicThemeCompatibility::class)->ensure($site, $actor);
                    $this->topics->publish($saved, $saved->draft_version, $actor->id, false, $request->input('request_key'));
                    $result = ['message' => $saved->fresh()->pending_revision_id ? '当前内容已保存并提交审核。' : '当前内容已保存并发布。', 'errors' => []];
                } catch (ValidationException $error) {
                    $result = ['message' => '工作稿已保存，发布尚未完成，请处理下方提示。', 'errors' => $error->errors()];
                }

                return response()->json(['topic_id' => $saved->id] + $result);
            }, fingerprintContext: ['actor_id' => (int) $actor->id]);
        } catch (ApiException $error) {
            throw ValidationException::withMessages(['request_key' => $error->getMessage()])->status($error->getHttpStatus());
        }
        $result = $response->getData(true);
        $redirect = redirect()->route('admin.topics.edit', ['topic' => $result['topic_id'], 'list_return' => $this->listReturn($request, $site)])
            ->with('message', $executed ? $result['message'] : '该保存发布请求已处理，请查看当前专题状态。')->with('topic_form_saved', true);

        return $result['errors'] ? $redirect->withErrors($result['errors']) : $redirect;
    }

    private function afterSave(TopicEditorRequest $request, Topic $topic, bool $created): RedirectResponse
    {
        if ($request->input('action') === 'generate') {
            $request->validate(['model_id' => ['required', 'integer', 'exists:ai_models,id'], 'request_key' => ['required', 'uuid']]);
            $model = AiModel::query()->findOrFail($request->integer('model_id'));
            $this->models->assertUsable(TopicAdminContext::actor(), $model);
            $input = ['matching_rules' => $topic->draft_payload['matching_rules'] ?? [], 'title' => $topic->title, 'model_id' => $model->id, 'rules' => (string) $request->input('rules', ''), 'target_count' => $request->integer('target_count', 8), 'template_key' => $topic->draft_payload['template_key'], 'after' => 'draft_only', 'suggestion_only' => ! $created || trim($topic->draft_payload['intro']) !== '' || ! empty($topic->draft_payload['articles']) || ! empty($topic->draft_payload['tags']) || ! empty($topic->draft_payload['summary']['one_sentence']) || ! empty($topic->draft_payload['summary']['facts'])];
            if ($request->filled('field')) {
                $input['field'] = $request->input('field');
                $input['suggestion_only'] = true;
            }
            $run = $this->generation->prepare(TopicAdminContext::actor(), $topic->site_key, $input, (string) $request->input('request_key'), $topic);
            $this->generation->enqueue($run);

            return redirect()->route('admin.topics.runs.show', ['run' => $run->id])->with('topic_form_saved', true);
        }

        return redirect()->route($request->input('action') === 'preview' ? 'admin.topics.preview' : 'admin.topics.edit', ['topic' => $topic->id, 'list_return' => $this->listReturn($request, $topic->site_key)])->with('message', '工作稿已保存。')->with('topic_form_saved', true);
    }

    private function listReturn(Request $request, string $site): string
    {
        $default = route('admin.topics.index', ['site' => $site]);
        $target = parse_url((string) $request->input('list_return', $request->header('referer', '')));
        $expected = parse_url(route('admin.topics.index'));
        if (! is_array($target) || array_intersect_key($target, array_flip(['scheme', 'host', 'port', 'path'])) !== array_intersect_key($expected, array_flip(['scheme', 'host', 'port', 'path']))) {
            return $default;
        }
        parse_str($target['query'] ?? '', $query);
        if (isset($query['site']) && $query['site'] !== $site) {
            return $default;
        }
        $query = array_filter(array_intersect_key($query, array_flip(['status', 'search', 'task_id', 'page'])), static fn ($value): bool => is_string($value));

        return route('admin.topics.index', ['site' => $site] + $query);
    }

    public function articles(Request $request): View|JsonResponse
    {
        $site = TopicAdminContext::site($request);
        $request->validate(['category_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:200'], 'after' => ['nullable', 'date'], 'before' => ['nullable', 'date']]);
        $q = $this->articlesQuery->queryForSiteKey($site)->useWritePdo()->with(['category', 'author', 'task', 'latestRiskScan', 'latestTopicReview']);
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->integer('category_id'));
        }
        if ($request->filled('search')) {
            $q->where('title', 'like', '%'.$request->input('search').'%');
        }
        if ($request->filled('after')) {
            $q->whereDate('published_at', '>=', $request->input('after'));
        }
        if ($request->filled('before')) {
            $q->whereDate('published_at', '<=', $request->input('before'));
        }
        $articles = $q->latest('published_at')->paginate(40)->withQueryString();
        $articles->setCollection($articles->getCollection()->filter(fn (Article $a) => $this->topics->isEligibleScoped($a)));
        if ($request->expectsJson()) {
            return response()->json(['articles' => $this->sourceOptions($articles->getCollection(), $site), 'next' => $articles->nextPageUrl()]);
        }

        return view('admin.topics.articles', TopicAdminContext::viewData($site) + ['sourceOptions' => collect($this->sourceOptions($articles->getCollection(), $site))->keyBy('article_id'), 'articles' => $articles, 'categories' => Category::query()->get(['id', 'name']), 'formKey' => (string) $request->query('form_key', ''), 'returnUrl' => $request->query('topic') ? route('admin.topics.edit', ['topic' => $request->integer('topic'), 'list_return' => $this->listReturn($request, $site)]) : route('admin.topics.create', ['site' => $site, 'form_token' => $request->query('form_token'), 'list_return' => $this->listReturn($request, $site)])]);
    }

    public function action(Request $request, int $topic, string $action): RedirectResponse
    {
        $model = Topic::withTrashed()->findOrFail($topic);
        TopicAdminContext::site($request, $model->site_key);
        Gate::forUser(TopicAdminContext::actor())->authorize(match ($action) {
            'trash' => 'delete','restore' => 'restore','approve' => 'approve',default => 'publish'
        }, $model);
        $this->perform($request, $model, $action);

        return redirect()->route('admin.topics.index', ['site' => $model->site_key])->with('message', '专题状态已更新。');
    }

    private function perform(Request $request, Topic $topic, string $action): void
    {
        $actor = TopicAdminContext::actor()->id;
        if ($action === 'publish') {
            $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid']]);
            app(TopicThemeCompatibility::class)->ensure($topic->site_key, TopicAdminContext::actor());
            $this->topics->publish($topic, $request->integer('expected_version'), $actor, false, $request->input('request_id'));
        } elseif ($action === 'approve') {
            $request->validate(['expected_revision_id' => ['required', 'integer', 'min:1']]);
            app(TopicThemeCompatibility::class)->ensure($topic->site_key, TopicAdminContext::actor());
            $this->topics->approve($topic, $actor, $request->integer('expected_revision_id'));
        } elseif ($action === 'withdraw') {
            $request->validate(['expected_revision_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid']]);
            $this->topics->withdraw($topic, $actor, $request->integer('expected_revision_id'), $request->input('request_id'));
        } elseif ($action === 'trash') {
            $topic->delete();
        } elseif ($action === 'restore') {
            $topic->restore();
        }
    }

    public function bulk(Request $request): RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        $data = $request->validate(['topic_ids' => ['required', 'array', 'min:1', 'max:100'], 'topic_ids.*' => ['integer', 'distinct'], 'action' => ['required', 'in:publish,withdraw,trash,generate,retry_generation'], 'versions' => ['required', 'array'], 'request_id' => ['required', 'uuid'], 'model_id' => ['required_if:action,generate', 'nullable', 'integer', 'exists:ai_models,id'], 'rules' => ['nullable', 'string', 'max:5000'], 'target_count' => ['nullable', 'integer', 'between:2,24']]);
        if ($data['action'] === 'generate') {
            $this->models->assertUsable(TopicAdminContext::actor(), AiModel::query()->findOrFail($data['model_id']));
            $rows = [];
            foreach ($data['topic_ids'] as $id) {
                $rows[] = ['topic_id' => (int) $id, 'expected_version' => $data['versions'][$id]['draft'] ?? null];
            }
            $batch = app(TopicBatchService::class)->create(TopicAdminContext::actor(), $site, $rows, ['mode' => 'existing', 'model_id' => (int) $data['model_id'], 'rules' => (string) ($data['rules'] ?? ''), 'target_count' => (int) ($data['target_count'] ?? 8), 'after' => 'draft_only', 'suggestion_only' => true], $data['request_id']);

            return redirect()->route('admin.topics.batches.show', ['batch' => $batch->id]);
        }
        $results = [];
        foreach ($data['topic_ids'] as $id) {
            try {
                $topic = $this->topic((int) $id, $data['action'] === 'trash' ? 'delete' : ($data['action'] === 'retry_generation' ? 'update' : 'publish'));
                abort_unless($topic->site_key === $site, 404);
                $run = null;
                if ($data['action'] === 'retry_generation') {
                    $run = TopicBuildRun::query()->where('topic_id', $topic->id)->where(fn ($q) => $q->where('owner_admin_id', TopicAdminContext::actor()->id)->when(TopicAdminContext::actor()->isSuperAdmin(), fn ($q) => $q->orWhereNotNull('owner_admin_id')))->latest('id')->first();
                    if (! $run || ! in_array($run->status, ['failed', 'waiting_content', 'publication_failed'], true)) {
                        throw ValidationException::withMessages(['run' => '最近一次生成没有可重试的失败结果。']);
                    }
                    if (! ($run->input['suggestion_only'] ?? false) || ($run->input['after'] ?? '') !== 'draft_only') {
                        throw ValidationException::withMessages(['run' => '请到原生成记录或批次中按原配置重试。']);
                    }
                    if ($run->batch_id) {
                        $batch = TopicImportBatch::query()->findOrFail($run->batch_id);
                        app(TopicBatchService::class)->retry($batch, false, [(int) $run->row_number]);
                    } else {
                        $this->generation->retry($run);
                    }
                } else {
                    $version = $data['versions'][$id] ?? [];
                    $operation = new Request(['expected_version' => $version['draft'] ?? null, 'expected_revision_id' => $version['public'] ?? null, 'request_id' => substr($data['request_id'], 0, 24).str_pad(dechex((int) $id), 12, '0', STR_PAD_LEFT)]);
                    $this->perform($operation, $topic, $data['action']);
                }
                $results[] = ['id' => $id, 'url' => route($data['action'] === 'trash' ? 'admin.topics.history' : 'admin.topics.edit', ['topic' => $id]), 'run_url' => $run ? route('admin.topics.runs.show', ['run' => $run->id]) : null, 'ok' => true, 'message' => $run ? '已排队重试原建议，不改变人工工作稿。' : '已完成'];
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'url' => route('admin.topics.history', ['topic' => $id]), 'ok' => false, 'message' => $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : '未完成，请检查当前状态与权限。'];
            }
        }

        return redirect()->route('admin.topics.index', ['site' => $site])->with('bulk_results', $results);
    }

    private function sourceOptions(Collection $articles, string $site): array
    {
        $profile = $site === 'primary' ? null : HostedSiteProfile::query()->with('channel')->findOrFail((int) substr($site, 7));
        $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));

        return $articles->map(fn (Article $a): array => ['article_id' => $a->id, 'title' => $a->title, 'excerpt' => mb_substr(strip_tags((string) $a->excerpt), 0, 400), 'source' => $a->author?->name ?? '本站', 'status' => '可公开阅读', 'category' => $a->category?->name ?? '', 'published_at' => $a->published_at?->format('Y-m-d') ?? '', 'url' => $profile ? app(HostedSiteUrlGenerator::class)->article($profile, $a) : rtrim((string) config('geoflow.site_url', config('app.url')), '/').app(ArticlePermalinkService::class)->path($a, $policy)])->values()->all();
    }

    public function preview(Request $request, int $topic): Response
    {
        $model = $this->topic($topic);

        return $this->previewResponse($model, $this->topics->previewView($model));
    }

    public function history(Request $request, int $topic): View
    {
        $model = Topic::withTrashed()->findOrFail($topic);
        TopicAdminContext::site($request, $model->site_key);
        Gate::forUser(TopicAdminContext::actor())->authorize('view', $model);

        return view('admin.topics.history', TopicAdminContext::viewData($model->site_key) + ['topic' => $model, 'revisions' => $model->revisions()->with('articles')->paginate(25)]);
    }

    public function revision(Request $request, int $topic, int $revision): Response
    {
        $model = Topic::withTrashed()->findOrFail($topic);
        TopicAdminContext::site($request, $model->site_key);
        Gate::forUser(TopicAdminContext::actor())->authorize('view', $model);
        $rev = $model->revisions()->with('articles')->findOrFail($revision);

        return $this->previewResponse($model, app(TopicViewBuilder::class)->build($model, $rev->payload, $rev, true));
    }

    private function previewResponse(Topic $topic, array $view): Response
    {
        return response()->view('admin.topics.preview', TopicAdminContext::viewData($topic->site_key) + ['topic' => $topic, 'topicView' => $view])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function restoreRevision(Request $request, int $topic, int $revision): RedirectResponse
    {
        $model = $this->topic($topic, 'update');
        $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $this->topics->restoreRevision($model, $revision, $request->integer('expected_version'));

        return redirect()->route('admin.topics.edit', ['topic' => $topic])->with('message', '历史版本已恢复到工作稿。')->with('topic_form_saved', true);
    }

    private function topic(int $id, string $ability = 'view'): Topic
    {
        $topic = Topic::query()->findOrFail($id);
        TopicAdminContext::site(request(), $topic->site_key);
        Gate::forUser(TopicAdminContext::actor())->authorize($ability, $topic);

        return $topic;
    }

    private function state(Topic $topic): string
    {
        if ($topic->trashed()) {
            return '回收站';
        }
        if ($topic->approved_revision_id && $topic->approved_revision_id === $topic->pending_revision_id) {
            return '已审核，等待计划发布';
        }
        if ($topic->pending_revision_id) {
            return '待审核';
        }
        if (TopicSourceInvalidation::query()->where('topic_id', $topic->id)->where(fn ($q) => $q->whereNull('topic_revision_id')->when($topic->public_revision_id, fn ($q) => $q->orWhere('topic_revision_id', $topic->public_revision_id)))->exists()) {
            return '缺来源';
        }
        if ($topic->public_revision_id) {
            return '已公开';
        }
        if ($topic->withdrawn_at) {
            return '已撤回';
        }

        return '草稿';
    }
}
