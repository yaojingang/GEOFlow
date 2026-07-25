<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Title;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Services\GeoFlow\ArticleContentPromptRenderer;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Ai\Responses\StreamedAgentResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ArticleEditorAssistantController extends Controller
{
    public function __construct(
        private readonly ArticleContentPromptRenderer $promptRenderer,
        private readonly ArticleContentGenerationService $generationService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
    ) {}

    public function titles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'library_id' => ['nullable', 'integer', 'min:1', 'exists:title_libraries,id'],
            'search' => ['nullable', 'string', 'max:200'],
            'usage' => ['nullable', Rule::in(['unused', 'all', 'used'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $usage = (string) ($validated['usage'] ?? 'unused');

        $titles = Title::query()
            ->select(['id', 'library_id', 'title', 'keyword', 'is_ai_generated', 'used_count', 'usage_count'])
            ->with('library:id,name')
            ->when(isset($validated['library_id']), fn (Builder $query): Builder => $query->where('library_id', (int) $validated['library_id']))
            ->when($usage === 'unused', fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                $query->whereNull('used_count')->orWhere('used_count', '<=', 0);
            }))
            ->when($usage === 'used', fn (Builder $query): Builder => $query->where('used_count', '>', 0))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('title', '%'.$search.'%')
                    ->orWhereLike('keyword', '%'.$search.'%')
                    ->orWhereHas('library', fn (Builder $libraryQuery): Builder => $libraryQuery->whereLike('name', '%'.$search.'%'));
            }))
            ->orderByRaw('COALESCE(used_count, 0) ASC')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'items' => collect($titles->items())->map(static fn (Title $title): array => [
                'id' => (int) $title->id,
                'title' => (string) $title->title,
                'keyword' => (string) ($title->keyword ?? ''),
                'library_id' => (int) $title->library_id,
                'library_name' => (string) ($title->library?->name ?? ''),
                'is_ai_generated' => (bool) $title->is_ai_generated,
                'used_count' => (int) ($title->used_count ?? 0),
            ])->values(),
            'pagination' => [
                'page' => $titles->currentPage(),
                'last_page' => $titles->lastPage(),
                'total' => $titles->total(),
            ],
        ]);
    }

    public function generate(Request $request): Response
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'knowledge_base_id' => ['required', 'integer', 'min:1', Rule::exists('knowledge_bases', 'id')],
            'prompt_id' => [
                'required',
                'integer',
                Rule::exists('prompts', 'id')->where(fn ($query) => $query->where('type', 'content')),
            ],
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($modelQuery) => $modelQuery
                        ->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat'))),
            ],
        ]);

        $knowledgeBase = KnowledgeBase::query()
            ->whereKey((int) $validated['knowledge_base_id'])
            ->firstOrFail(['id']);
        $prompt = Prompt::query()->whereKey((int) $validated['prompt_id'])->where('type', 'content')->firstOrFail();
        $aiModel = AiModel::query()
            ->whereKey((int) $validated['ai_model_id'])
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->firstOrFail();

        $knowledgeContext = $this->knowledgeRetrievalService->retrieveContext(
            (int) $knowledgeBase->id,
            implode("\n", array_filter([
                trim((string) $validated['title']),
                trim((string) ($validated['keyword'] ?? '')),
            ])),
            5,
            3200,
        );
        if ($knowledgeContext === '') {
            return response()->json([
                'message' => __('admin.article_assistant.generate.knowledge_unavailable'),
            ], 422);
        }

        $contentPrompt = $this->promptRenderer->renderForEditor(
            trim((string) $validated['title']),
            trim((string) ($validated['keyword'] ?? '')),
            (string) $prompt->content,
            $knowledgeContext,
        );

        try {
            $stream = $this->generationService->stream($aiModel, $contentPrompt);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('admin.article_assistant.generate.failed')], 500);
        }

        $stream->then(function (StreamedAgentResponse $response) use ($aiModel, $knowledgeBase): void {
            if (trim((string) ($response->text ?? '')) === '') {
                return;
            }

            try {
                KnowledgeBase::query()->whereKey((int) $knowledgeBase->id)->update([
                    'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Article assistant knowledge usage statistics update failed.', [
                    'knowledge_base_id' => (int) $knowledgeBase->id,
                    'ai_model_id' => (int) $aiModel->id,
                ]);
            }
        });

        $response = $stream->toResponse($request);
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
