<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\CollectionOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    public function __construct(private readonly UrlImportProcessingService $urlImportProcessingService) {}

    public function index(): View
    {
        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats(),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel(),
            'aiModelOptions' => $this->urlImportProcessingService->analysisModelOptions(),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
            'collectionOptions' => CollectionOptions::all(true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', Rule::in(['', 'zh-CN', 'en'])],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'title_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles,entities,cases'],
        ]);

        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['url' => $exception->getMessage()]);
        }

        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.ai-models.index')
                ->withInput()
                ->withErrors(['ai_model' => $exception->getMessage()]);
        }

        $job = UrlImportJob::query()->create([
            'url' => $validated['url'],
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'page_title' => $validated['project_name'] ?? '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => $validated['project_name'] ?? '',
                'source_label' => $validated['source_label'] ?? '',
                'content_language' => $validated['content_language'] ?? '',
                'ai_model_id' => (int) ($validated['ai_model_id'] ?? 0),
                'collection_id' => (int) ($validated['collection_id'] ?? 0) ?: null,
                'title_count' => min(50, max(1, (int) ($validated['title_count'] ?? 30))),
                'notes' => $validated['notes'] ?? '',
                'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles', 'entities', 'cases'],
            ], JSON_UNESCAPED_UNICODE),
            'result_json' => '',
            'error_message' => '',
            'created_by' => Auth::guard('admin')->user()?->username ?? '',
        ]);

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'step' => 'queued',
            'level' => 'info',
            'message' => __('admin.url_import.section.new_job_desc'),
        ]);

        return redirect()->route('admin.url-import.show', ['jobId' => $job->id]);
    }

    public function run(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        if (in_array($job->status, ['queued', 'failed'], true)) {
            try {
                $this->urlImportProcessingService->assertAnalysisModelReady();
            } catch (\Throwable $exception) {
                $job->update([
                    'status' => 'failed',
                    'progress_percent' => max(1, (int) $job->progress_percent),
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);

                UrlImportJobLog::query()->create([
                    'job_id' => $job->id,
                    'step' => $job->current_step ?: 'queued',
                    'level' => 'error',
                    'message' => __('admin.url_import.log.failed', ['message' => $exception->getMessage()]),
                ]);

                return response()->json($this->statusPayload($job->refresh()), 422);
            }

            if (app()->runningUnitTests()) {
                $job = $this->urlImportProcessingService->process($job);
            } else {
                $job->update([
                    'status' => 'running',
                    'current_step' => $job->current_step ?: 'queued',
                    'progress_percent' => max(0, (int) $job->progress_percent),
                    'error_message' => '',
                    'started_at' => $job->started_at ?: now(),
                ]);

                if (! $this->spawnUrlImportWorker((int) $job->id)) {
                    $job = $this->urlImportProcessingService->process($job->refresh());
                }
            }
        }

        return response()->json($this->statusPayload($job->refresh()));
    }

    public function status(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        return response()->json($this->statusPayload($job));
    }

    public function commit(Request $request, int $jobId): RedirectResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        try {
            $summary = $this->urlImportProcessingService->commit($job, $this->selectedImportItems($request));
        } catch (\Throwable $exception) {
            return back()->withErrors(__('admin.url_import.error.commit_failed').': '.$exception->getMessage());
        }

        $message = __('admin.url_import.commit.success').'：'.__('admin.url_import_history.import.summary', [
                'knowledge_base' => $summary['knowledge_base'],
                'keywords' => $summary['keywords'],
                'titles' => $summary['titles'],
            ]);
        if ((int) ($summary['entities'] ?? 0) > 0 || (int) ($summary['cases'] ?? 0) > 0) {
            $message .= '；Entity '.(int) ($summary['entities'] ?? 0).'，Case '.(int) ($summary['cases'] ?? 0);
        }

        return redirect()
            ->route('admin.url-import.show', ['jobId' => $jobId])
            ->with('message', $message);
    }

    public function show(int $jobId): View
    {
        $job = UrlImportJob::query()->findOrFail($jobId);

        $job->load(['logs' => fn ($query) => $query->oldest()->limit(120)]);

        return view('admin.url-import.show', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'job' => $job,
            'result' => $this->decodeJson((string) $job->result_json),
            'logs' => $job->logs,
        ]);
    }

    public function history(): View
    {
        return view('admin.url-import.history', [
            'pageTitle' => __('admin.url_import_history.page_title'),
            'activeMenu' => 'materials',
            'jobs' => UrlImportJob::query()->latest()->paginate(20),
            'stats' => [
                'total' => UrlImportJob::query()->count(),
                'completed' => UrlImportJob::query()->where('status', 'completed')->count(),
                'running' => UrlImportJob::query()->whereIn('status', ['queued', 'running'])->count(),
                'failed' => UrlImportJob::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    private function loadStats(): array
    {
        return [
            'knowledge_bases' => KnowledgeBase::query()->count(),
            'keyword_libraries' => KeywordLibrary::query()->count(),
            'title_libraries' => TitleLibrary::query()->count(),
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function selectedImportItems(Request $request): array
    {
        $selected = $request->input('selected', []);
        if (! is_array($selected)) {
            return [];
        }

        $result = [];
        foreach (['knowledge', 'keywords', 'titles', 'entities', 'cases', 'images'] as $type) {
            $values = $selected[$type] ?? null;
            if (! is_array($values)) {
                continue;
            }
            $result[$type] = collect($values)
                ->map(static fn ($value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value >= 0)
                ->unique()
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function spawnUrlImportWorker(int $jobId): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $phpBinary = PHP_BINARY ?: 'php';
        if (str_contains(basename($phpBinary), 'php-fpm')) {
            $phpBinary = 'php';
        }

        $command = sprintf(
            '%s %s geoflow:process-url-import %d > %s 2>&1 & echo $!',
            escapeshellarg($phpBinary),
            escapeshellarg(base_path('artisan')),
            $jobId,
            escapeshellarg(storage_path('logs/url-import-worker-'.$jobId.'.log'))
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(UrlImportJob $job): array
    {
        $logs = UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->oldest()
            ->limit(120)
            ->get();
        $latestLogStep = (string) ($logs->last()?->step ?: '');
        $storedStep = (string) $job->current_step;
        $currentStep = $latestLogStep !== '' && ! ($latestLogStep === 'queued' && $storedStep !== 'queued')
            ? $latestLogStep
            : $storedStep;

        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'status_label' => __('admin.url_import_history.status.' . $job->status),
            'current_step' => $currentStep,
            'stored_step' => $storedStep,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) $job->error_message,
            'result_ready' => (string) $job->result_json !== '',
            'finished_at' => optional($job->finished_at)->format('Y-m-d H:i:s'),
            'logs' => $logs
                ->map(fn (UrlImportJobLog $log): array => [
                    'step' => (string) ($log->step ?: ''),
                    'level' => (string) $log->level,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ];
    }
}
