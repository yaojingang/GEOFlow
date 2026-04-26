<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    public function index(): View
    {
        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles,images'],
        ]);

        $parts = parse_url($validated['url']);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        $job = UrlImportJob::query()->create([
            'url' => $validated['url'],
            'normalized_url' => $validated['url'],
            'source_domain' => $host,
            'page_title' => $validated['project_name'] ?? '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => $validated['project_name'] ?? '',
                'source_label' => $validated['source_label'] ?? '',
                'content_language' => $validated['content_language'] ?? '',
                'notes' => $validated['notes'] ?? '',
                'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles', 'images'],
            ], JSON_UNESCAPED_UNICODE),
            'result_json' => '',
            'error_message' => '',
            'created_by' => Auth::guard('admin')->user()?->username ?? '',
        ]);

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'level' => 'info',
            'message' => __('admin.url_import.section.new_job_desc'),
        ]);

        return redirect()->route('admin.url-import.show', ['jobId' => $job->id]);
    }

    public function show(int $jobId): View
    {
        $job = UrlImportJob::query()
            ->with(['logs' => fn ($query) => $query->latest()->limit(20)])
            ->findOrFail($jobId);

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
            'image_libraries' => ImageLibrary::query()->count(),
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
}
