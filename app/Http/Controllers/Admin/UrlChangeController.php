<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeReportStore;
use App\Services\Site\UrlChangeService;
use App\Support\AdminWeb;
use App\Support\Site\ArticlePermalinkCsv;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UrlChangeController extends Controller
{
    public function __construct(private readonly UrlChangeService $changes, private readonly UrlChangeReportStore $reports) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['operation' => ['required', 'in:primary,hosted,category,article_category'], 'target_id' => ['nullable', 'integer', 'min:1'], 'value' => ['required', 'string', 'max:255']]);
        try {
            $change = $this->changes->start($request->user('admin'), $data['operation'], isset($data['target_id']) ? (int) $data['target_id'] : null, $data['value']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['value' => Arr::flatten($exception->errors())]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }

        return redirect()->route('admin.url-changes.show', $change);
    }

    public function show(Request $request, UrlChangeRequest $urlChange): Response
    {
        $this->authorizeReport($request, $urlChange);

        return response()->view('admin.url-changes.show', [
            'pageTitle' => __('url_change.title'), 'activeMenu' => 'site-settings', 'adminSiteName' => AdminWeb::siteName(),
            'change' => $urlChange, 'credential' => $urlChange->status === 'ready' ? $this->changes->credential($urlChange) : '',
            'phrase' => $this->changes->phrase($urlChange),
            'editorUrl' => $this->editorUrl($urlChange),
            'targetDetails' => $this->targetDetails($urlChange),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function status(Request $request, UrlChangeRequest $urlChange): JsonResponse
    {
        $this->authorizeReport($request, $urlChange);

        return response()->json(['status' => $urlChange->status, 'summary' => $urlChange->summary, 'error' => $urlChange->error, 'updated_at' => $urlChange->updated_at?->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, UrlChangeRequest $urlChange): RedirectResponse
    {
        $this->authorizeReport($request, $urlChange);
        $data = $request->validate(['credential' => ['required', 'string', 'max:4096'], 'confirmation' => ['required', 'string', 'max:100']]);
        $change = $this->changes->confirm($request->user('admin'), $urlChange, $data['credential'], $data['confirmation']);
        $request->request->remove('credential');
        $request->request->remove('confirmation');
        $request->attributes->set('admin_activity_action', 'activate');
        $request->attributes->set('admin_activity_details', [
            'url_change_id' => $change->id, 'operation' => $change->operation, 'target_id' => $change->target_id,
            'old_value' => $change->old_value, 'new_value' => $change->new_value,
            'affected_urls' => $change->summary['changed_urls'], 'success' => true,
            'versions' => $change->versions, 'status' => $change->status,
        ]);

        return redirect()->route('admin.url-changes.show', $change)->with('message', __('url_change.applied_notice'));
    }

    public function cancel(Request $request, UrlChangeRequest $urlChange): RedirectResponse
    {
        $this->authorizeReport($request, $urlChange);
        $data = $request->validate(['return_to_editor' => ['sometimes', 'boolean']]);
        try {
            Cache::lock('url-change:'.$urlChange->id, 120)->block(2, function () use ($urlChange): void {
                $urlChange->refresh();
                abort_unless(in_array($urlChange->status, ['checking', 'ready'], true), 409);
                $this->changes->finish($urlChange, 'cancelled');
                abort_unless($urlChange->refresh()->status === 'cancelled', 409);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['confirmation' => __('url_change.ui.cancel_busy')]);
        }

        if ($data['return_to_editor'] ?? false) {
            $field = match ($urlChange->operation) {
                'category' => 'value',
                'article_category' => 'category_id',
                default => 'pattern',
            };

            return redirect()->to($this->editorUrl($urlChange))
                ->withInput([$field => $urlChange->new_value])
                ->with('url_change_draft_restored', true);
        }

        return redirect()->route('admin.url-changes.show', $urlChange);
    }

    private function editorUrl(UrlChangeRequest $change): string
    {
        return match ($change->operation) {
            'category' => route('admin.categories.edit', ['categoryId' => $change->target_id]),
            'article_category' => route('admin.articles.edit', ['articleId' => $change->target_id]),
            'hosted' => route('admin.distribution.hosted-sites.edit', ['hostedSite' => $change->target_id]),
            default => route('admin.site-settings.index').'#site-settings-permalink',
        };
    }

    /** @return array<string, mixed> */
    private function targetDetails(UrlChangeRequest $change): array
    {
        if ($change->operation === 'category') {
            $category = Category::query()->select(['id', 'name'])->find($change->target_id);

            return ['kind' => 'category', 'id' => $change->target_id, 'name' => $category?->name ?? __('url_change.ui.target_unavailable')];
        }
        if ($change->operation === 'article_category') {
            $article = Article::withTrashed()->select(['id', 'title'])->find($change->target_id);
            $categories = Category::query()->whereIn('id', [(int) $change->old_value, (int) $change->new_value])->pluck('name', 'id');

            return [
                'kind' => 'article', 'id' => $change->target_id, 'name' => $article?->title ?? __('url_change.ui.target_unavailable'),
                'old_category_name' => $categories->get((int) $change->old_value, __('url_change.ui.target_unavailable')),
                'new_category_name' => $categories->get((int) $change->new_value, __('url_change.ui.target_unavailable')),
            ];
        }

        return [];
    }

    public function articles(Request $request, UrlChangeRequest $urlChange): JsonResponse
    {
        $this->authorizeReport($request, $urlChange);
        $this->assertReadable($urlChange);
        $data = $request->validate(['segment' => ['nullable', 'integer', 'min:0'], 'offset' => ['nullable', 'integer', 'min:0', 'max:500'], 'site' => ['nullable', 'string', 'max:100']]);
        $start = (int) ($data['segment'] ?? 0);
        $offset = (int) ($data['offset'] ?? 0);
        $end = null;
        if (isset($data['site'])) {
            $bounds = $urlChange->progress['site_segments'][$data['site']] ?? null;
            if ($bounds === null) {
                return response()->json(['rows' => [], 'next' => null])->header('Cache-Control', 'private, no-store');
            }
            if ($start < $bounds['start']) {
                $start = $bounds['start'];
                $offset = 0;
            }
            $end = $bounds['end'];
        }
        $rows = [];
        $position = 0;
        $last = $start;
        foreach ($this->reports->rows($urlChange, $start, $end) as $segment => $row) {
            if ($last !== $segment) {
                $position = 0;
                $last = $segment;
            }
            if ($segment === $start && $position++ < $offset) {
                continue;
            }
            if ($segment !== $start) {
                $position++;
            }
            if (! isset($data['site']) || $row['site'] === $data['site']) {
                $rows[] = $row;
            }
            if (count($rows) === 20) {
                return response()->json(['rows' => $rows, 'next' => ['segment' => $segment, 'offset' => $position]])->header('Cache-Control', 'private, no-store');
            }
        }

        return response()->json(['rows' => $rows, 'next' => null])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, UrlChangeRequest $urlChange): StreamedResponse
    {
        $this->authorizeReport($request, $urlChange);
        $this->assertReadable($urlChange);
        $data = $request->validate(['part' => ['nullable', 'integer', 'min:1']]);
        $part = (int) ($data['part'] ?? 1);
        abort_if($part > max(1, (int) ceil(($urlChange->progress['rows'] ?? 0) / 100000)), 404);

        return response()->streamDownload(function () use ($urlChange, $part): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            try {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['article_id', 'title', 'site', 'old_url', 'new_url', 'currently_public'], ',', '"', '');
                $start = $urlChange->progress['export_index'][$part - 1] ?? ['segment' => 0, 'offset' => 0];
                $index = 0;
                foreach ($this->reports->rows($urlChange, $start['segment']) as $row) {
                    if ($index++ < $start['offset']) {
                        continue;
                    }
                    if ($index > $start['offset'] + 100000) {
                        break;
                    }
                    fputcsv($out, array_map([ArticlePermalinkCsv::class, 'cell'], [$row['id'], $row['title'], $row['label'], $row['old_url'], $row['new_url'], $row['public'] ? 'yes' : 'no']), ',', '"', '');
                }
            } finally {
                fclose($out);
            }
        }, 'url-migration-'.$urlChange->id.'-'.$part.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeReport(Request $request, UrlChangeRequest $change): void
    {
        $this->changes->authorize($request->user('admin'));
        abort_unless($change->admin_id === (int) $request->user('admin')->id, 403);
    }

    private function assertReadable(UrlChangeRequest $change): void
    {
        abort_unless(in_array($change->status, ['ready', 'applied', 'refreshing', 'completed'], true), 409);
        abort_if($change->finished_at?->lt(now()->subDay()) === true, 410);
        if ($change->applied_at === null) {
            abort_unless($change->expires_at?->isFuture() && $this->changes->isCurrent($change), 409, __('url_change.errors.stale'));
        }
    }
}
