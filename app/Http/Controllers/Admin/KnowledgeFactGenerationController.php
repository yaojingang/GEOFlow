<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeFacts\KnowledgeFactGenerationRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class KnowledgeFactGenerationController extends Controller
{
    public function store(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $run = $coordinator->start($this->library($knowledgeBaseId), AiModel::query()->findOrFail($data['ai_model_id']), $this->admin($request), $data['mode'], (int) $data['target_count'], $data['request_key']);

        return $request->expectsJson() ? response()->json(['data' => ['run' => $run]], 202) : back()->with('message', __('admin.knowledge_facts.message.generation_started'));
    }

    public function show(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId): JsonResponse
    {
        return response()->json(['data' => ['run' => $this->run($knowledgeBaseId, $runId)]]);
    }

    public function cancel(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse|RedirectResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $coordinator->cancel($run);

        return $request->expectsJson() ? response()->json(['data' => ['run' => $run->fresh()]], 202) : back()->with('message', __('admin.knowledge_facts.message.generation_cancelled'));
    }

    public function resolve(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse|RedirectResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $data = $request->validated();
        $run = $coordinator->resolveConflict($run->id, $data['candidate_key'], $data['action'], $data['stable_key'] ?? null, $this->admin($request));

        return $request->expectsJson() ? response()->json(['data' => ['run' => $run]]) : back()->with('message', __('admin.knowledge_facts.message.conflict_resolved'));
    }

    private function library(int $knowledgeBaseId): KnowledgeFactLibrary
    {
        return KnowledgeBase::query()->findOrFail($knowledgeBaseId)->factLibrary()->firstOrCreate([]);
    }

    private function run(int $knowledgeBaseId, int $runId): KnowledgeFactGenerationRun
    {
        return KnowledgeBase::query()->findOrFail($knowledgeBaseId)->factLibrary()->firstOrFail()->generationRuns()->whereKey($runId)->firstOrFail();
    }

    private function admin(KnowledgeFactGenerationRequest $request): Admin
    { /** @var Admin $admin */ $admin = $request->user('admin');

        return $admin;
    }
}
