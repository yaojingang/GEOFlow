<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeFacts\KnowledgeFactRequest;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactValue;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEditor;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactLibraryPresenter;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactPublisher;
use App\Support\AdminWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeFactController extends Controller
{
    public function index(
        Request $request,
        int $knowledgeBaseId,
        KnowledgeFactLibraryPresenter $presenter,
        AdminAiModelAccessResolver $modelAccessResolver,
    ): View {
        $knowledgeBase = KnowledgeBase::query()->with(['systemBinding', 'factLibrary.activeRevision'])->findOrFail($knowledgeBaseId);
        $library = $knowledgeBase->factLibrary()->firstOrCreate([]);
        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('q', ''));
        $facts = $library->facts()
            ->with(['values' => fn ($query) => $query->withCount('evidences')->with(['evidences' => fn ($evidences) => $evidences->select(['id', 'value_id', 'knowledge_chunk_id', 'excerpt', 'is_primary'])])])
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('label', 'like', '%'.$search.'%')
                ->orWhere('subject', 'like', '%'.$search.'%')
                ->orWhere('predicate', 'like', '%'.$search.'%')))
            ->when($status === 'pending', fn ($query) => $query->where('review_status', '!=', 'reviewed'))
            ->when($status === 'reviewed', fn ($query) => $query->where('review_status', 'reviewed'))
            ->when($status === 'conflict', fn ($query) => $query->whereHas('values', fn ($values) => $values->where('conflict_status', '!=', 'clear')))
            ->paginate(20)
            ->withQueryString();
        $library->setRelation('facts', $facts->getCollection());
        $library->load(['revisions' => fn ($query) => $query->limit(10)]);
        $activeRun = $library->generationRuns()->whereIn('status', ['queued', 'running'])->latest('id')->first();

        return view('admin.knowledge-bases.facts.index', [
            'pageTitle' => '原子事实工作台',
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'factLibrary' => $library,
            'facts' => $facts,
            'factSummary' => $presenter->summary($library),
            'publishReadiness' => $presenter->publishReadiness($library),
            'mergeTargets' => $library->facts()->where('is_enabled', true)->orderBy('label')->limit(200)->get(['id', 'label']),
            'factEvidenceChunks' => $knowledgeBase->chunks()->select(['id', 'knowledge_base_id', 'section_path', 'content_hash'])->orderBy('chunk_index')->limit(200)->get(),
            'factGenerationModels' => $modelAccessResolver
                ->usableQuery($this->admin($request))
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->get(['id', 'name']),
            'factGenerationRuns' => $library->generationRuns()->latest('id')->limit(10)->get(),
            'activeGenerationRun' => $activeRun ? $presenter->generationRun($activeRun, $knowledgeBaseId) : null,
            'systemReadOnly' => $knowledgeBase->isSystemManaged() && $request->user('admin')?->canManageProtectedWorkflows() !== true,
        ]);
    }

    public function store(KnowledgeFactRequest $request, int $knowledgeBaseId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $fact = $editor->createFact($library, $request->validated(), $this->admin($request));

        return $this->response($request, ['fact' => $fact], __('admin.knowledge_facts.message.saved'));
    }

    public function update(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $fact = $library->facts()->whereKey($factId)->firstOrFail();
        $fact = $editor->updateFact($library, $fact, $request->validated(), $this->admin($request));

        return $this->response($request, ['fact' => $fact], __('admin.knowledge_facts.message.saved'));
    }

    public function review(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $fact = $library->facts()->whereKey($factId)->firstOrFail();
        $fact = $editor->updateFact($library, $fact, $request->validated(), $this->admin($request));

        return $this->response($request, ['fact' => $fact], __('admin.knowledge_facts.message.saved'));
    }

    public function archive(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $fact = $library->facts()->whereKey($factId)->firstOrFail();
        $fact = $editor->updateFact($library, $fact, ['lock_version' => $request->integer('lock_version'), 'is_enabled' => false, 'review_status' => 'rejected'], $this->admin($request));

        return $this->response($request, ['fact' => $fact], __('admin.knowledge_facts.message.saved'));
    }

    public function storeValue(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $fact = $library->facts()->whereKey($factId)->firstOrFail();
        $value = $editor->createValue($library, $fact, $request->validated(), $this->admin($request));

        return $this->response($request, ['value' => $value], __('admin.knowledge_facts.message.saved'));
    }

    public function updateValue(KnowledgeFactRequest $request, int $knowledgeBaseId, int $valueId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $value = KnowledgeFactValue::query()->whereKey($valueId)->whereHas('fact', fn ($query) => $query->where('library_id', $library->id))->firstOrFail();

        return $this->response($request, ['value' => $editor->updateValue($library, $value, $request->validated(), $this->admin($request))], __('admin.knowledge_facts.message.saved'));
    }

    public function archiveValue(KnowledgeFactRequest $request, int $knowledgeBaseId, int $valueId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $value = KnowledgeFactValue::query()->whereKey($valueId)->whereHas('fact', fn ($query) => $query->where('library_id', $library->id))->firstOrFail();
        $value = $editor->updateValue($library, $value, ['lock_version' => $request->integer('lock_version'), 'review_status' => 'rejected', 'conflict_status' => 'resolved'], $this->admin($request));

        return $this->response($request, ['value' => $value], __('admin.knowledge_facts.message.saved'));
    }

    public function storeEvidence(KnowledgeFactRequest $request, int $knowledgeBaseId, int $valueId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $value = KnowledgeFactValue::query()->whereKey($valueId)->whereHas('fact', fn ($query) => $query->where('library_id', $library->id))->firstOrFail();
        $data = $request->validated();
        if (isset($data['knowledge_chunk_id'])) {
            $chunk = KnowledgeChunk::query()->whereKey($data['knowledge_chunk_id'])->where('knowledge_base_id', $knowledgeBaseId)->firstOrFail();
            $excerpt = mb_substr((string) $chunk->content, 0, 5000);
            $data = array_merge($data, [
                'source_hash' => (string) $chunk->source_hash,
                'content_hash' => (string) $chunk->content_hash,
                'source_locator_json' => ['section_path' => (string) $chunk->section_path],
                'excerpt' => $excerpt,
            ]);
        }

        return $this->response($request, ['evidence' => $editor->createEvidence($library, $value, $data, $this->admin($request))], __('admin.knowledge_facts.message.saved'));
    }

    public function merge(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $source = $library->facts()->whereKey($factId)->firstOrFail();
        $target = $library->facts()->whereKey($request->integer('target_fact_id'))->firstOrFail();
        $editor->merge($library, $source, $target);

        return $this->response($request, ['target_fact_id' => $target->id], __('admin.knowledge_facts.message.saved'));
    }

    public function split(KnowledgeFactRequest $request, int $knowledgeBaseId, int $factId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $source = $library->facts()->whereKey($factId)->firstOrFail();
        $data = $request->validated();
        $new = $editor->split($library, $source, $data['value_ids'], $data, $this->admin($request));

        return $this->response($request, ['fact' => $new], __('admin.knowledge_facts.message.saved'));
    }

    public function publish(KnowledgeFactRequest $request, int $knowledgeBaseId, KnowledgeFactPublisher $publisher): JsonResponse|RedirectResponse
    {
        $revision = $publisher->publish($this->library($knowledgeBaseId), $this->admin($request));

        return $this->response($request, ['revision' => $revision], __('admin.knowledge_facts.message.published'));
    }

    public function restore(KnowledgeFactRequest $request, int $knowledgeBaseId, int $revisionId, KnowledgeFactPublisher $publisher): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $revision = $library->revisions()->whereKey($revisionId)->firstOrFail();
        $restored = $publisher->restore($library, $revision, $this->admin($request));

        return $this->response($request, ['revision' => $restored], __('admin.knowledge_facts.message.restored'));
    }

    private function library(int $knowledgeBaseId): KnowledgeFactLibrary
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        return $knowledgeBase->factLibrary()->firstOrCreate([]);
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return $admin;
    }

    /** @param array<string,mixed> $payload */
    private function response(KnowledgeFactRequest $request, array $payload, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $payload]);
        }

        return back()->with('message', $message);
    }
}
