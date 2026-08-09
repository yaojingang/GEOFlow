<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualPublicationRequest;
use App\Http\Requests\Admin\TransitionManualPublicationRequest;
use App\Http\Requests\Admin\UpdateManualPublicationRequest;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Services\GeoFlow\ManualPublicationService;
use App\Support\AdminWeb;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ManualPublicationController extends Controller
{
    public function __construct(private readonly ManualPublicationService $service) {}

    public function index(Request $request): View
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('viewAny', ManualPublication::class);
        $query = $this->filteredQuery($request, $admin);
        $publications = (clone $query)
            ->with($this->relations())
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $statsQuery = ManualPublication::query()->visibleTo($admin);

        return view('admin.manual-publications.index', [
            'pageTitle' => __('admin.manual_publications.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'publications' => $publications,
            'filters' => $request->only(['status', 'type', 'platform', 'assigned_admin_id', 'article_id', 'scheduled_from', 'scheduled_to', 'search']),
            'stats' => [
                'total' => (clone $statsQuery)->count(),
                'ready' => (clone $statsQuery)->where('status', ManualPublication::STATUS_READY)->count(),
                'in_progress' => (clone $statsQuery)->where('status', ManualPublication::STATUS_IN_PROGRESS)->count(),
                'completed' => (clone $statsQuery)->where('status', ManualPublication::STATUS_COMPLETED)->count(),
            ],
            'admins' => $admin->isSuperAdmin() ? $this->activeAdmins() : collect([$admin]),
            'canCreate' => Gate::forUser($admin)->allows('create', ManualPublication::class),
            'platforms' => ManualPublicationAccount::PLATFORMS,
        ]);
    }

    public function create(Request $request): View
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('create', ManualPublication::class);
        $article = Article::query()
            ->whereKey((int) $request->query('article_id'))
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->first(['id', 'title', 'content', 'review_status']);

        return view('admin.manual-publications.form', $this->formViewData($request, null, $article));
    }

    public function store(StoreManualPublicationRequest $request): RedirectResponse
    {
        try {
            $publication = $this->service->create($request->validated(), $this->admin($request));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.created'));
    }

    public function show(Request $request, int $manualPublicationId): View
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('view', $publication);
        $publication->load([
            ...$this->relations(),
            'transitions.actor:id,display_name,username',
        ]);

        return view('admin.manual-publications.show', [
            'pageTitle' => __('admin.manual_publications.detail_title', ['id' => $publication->getKey()]),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'publication' => $publication,
            'duplicates' => $this->service->duplicatesFor($publication)
                ->filter(fn (ManualPublication $duplicate): bool => Gate::forUser($admin)->allows('view', $duplicate))
                ->values(),
            'canEdit' => Gate::forUser($admin)->allows('update', $publication),
            'canTransition' => Gate::forUser($admin)->allows('transition', $publication),
            'canReopen' => Gate::forUser($admin)->allows('reopen', $publication),
        ]);
    }

    public function edit(Request $request, int $manualPublicationId): View
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('update', $publication);

        return view('admin.manual-publications.form', $this->formViewData($request, $publication));
    }

    public function update(UpdateManualPublicationRequest $request, int $manualPublicationId): RedirectResponse
    {
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();

        try {
            $publication = $this->service->update(
                $publication,
                $request->safe()->except('revision'),
                (int) $request->validated('revision'),
            );
        } catch (DomainException|ManualPublicationConflictException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.updated'));
    }

    public function transition(TransitionManualPublicationRequest $request, int $manualPublicationId): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        $targetStatus = (string) $request->validated('target_status');
        $ability = $publication->isReopenTransition($targetStatus) ? 'reopen' : 'transition';
        Gate::forUser($admin)->authorize($ability, $publication);

        try {
            $this->service->transition(
                $publication,
                $targetStatus,
                (int) $request->validated('revision'),
                $admin,
                $request->validated('completion_url'),
                $request->validated('result_note'),
            );
        } catch (DomainException|ManualPublicationConflictException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.transitioned'));
    }

    public function export(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('exportAny', ManualPublication::class);
        $query = $this->filteredQuery($request, $admin);
        $filename = 'geoflow-manual-publications-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map(fn (string $key): string => (string) __('admin.manual_publications.export.'.$key), [
                'id', 'type', 'platform', 'article', 'persona', 'account', 'assignee', 'status', 'scheduled_at',
                'target_url', 'content', 'risk_status', 'duplicates', 'completion_url', 'result_note', 'created_at',
            ]));

            $query->chunkById(200, function ($rows) use ($handle): void {
                $rows->load($this->relations());

                foreach ($rows as $row) {
                    if (! $row instanceof ManualPublication) {
                        continue;
                    }
                    fputcsv($handle, array_map(fn (mixed $value): string => $this->csvCell($value), [
                        $row->getKey(),
                        __('admin.manual_publications.type.'.$row->type),
                        $row->platformDisplayName(),
                        $row->article?->title ?? '',
                        $row->personaDisplayName() ?? '',
                        $row->accountDisplayName() ?? '',
                        $row->assignee?->name ?? '',
                        __('admin.manual_publications.status.'.$row->status),
                        $row->scheduled_at?->format('Y-m-d H:i:s') ?? '',
                        $row->target_url ?? '',
                        $row->content,
                        __('admin.manual_publications.risk.'.$row->risk_status),
                        $row->duplicate_warning_count,
                        $row->completion_url ?? '',
                        $row->result_note ?? '',
                        $row->created_at?->format('Y-m-d H:i:s') ?? '',
                    ]));
                }
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function formViewData(Request $request, ?ManualPublication $publication, ?Article $selectedArticle = null): array
    {
        if ($publication instanceof ManualPublication) {
            $publication->load($this->relations());
        }

        $selectedArticle ??= $publication?->article;
        $articleSearch = trim((string) $request->query('article_search'));
        $articles = Article::query()
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->when($articleSearch !== '', function (Builder $query) use ($articleSearch): void {
                $query->where('title', 'like', '%'.$articleSearch.'%');
            })
            ->latest('id')
            ->paginate(50, ['id', 'title', 'review_status'], 'article_page')
            ->withQueryString();

        if ($selectedArticle instanceof Article
            && ! $articles->getCollection()->contains(fn (Article $article): bool => $article->is($selectedArticle))) {
            $articles->setCollection(
                $articles->getCollection()->prepend($selectedArticle)->unique('id')->values(),
            );
        }

        return [
            'pageTitle' => $publication === null
                ? __('admin.manual_publications.create_title')
                : __('admin.manual_publications.edit_title', ['id' => $publication->getKey()]),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'publication' => $publication,
            'selectedArticle' => $selectedArticle,
            'personas' => ManualPublicationPersona::query()->where('is_active', true)->orderBy('name')->get(),
            'accounts' => ManualPublicationAccount::query()->where('is_active', true)->with('persona:id,name')->orderBy('account_name')->get(),
            'admins' => $this->activeAdmins(),
            'articles' => $articles,
            'articleSearch' => $articleSearch,
            'platforms' => ManualPublicationAccount::PLATFORMS,
            'prefilledContent' => $selectedArticle instanceof Article
                ? Str::limit((string) $selectedArticle->content, ManualPublication::MAX_CONTENT_CHARACTERS, '')
                : '',
        ];
    }

    /** @return Builder<ManualPublication> */
    private function filteredQuery(Request $request, Admin $admin): Builder
    {
        $query = ManualPublication::query()->visibleTo($admin);
        $status = trim((string) $request->query('status'));
        $type = trim((string) $request->query('type'));
        $platform = trim((string) $request->query('platform'));

        if (in_array($status, ManualPublication::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($type, ManualPublication::TYPES, true)) {
            $query->where('type', $type);
        }
        if (in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            $query->where('platform', $platform);
        }

        $assigneeId = (int) $request->query('assigned_admin_id');
        if ($admin->isSuperAdmin() && $assigneeId > 0) {
            $query->where('assigned_admin_id', $assigneeId);
        }

        $articleId = (int) $request->query('article_id');
        if ($articleId > 0) {
            $query->where('article_id', $articleId);
        }

        foreach (['scheduled_from' => '>=', 'scheduled_to' => '<='] as $field => $operator) {
            $date = $this->dateFilter($request->query($field));
            if ($date !== null) {
                $query->whereDate('scheduled_at', $operator, $date);
            }
        }

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('content', 'like', '%'.$search.'%')
                    ->orWhere('target_url', 'like', '%'.$search.'%')
                    ->orWhereHas('article', fn (Builder $articleQuery) => $articleQuery->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('account', fn (Builder $accountQuery) => $accountQuery->where('account_name', 'like', '%'.$search.'%'));
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function relations(): array
    {
        return [
            'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'review_status', 'deleted_at']),
            'persona:id,name,disclosure_text,is_active',
            'account:id,persona_id,platform,custom_platform,account_name,profile_url,is_active',
            'assignee:id,username,display_name',
            'creator:id,username,display_name',
        ];
    }

    private function activeAdmins()
    {
        return Admin::query()->where('status', 'active')->orderBy('display_name')->orderBy('username')->get(['id', 'username', 'display_name']);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function dateFilter(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function csvCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : (string) $value;

        return $cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }
}
