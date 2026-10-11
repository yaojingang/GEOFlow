<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\AiModelRuntimeEligibilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestAiPromptRequest;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\PromptTestRunStore;
use App\Services\GeoFlow\PromptTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiPromptTestController extends Controller
{
    public function __construct(
        private readonly PromptTestService $service,
        private readonly PromptTestRunStore $runs,
        private readonly AdminAiModelAccessResolver $models,
    ) {}

    public function options(Request $request, int $promptId): JsonResponse
    {
        $this->prompt($promptId);
        $input = $request->validate([
            'kind' => ['nullable', Rule::in(['all', 'knowledge', 'titles'])],
            'q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1'],
            'library_id' => ['nullable', 'integer', 'exists:title_libraries,id'],
        ]);
        $kind = $input['kind'] ?? 'all';
        $search = $input['q'] ?? '';
        if ($kind === 'titles') {
            $titles = Title::query()->select(['id', 'title', 'keyword', 'library_id'])
                ->when(isset($input['library_id']), fn ($query) => $query->where('library_id', $input['library_id']))
                ->when($search !== '', fn ($query) => $query->whereLike('title', '%'.$search.'%'))
                ->orderByDesc('id')->paginate(20);

            return response()->json(['items' => $titles->items(), 'page' => $titles->currentPage(), 'last_page' => $titles->lastPage()]);
        }
        $knowledge = KnowledgeBase::query()->select(['id', 'name'])->whereHas('chunks')
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(30);
        $result = ['knowledge_bases' => $knowledge->items(), 'page' => $knowledge->currentPage(), 'last_page' => $knowledge->lastPage()];
        if ($kind === 'all') {
            try {
                $actor = $request->user('admin');
                $result['models'] = $this->models->usableQuery($actor)
                    ->where(fn ($query) => $query->whereNull('model_type')->orWhereIn('model_type', ['', 'chat']))
                    ->get()->map(fn (AiModel $model): array => ['id' => $model->id, 'name' => $model->name])->all();
                $result['default_model_id'] = $this->models->resolveCandidates($actor, 'chat')->first()?->id;
            } catch (AiModelAccessException) {
                return $this->modelError();
            }
            $result['title_libraries'] = TitleLibrary::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
        }

        return response()->json($result);
    }

    public function generate(TestAiPromptRequest $request, int $promptId): StreamedResponse|JsonResponse
    {
        $prompt = $this->prompt($promptId);
        try {
            $prepared = $this->service->start($request->user('admin'), $prompt, $request->validated());
        } catch (AiModelAccessException|AiModelRuntimeEligibilityException) {
            return $this->modelError();
        }

        return response()->stream(function () use ($prepared): iterable {
            $previous = ignore_user_abort(true);
            try {
                yield from $this->service->stream($prepared['run'], $prepared['lock']);
            } finally {
                ignore_user_abort((bool) $previous);
            }
        }, headers: ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    public function show(Request $request, int $promptId, string $runId): JsonResponse
    {
        $this->prompt($promptId);
        try {
            return response()->json($this->runs->present($this->service->find($request->user('admin'), $promptId, $runId)));
        } catch (AiModelAccessException) {
            return $this->modelError();
        }
    }

    public function diagnose(Request $request, int $promptId, string $runId): JsonResponse
    {
        $this->prompt($promptId);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'], 'diagnostic_model_id' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            return response()->json($this->service->diagnose(
                $request->user('admin'), $promptId, $runId, $input['request_id'],
                isset($input['diagnostic_model_id']) ? (int) $input['diagnostic_model_id'] : null,
            ));
        } catch (AiModelAccessException|AiModelRuntimeEligibilityException) {
            return $this->modelError();
        }
    }

    public function cancel(Request $request, int $promptId, string $runId): JsonResponse
    {
        $this->prompt($promptId);
        try {
            $run = $this->service->find($request->user('admin'), $promptId, $runId);
            if (in_array($run['status'], ['retrieving', 'generating', 'article_ready', 'diagnosing'], true)) {
                $this->runs->cancel($run);
            }

            return response()->json($this->runs->present($run));
        } catch (AiModelAccessException) {
            return $this->modelError();
        }
    }

    private function prompt(int $id): Prompt
    {
        return Prompt::query()->where('type', 'content')->findOrFail($id);
    }

    private function modelError(): JsonResponse
    {
        return response()->json(['message' => __('prompt_test.errors.model_unavailable')], 422);
    }
}
