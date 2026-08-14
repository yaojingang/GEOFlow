<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ContentApproval;
use App\Models\PublicFact;
use App\Models\PublicPage;
use App\Models\PublicationSnapshot;
use App\Services\ZeroPoint\PublicContentWorkflow;
use App\Support\AdminWeb;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicPageController extends Controller
{
    public function __construct(private readonly PublicContentWorkflow $workflow) {}

    public function index(): View
    {
        $pages = PublicPage::query()
            ->with(['activeSnapshot', 'approvals' => fn ($query) => $query->latest('decided_at')])
            ->orderBy('sort_order')
            ->get();

        return view('admin.public-pages.index', [
            'pages' => $pages,
            'pageTitle' => '正负零公开页面',
            'activeMenu' => 'public-pages',
            'adminSiteName' => AdminWeb::siteName(),
            'gates' => ContentApproval::GATES,
        ]);
    }

    public function edit(int $pageId): View
    {
        $page = PublicPage::query()
            ->with(['facts', 'approvals' => fn ($query) => $query->latest('decided_at'), 'snapshots' => fn ($query) => $query->latest('published_at')])
            ->findOrFail($pageId);

        return view('admin.public-pages.edit', [
            'page' => $page,
            'facts' => PublicFact::query()->orderBy('fact_code')->get(),
            'pageTitle' => '编辑公开页面',
            'activeMenu' => 'public-pages',
            'adminSiteName' => AdminWeb::siteName(),
            'gates' => ContentApproval::GATES,
        ]);
    }

    public function update(Request $request, int $pageId): RedirectResponse
    {
        $page = PublicPage::query()->findOrFail($pageId);
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'eyebrow' => ['nullable', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:1200'],
            'body' => ['required', 'string', 'max:100000'],
            'area' => ['required', Rule::in(PublicPage::AREAS)],
            'page_type' => ['required', 'string', 'max:40'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'string', 'max:500'],
            'fact_ids' => ['nullable', 'array'],
            'fact_ids.*' => ['integer', 'exists:public_facts,id'],
        ]);

        if ($payload['area'] === 'health') {
            $payload['cta_label'] = '';
            $payload['cta_url'] = '';
        }

        $contentKeys = ['title', 'eyebrow', 'summary', 'body', 'area', 'page_type', 'seo_title', 'meta_description', 'cta_label', 'cta_url'];
        $hasContentChange = collect($contentKeys)->contains(
            fn (string $key): bool => (string) ($page->{$key} ?? '') !== (string) ($payload[$key] ?? '')
        );

        $oldHash = (string) $page->content_hash;
        $page->fill($payload);
        if ($hasContentChange) {
            $page->version = (int) $page->version + 1;
            $page->is_placeholder = false;
            $page->status = 'draft';
        }
        $page->save();
        $page->facts()->sync($payload['fact_ids'] ?? []);
        $page->save();
        if (! $hasContentChange && $oldHash !== (string) $page->content_hash) {
            $page->version = (int) $page->version + 1;
            $page->status = 'draft';
            $page->save();
        }

        return redirect()->route('admin.public-pages.edit', $page->id)->with('message', '页面草稿已保存；内容变更后需重新完成四门审核。');
    }

    public function approve(Request $request, int $pageId): RedirectResponse
    {
        $payload = $request->validate([
            'gate' => ['required', Rule::in(ContentApproval::GATES)],
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $page = PublicPage::query()->findOrFail($pageId);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $this->workflow->approve($page, $payload['gate'], $admin, $payload['decision'], (string) ($payload['note'] ?? ''));

        return redirect()->route('admin.public-pages.edit', $page->id)->with('message', '审核结论已绑定当前内容版本。');
    }

    public function publish(Request $request, int $pageId): RedirectResponse
    {
        $page = PublicPage::query()->findOrFail($pageId);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $this->workflow->publish($page, $admin);
        } catch (DomainException $exception) {
            return redirect()->route('admin.public-pages.edit', $page->id)->withErrors($exception->getMessage());
        }

        return redirect()->route('admin.public-pages.edit', $page->id)->with('message', '当前审核版本已发布并成为活动快照。');
    }

    public function rollback(Request $request, int $pageId, int $snapshotId): RedirectResponse
    {
        $page = PublicPage::query()->findOrFail($pageId);
        $snapshot = PublicationSnapshot::query()->findOrFail($snapshotId);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $this->workflow->rollback($page, $snapshot, $admin);
        } catch (DomainException $exception) {
            return redirect()->route('admin.public-pages.edit', $page->id)->withErrors($exception->getMessage());
        }

        return redirect()->route('admin.public-pages.edit', $page->id)->with('message', '已从历史快照创建新的活动发布版本。');
    }
}
