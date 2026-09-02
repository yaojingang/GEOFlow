<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\UrlImportAiExecutionGuard;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    public function __construct(
        private readonly UrlImportProcessingService $urlImportProcessingService,
        private readonly UrlImportAiExecutionGuard $urlImportAiExecutionGuard,
    ) {}

    public function index(): View
    {
        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats(),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel($this->currentAdmin()),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array', 'max:3'],
            'outputs.*' => ['string', 'distinct', 'in:knowledge,keywords,titles'],
        ]);
        $safeOldInput = collect($validated)->only([
            'url', 'project_name', 'source_label', 'content_language', 'notes', 'outputs',
        ])->all();

        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $exception) {
            report($exception);

            return back()->withInput($safeOldInput)->withErrors([
                'url' => __('admin.url_import.error.invalid_url'),
            ]);
        }

        try {
            $analysisModel = $this->urlImportProcessingService->assertAnalysisModelReady($this->currentAdmin());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.ai-models.index')
                ->withInput($safeOldInput)
                ->withErrors(['ai_model' => __('admin.url_import.error.ai_model_required')]);
        }

        $job = DB::transaction(function () use ($validated, $normalized, $analysisModel): UrlImportJob {
            $admin = $this->currentAdmin();
            $identity = $this->urlImportAiExecutionGuard->snapshotForCreation($admin, $analysisModel);
            $job = UrlImportJob::query()->create(array_merge([
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
                    'notes' => $validated['notes'] ?? '',
                    'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles'],
                ], JSON_UNESCAPED_UNICODE),
                'result_json' => '',
                'error_message' => '',
                'created_by' => $admin->username,
            ], $identity));

            UrlImportJobLog::query()->create([
                'job_id' => $job->id,
                'step' => 'queued',
                'level' => 'info',
                'message' => __('admin.url_import.section.new_job_desc'),
            ]);

            return $job;
        });

        return redirect()->route('admin.url-import.show', ['jobId' => $job->id]);
    }

    public function run(int $jobId): JsonResponse
    {
        $job = $this->findOwnedExecutionJobOrFail($jobId);

        if ((string) $job->status === 'failed' && ! (bool) $job->retryable_failure) {
            return response()->json($this->statusPayload($job), 422);
        }

        if (in_array($job->status, ['queued', 'failed'], true)) {
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

        $job = $job->refresh();

        return response()->json(
            $this->statusPayload($job),
            (string) $job->status === 'failed' ? 422 : 200,
        );
    }

    public function status(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        return response()->json($this->statusPayload($job));
    }

    public function commit(int $jobId): RedirectResponse
    {
        $job = $this->findOwnedExecutionJobOrFail($jobId);

        try {
            $summary = $this->urlImportProcessingService->commit($job);
        } catch (\Throwable $exception) {
            $this->reportCommitFailure($exception, $jobId);

            return back()->withErrors(__('admin.url_import.error.commit_failed'));
        }

        return redirect()
            ->route('admin.url-import.show', ['jobId' => $jobId])
            ->with('message', __('admin.url_import.commit.success').'：'.__('admin.url_import_history.import.summary', [
                'knowledge_base' => $summary['knowledge_base'],
                'keywords' => $summary['keywords'],
                'titles' => $summary['titles'],
            ]));
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

    private function reportCommitFailure(\Throwable $exception, int $jobId): void
    {
        if ($exception instanceof QueryException) {
            $sqlState = preg_match('/^[A-Z0-9]{5}$/', (string) $exception->getCode()) === 1
                ? (string) $exception->getCode()
                : 'unknown';
            report(new \RuntimeException(
                "URL import database commit failed for job {$jobId} (SQLSTATE {$sqlState})."
            ));

            return;
        }

        report($exception);
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
            'status_label' => __('admin.url_import_history.status.'.$job->status),
            'current_step' => $currentStep,
            'stored_step' => $storedStep,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) $job->error_message,
            'error_code' => (string) ($job->error_code ?? ''),
            'retryable_failure' => (bool) $job->retryable_failure,
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

    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();

        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function findOwnedExecutionJobOrFail(int $jobId): UrlImportJob
    {
        return UrlImportJob::query()
            ->whereKey($jobId)
            ->where('model_access_admin_id', $this->currentAdmin()->getKey())
            ->firstOrFail();
    }
}
