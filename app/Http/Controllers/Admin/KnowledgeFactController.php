<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeFacts\KnowledgeFactRequest;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactValue;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEditor;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class KnowledgeFactController extends Controller
{
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

    public function storeEvidence(KnowledgeFactRequest $request, int $knowledgeBaseId, int $valueId, KnowledgeFactEditor $editor): JsonResponse|RedirectResponse
    {
        $library = $this->library($knowledgeBaseId);
        $value = KnowledgeFactValue::query()->whereKey($valueId)->whereHas('fact', fn ($query) => $query->where('library_id', $library->id))->firstOrFail();
        $data = $request->validated();
        if (isset($data['knowledge_chunk_id'])) {
            abort_unless(KnowledgeBase::query()->whereKey($knowledgeBaseId)->whereHas('chunks', fn ($query) => $query->whereKey($data['knowledge_chunk_id']))->exists(), 404);
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

    private function admin(KnowledgeFactRequest $request): Admin
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
