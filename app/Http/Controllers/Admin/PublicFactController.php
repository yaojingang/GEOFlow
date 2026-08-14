<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PublicFact;
use App\Models\PublicationSnapshot;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicFactController extends Controller
{
    public function index(): View
    {
        return view('admin.public-facts.index', [
            'facts' => PublicFact::query()->withCount('pages')->orderBy('fact_code')->get(),
            'pageTitle' => '公开事实与证据',
            'activeMenu' => 'public-facts',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PublicFact::query()->create($this->validated($request));

        return redirect()->route('admin.public-facts.index')->with('message', '事实草稿已建立。达到 E3 且批准公开后才能用于正式页面。');
    }

    public function update(Request $request, int $factId): RedirectResponse
    {
        $fact = PublicFact::query()->with('pages')->findOrFail($factId);
        $fact->update($this->validated($request, $fact));

        foreach ($fact->pages as $page) {
            $page->version = (int) $page->version + 1;
            $page->status = 'draft';
            $page->save();

            PublicationSnapshot::query()
                ->where('public_page_id', $page->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'superseded_at' => now()]);
        }

        return redirect()->route('admin.public-facts.index')->with('message', '事实与证据状态已更新；受影响页面需重新审核发布。');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PublicFact $fact = null): array
    {
        return $request->validate([
            'fact_code' => ['required', 'string', 'max:64', Rule::unique('public_facts', 'fact_code')->ignore($fact?->id)],
            'entity_type' => ['required', 'string', 'max:40'],
            'statement' => ['required', 'string', 'max:5000'],
            'evidence_level' => ['required', 'integer', 'between:0,4'],
            'evidence_label' => ['nullable', 'string', 'max:255'],
            'evidence_url' => ['nullable', 'url:http,https', 'max:2000'],
            'visibility' => ['required', Rule::in(['public', 'internal', 'restricted'])],
            'status' => ['required', Rule::in(['draft', 'approved', 'published', 'withdrawn'])],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'effective_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:effective_at'],
        ]);
    }
}
