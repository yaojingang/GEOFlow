<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IterateSiteThemeReplicationJob;
use App\Jobs\RunSiteThemeReplicationJob;
use App\Models\SiteThemeReplication;
use App\Services\Admin\SiteThemeReplication\ThemePreviewRenderer;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackageService;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPublishService;
use App\Services\Admin\SiteThemeReplicationService;
use App\Support\AdminWeb;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteThemeReplicationController extends Controller
{
    public function create(
        SiteThemeCatalog $themeCatalog,
        SiteThemeReplicationService $replicationService
    ): View {
        return view('admin.site-theme-replications.create', [
            'pageTitle' => __('admin.theme_replication.create_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'availableThemes' => $themeCatalog->all(),
            'activeChatModels' => $replicationService->activeChatModels(),
            'schemaReady' => $replicationService->isSchemaReady(),
        ]);
    }

    public function store(Request $request, SiteThemeReplicationService $replicationService): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'theme_id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]{1,78}[a-zA-Z0-9]$/'],
            'base_theme_id' => ['nullable', 'string', 'max:80'],
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(static function ($query): void {
                    $query
                        ->where('status', 'active')
                        ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'");
                }),
            ],
            'home_url' => ['required', 'url', 'max:500'],
            'category_url' => ['required', 'url', 'max:500'],
            'article_url' => ['required', 'url', 'max:500'],
            'style_preference' => ['required', Rule::in(['content_site', 'brand_site', 'news_site'])],
            'compliance_ack' => ['accepted'],
        ], [
            'theme_id.regex' => __('admin.theme_replication.validation.theme_id_invalid'),
            'ai_model_id.exists' => __('admin.theme_replication.validation.ai_model_required'),
            'compliance_ack.accepted' => __('admin.theme_replication.validation.compliance_ack'),
        ]);

        if (! $replicationService->isSchemaReady()) {
            return back()
                ->withErrors(['theme_replication' => __('admin.theme_replication.message.migration_required')])
                ->withInput();
        }

        try {
            $themeId = $replicationService->normalizeThemeId((string) $payload['theme_id']);
            if ($replicationService->themeIdExists($themeId)) {
                return back()
                    ->withErrors(['theme_id' => __('admin.theme_replication.validation.theme_id_exists')])
                    ->withInput();
            }

            $baseThemeId = trim((string) ($payload['base_theme_id'] ?? ''));
            if ($baseThemeId !== '' && ! $replicationService->isCatalogThemeId($baseThemeId)) {
                return back()
                    ->withErrors(['base_theme_id' => __('admin.theme_replication.validation.base_theme_invalid')])
                    ->withInput();
            }

            foreach (['home_url', 'category_url', 'article_url'] as $urlField) {
                try {
                    $replicationService->normalizeReferenceUrls([$urlField => (string) $payload[$urlField]]);
                } catch (InvalidArgumentException $e) {
                    return back()
                        ->withErrors([$urlField => $e->getMessage()])
                        ->withInput();
                }
            }

            $replication = $replicationService->create([
                'name' => (string) $payload['name'],
                'theme_id' => $themeId,
                'base_theme_id' => $baseThemeId !== '' ? $baseThemeId : null,
                'ai_model_id' => (int) $payload['ai_model_id'],
                'home_url' => (string) $payload['home_url'],
                'category_url' => (string) $payload['category_url'],
                'article_url' => (string) $payload['article_url'],
                'style_preference' => (string) $payload['style_preference'],
                'created_by_admin_id' => auth('admin')->id(),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()
                ->withErrors(['theme_id' => $e->getMessage()])
                ->withInput();
        } catch (RuntimeException $e) {
            return back()
                ->withErrors(['theme_replication' => $e->getMessage()])
                ->withInput();
        }

        RunSiteThemeReplicationJob::dispatch((int) $replication->id)->onQueue('theme-replication');

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id])
            ->with('message', __('admin.theme_replication.message.created'));
    }

    public function show(
        int $replicationId,
        SiteThemeReplicationService $replicationService
    ): View {
        $replication = SiteThemeReplication::query()
            ->with(['aiModel', 'creator', 'versions' => fn ($query) => $query->latest('version')])
            ->findOrFail($replicationId);

        $hasCurrentDraft = ! empty($replication->generated_files_json) && ! empty($replication->preview_snapshot_json);
        $emptyFileDiff = [
            'base' => null,
            'target' => null,
            'rows' => [],
            'counts' => ['added' => 0, 'modified' => 0, 'removed' => 0, 'unchanged' => 0],
        ];
        $timelineLogs = $replication->logs()->oldest('id')->limit(100)->get();

        return view('admin.site-theme-replications.show', [
            'pageTitle' => __('admin.theme_replication.detail_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'replication' => $replication,
            'latestVersion' => $hasCurrentDraft ? $replication->versions()->latest('version')->first() : null,
            'logs' => $replication->logs()->latest('id')->limit(30)->get(),
            'progress' => $replicationService->progressSnapshot($replication, $timelineLogs),
            'fileDiff' => $hasCurrentDraft ? $replicationService->versionDiff($replication) : $emptyFileDiff,
            'failureAdvice' => $replicationService->failureAdvice($replication),
        ]);
    }

    public function status(
        int $replicationId,
        SiteThemeReplicationService $replicationService
    ): JsonResponse {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);
        $logs = $replication->logs()->oldest('id')->limit(100)->get();

        return response()->json($replicationService->progressSnapshot($replication, $logs));
    }

    public function retry(
        int $replicationId,
        SiteThemeReplicationService $replicationService
    ): RedirectResponse {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);

        if ((string) $replication->status !== SiteThemeReplication::STATUS_FAILED) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors(__('admin.theme_replication.message.retry_unavailable'));
        }

        $replicationService->retry($replication);
        RunSiteThemeReplicationJob::dispatch($replicationId)->onQueue('theme-replication');

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
            ->with('message', __('admin.theme_replication.message.retried'));
    }

    public function preview(int $replicationId, string $page, ThemePreviewRenderer $renderer): Response
    {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);
        if (! $replication->isPreviewReady()) {
            abort(404, __('admin.theme_replication.error.preview_unavailable'));
        }

        return $renderer->render($page);
    }

    public function iterate(int $replicationId, Request $request, SiteThemeReplicationService $replicationService): RedirectResponse
    {
        $payload = $request->validate([
            'feedback' => ['required', 'string', 'max:2000'],
        ]);

        $replication = SiteThemeReplication::query()->findOrFail($replicationId);
        if ((string) $replication->status !== SiteThemeReplication::STATUS_READY) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors(__('admin.theme_replication.message.iteration_unavailable'));
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_ITERATING,
            'error_message' => null,
        ])->save();

        $replicationService->log($replication, 'info', 'iteration_queued', __('admin.theme_replication.log.iteration_queued'), [
            'feedback' => mb_substr((string) $payload['feedback'], 0, 500),
        ]);

        IterateSiteThemeReplicationJob::dispatch($replicationId, (string) $payload['feedback'])->onQueue('theme-replication');

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
            ->with('message', __('admin.theme_replication.message.iteration_queued'));
    }

    public function publish(int $replicationId, ThemeReplicationPublishService $publishService): RedirectResponse
    {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);

        try {
            $result = $publishService->publish($replication);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
            ->with('message', (string) $result['message']);
    }

    public function copy(int $replicationId, Request $request, SiteThemeReplicationService $replicationService): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'theme_id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]{1,78}[a-zA-Z0-9]$/'],
        ], [
            'theme_id.regex' => __('admin.theme_replication.validation.theme_id_invalid'),
        ]);

        $replication = SiteThemeReplication::query()->findOrFail($replicationId);

        try {
            $copy = $replicationService->duplicateAsNewTheme($replication, [
                'name' => (string) $payload['name'],
                'theme_id' => (string) $payload['theme_id'],
                'created_by_admin_id' => auth('admin')->id(),
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $copy->id])
            ->with('message', __('admin.theme_replication.message.copy_created'));
    }

    public function archive(int $replicationId, SiteThemeReplicationService $replicationService): RedirectResponse
    {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);

        try {
            $replicationService->archive($replication);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
            ->with('message', __('admin.theme_replication.message.archived'));
    }

    public function deleteDrafts(int $replicationId, SiteThemeReplicationService $replicationService): RedirectResponse
    {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);

        try {
            $replicationService->deleteDrafts($replication);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
            ->with('message', __('admin.theme_replication.message.drafts_deleted'));
    }

    public function downloadPackage(
        int $replicationId,
        ThemeReplicationPackageService $packageService
    ): BinaryFileResponse|RedirectResponse {
        $replication = SiteThemeReplication::query()->findOrFail($replicationId);
        if (! $replication->canPackage()) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors(__('admin.theme_replication.message.publish_unavailable'));
        }

        try {
            $package = $packageService->createPackage($replication);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.site-settings.theme-replications.show', ['replicationId' => $replicationId])
                ->withErrors($exception->getMessage());
        }

        return response()->download((string) $package['absolute_path'], (string) $package['name']);
    }
}
