<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeFacts\KnowledgeFactGenerationRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEditor;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use Illuminate\Http\JsonResponse;

class KnowledgeFactGenerationController extends Controller
{
    public function store(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse
    {
        $data = $request->validated();
        $run = $coordinator->start($this->library($knowledgeBaseId), AiModel::query()->findOrFail($data['ai_model_id']), $this->admin($request), $data['mode'], (int) $data['target_count']);

        return response()->json(['data' => ['run' => $run]], 202);
    }

    public function show(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId): JsonResponse
    {
        return response()->json(['data' => ['run' => $this->run($knowledgeBaseId, $runId)]]);
    }

    public function cancel(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $coordinator->cancel($run);

        return response()->json(['data' => ['run' => $run->fresh()]], 202);
    }

    public function resolve(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactEditor $editor): JsonResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $data = $request->validated();
        $result = (array) $run->result_json;
        $conflicts = array_values((array) ($result['conflicts'] ?? []));
        $candidate = $conflicts[$data['candidate_index']] ?? null;
        abort_unless(is_array($candidate), 404);
        $editor->resolveGeneratedCandidate($run->library, $candidate, $data['action'], $data['stable_key'] ?? null, $this->admin($request), $run->id);
        $result['resolved'][] = ['action' => $data['action'], 'stable_key' => $data['action'] === 'create_with_new_key' ? ($data['stable_key'] ?? '') : $candidate['stable_key']];
        array_splice($conflicts, (int) $data['candidate_index'], 1);
        $result['conflicts'] = $conflicts;
        $run->forceFill(['result_json' => $result, 'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))])->save();

        return response()->json(['data' => ['run' => $run->fresh()]]);
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
