<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicImportRows;
use App\Services\Topics\TopicPayload;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTemplateCatalog;
use App\Support\TopicAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TopicBatchController extends Controller
{
    public function __construct(private readonly TopicBatchService $batches, private readonly AdminAiModelAccessResolver $models) {}

    public function create(Request $request): View
    {
        $site = TopicAdminContext::site($request);

        return view('admin.topics.batch-create', TopicAdminContext::viewData($site) + ['models' => $this->models->usableQuery(TopicAdminContext::actor())->get(['id', 'name']), 'categories' => Category::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])]);
    }

    private function settings(Request $request): array
    {
        $data = $request->validate(['mode' => ['required', 'in:ai,draft'], 'model_id' => ['required_if:mode,ai', 'nullable', 'integer', 'exists:ai_models,id'], 'template_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'], 'after' => ['required', 'in:auto_publish,draft_only,review_then_publish'], 'target_count' => ['required', 'integer', 'between:2,24'], 'rules' => ['nullable', 'string', 'max:5000'], 'category_ids' => ['nullable', 'array', 'max:100'], 'category_ids.*' => ['integer', 'exists:categories,id']]);
        app(TopicTemplateCatalog::class)->assertAvailableForSite(TopicAdminContext::site($request), $data['template_key']);
        $data['model_id'] = isset($data['model_id']) ? (int) $data['model_id'] : null;
        $data['target_count'] = (int) $data['target_count'];
        $data['category_ids'] = array_values(array_unique(array_map('intval', $data['category_ids'] ?? [])));
        $data['rules'] = (string) ($data['rules'] ?? '');
        if ($data['mode'] === 'ai') {
            $this->models->assertUsable(TopicAdminContext::actor(), AiModel::query()->findOrFail($data['model_id']));
        } else {
            $data['after'] = 'draft_only';
        }

        return $data;
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        $settings = $this->settings($request);
        $request->validate(['titles_text' => ['nullable', 'string', 'max:100000'], 'csv' => ['nullable', 'file', 'max:1024']]);
        $parser = app(TopicImportRows::class);
        if ($request->hasFile('csv')) {
            $inputs = $parser->csv(file_get_contents($request->file('csv')->getRealPath()));
        } else {
            $saved = $request->session()->get('topic_batch_form.'.$site, []);
            $text = trim((string) $request->input('titles_text', ''));
            $inputs = $text === ($saved['titles_text'] ?? null) && is_array($saved['inputs'] ?? null)
                ? $saved['inputs'] : array_map(fn ($title) => ['title' => $title, 'metadata' => []], preg_split('/\r\n|\r|\n/', $text));
        }
        if ($request->input('intent') === 'task') {
            return $this->transferToTask($request, $site, $inputs, $settings);
        }
        if (count($inputs) > 100 || $inputs === []) {
            throw ValidationException::withMessages(['titles_text' => '每批请填写 1 至 100 个专题标题。']);
        }
        $request->session()->put('topic_batch_form.'.$site, ['titles_text' => implode("\n", array_column($inputs, 'title')), 'titles' => array_column($inputs, 'title'), 'inputs' => $inputs] + $settings);
        $rows = [];
        $first = [];
        foreach ($inputs as $i => $input) {
            $key = TopicPayload::titleKey($input['title']);
            $error = null;
            try {
                $normalized = $parser->normalize($input);
                app(TopicTemplateCatalog::class)->assertAvailableForSite($site, $normalized['payload']['template_key'] ?? $settings['template_key'], 'metadata.template');
                $identity = app(TopicGenerationService::class)->identityPayload(array_replace($settings, $normalized['payload'], ['filters' => array_replace(['category_ids' => $settings['category_ids']], $normalized['filters'])]));
                $key = TopicPayload::topicKey($normalized['title'], $identity);
            } catch (ValidationException $exception) {
                $error = collect($exception->errors())->flatten()->implode('；');
            }
            $rows[] = $input + ['number' => $i + 1, 'key' => $key, 'error' => $error, 'duplicate_of' => $error === null ? ($first[$key] ?? null) : null];
            if ($error === null) {
                $first[$key] ??= $i + 1;
            }
        }
        $existing = Topic::withTrashed()->where('site_key', $site)->whereIn('normalized_title_key', array_column($rows, 'key'))->get()->keyBy('normalized_title_key');
        foreach ($rows as &$row) {
            $duplicateTopic = $existing[$row['key']] ?? null;
            $row['existing_url'] = $duplicateTopic ? route($duplicateTopic->trashed() ? 'admin.topics.history' : 'admin.topics.edit', ['topic' => $duplicateTopic->id]) : null;
            $row['existing_id'] = $duplicateTopic?->id;
            unset($row['key']);
        }
        unset($row);

        return view('admin.topics.batch-preview', TopicAdminContext::viewData($site) + ['rows' => $rows, 'settings' => $settings, 'requestKey' => (string) Str::uuid(), 'hasErrors' => collect($rows)->contains(fn ($row) => $row['error'] !== null), 'executableCount' => collect($rows)->filter(fn ($row) => $row['error'] === null && $row['duplicate_of'] === null)->count()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        $settings = $this->settings($request);
        $data = $request->validate(['titles' => ['required_without:rows', 'array', 'min:1', 'max:100'], 'titles.*' => ['required', 'string', 'max:200'], 'rows' => ['required_without:titles', 'array', 'min:1', 'max:100'], 'rows.*' => ['array:title,metadata'], 'request_key' => ['required', 'uuid'], 'intent' => ['nullable', 'in:batch,task']]);
        if (($data['intent'] ?? 'batch') === 'task') {
            return $this->transferToTask($request, $site, $data['rows'] ?? array_map(fn ($title) => ['title' => $title], $data['titles']), $settings);
        }
        $batch = $this->batches->create(TopicAdminContext::actor(), $site, $data['rows'] ?? $data['titles'], $settings, $data['request_key']);

        return redirect()->route('admin.topics.batches.show', ['batch' => $batch->id]);
    }

    private function transferToTask(Request $request, string $site, array $inputs, array $settings): RedirectResponse
    {
        if (count($inputs) > 100 || $inputs === []) {
            throw ValidationException::withMessages(['titles_text' => '每批请填写 1 至 100 个专题标题。']);
        }
        $valid = [];
        foreach ($inputs as $input) {
            try {
                $row = app(TopicImportRows::class)->taskTitle($input, $settings);
                app(TopicTemplateCatalog::class)->assertAvailableForSite($site, $row['payload']['template_key'] ?? $settings['template_key']);
                $valid[$row['topic_key']] ??= ['title' => $row['title'], 'metadata' => $row['metadata'], 'scope_label' => $row['scope_label'], 'library_title' => $row['library_title']];
            } catch (ValidationException) {
                // The original batch input remains available for correction.
            }
        }
        $request->session()->put('topic_batch_form.'.$site, ['titles_text' => implode("\n", array_column($inputs, 'title')), 'inputs' => $inputs] + $settings);
        if ($valid === []) {
            return redirect()->route('admin.topics.batches.create', ['site' => $site])->withErrors(['titles_text' => '当前没有可导入的有效标题，请修正有问题的行后继续。']);
        }
        $key = (string) Str::uuid();
        $request->session()->put('topic_batch_transfer.'.$key, ['owner_admin_id' => (int) TopicAdminContext::actor()->id, 'site' => $site, 'settings' => $settings, 'rows' => array_values($valid), 'ignored_count' => count($inputs) - count($valid), 'expires_at' => now()->addHour()->toIso8601String()]);

        return redirect()->route('admin.tasks.create', ['content_type' => 'topic', 'site' => $site, 'fromBatch' => $key]);
    }

    private function batch(int $id): TopicImportBatch
    {
        $batch = TopicImportBatch::query()->findOrFail($id);
        TopicAdminContext::site(request(), $batch->site_key);
        abort_unless((int) $batch->owner_admin_id === (int) TopicAdminContext::actor()->id || TopicAdminContext::actor()->isSuperAdmin(), 403);

        return $batch;
    }

    private function dto(TopicImportBatch $batch): array
    {
        $topics = Topic::withTrashed()->where('site_key', $batch->site_key)->whereIn('id', array_filter(array_column($batch->rows, 'topic_id')))->get()->keyBy('id');
        $runs = TopicBuildRun::query()->where('batch_id', $batch->id)->get()->keyBy('row_number');
        $rows = array_map(function (array $row) use ($topics, $runs, $batch): array {
            $safe = array_intersect_key($row, array_flip(['number', 'title', 'status', 'run_id', 'topic_id', 'duplicate_of', 'error']));
            $topic = $topics[$row['topic_id'] ?? null] ?? null;
            $state = null;
            if ($topic) {
                $state = $topic->trashed() ? 'trash' : ($topic->pending_revision_id ? ($topic->approved_revision_id === $topic->pending_revision_id ? 'scheduled' : 'pending') : ($topic->public_revision_id ? 'published' : 'draft'));
                if (! $topic->trashed()) {
                    $draft = app(TopicService::class)->previewView($topic);
                    if (($state === 'published' && ! app(TopicService::class)->publicView($topic)) || ($state === 'draft' && ($draft['article_count'] < 2 || trim($draft['intro']) === ''))) {
                        $state = 'waiting_content';
                    }
                }
            }

            return $safe + ['can_retry' => empty($row['validation_failed']) && (in_array($row['status'], ['failed', 'waiting_content', 'publication_failed'], true) || $this->batches->canRecoverRow($batch, $row, $runs[$row['number']] ?? null)), 'lease_expired' => $this->batches->canRecoverRow($batch, $row, $runs[$row['number']] ?? null), 'current_state' => $state, 'topic_url' => $topic ? route($topic->trashed() ? 'admin.topics.history' : 'admin.topics.edit', ['topic' => $topic->id]) : null];
        }, $batch->rows);

        return ['can_retry' => collect($rows)->contains('can_retry', true) && ! collect($rows)->contains(fn ($row) => $row['status'] === 'running' && ! $row['lease_expired']), 'counts' => collect($rows)->countBy('status')->all(), 'current_counts' => array_replace(['published' => 0, 'draft' => 0, 'pending' => 0, 'waiting_content' => 0], collect($rows)->whereNotNull('current_state')->unique('topic_id')->countBy('current_state')->all()), 'id' => $batch->id, 'status' => $batch->status, 'site' => $batch->site_key, 'rows' => $rows];
    }

    public function show(int $batch): View
    {
        $model = $this->batch($batch);

        return view('admin.topics.batch', TopicAdminContext::viewData($model->site_key) + ['batch' => $model, 'batchData' => $this->dto($model)]);
    }

    public function status(int $batch): JsonResponse
    {
        return response()->json($this->dto($this->batch($batch)))->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, int $batch, string $action): RedirectResponse
    {
        $model = $this->batch($batch);
        if ($action === 'cancel') {
            $this->batches->cancel($model);
        } else {
            $data = $request->validate(['row_numbers' => ['nullable', 'array', 'min:1', 'max:100'], 'row_numbers.*' => ['required', 'integer', 'min:1', 'max:100', 'distinct']]);
            $this->batches->retry($model, $action === 'continue', isset($data['row_numbers']) ? array_map('intval', $data['row_numbers']) : null);
        }

        return redirect()->route('admin.topics.batches.show', ['batch' => $batch]);
    }
}
