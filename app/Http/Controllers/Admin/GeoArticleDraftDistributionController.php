<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GeoArticleDraft;
use App\Models\Organization;
use App\Services\Geo\GeoYixiaoerDistributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class GeoArticleDraftDistributionController extends Controller
{
    public function submitToYixiaoer(int $draftId, Request $request, GeoYixiaoerDistributionService $distributionService): RedirectResponse
    {
        $payload = $request->validate([
            'platform_codes' => ['required', 'array', 'min:1'],
            'platform_codes.*' => ['required', Rule::in(['weixingongzhonghao'])],
        ], [
            'platform_codes.required' => '请至少选择一个蚁小二目标平台',
            'platform_codes.*.in' => '当前文章只允许提交微信公众号草稿，请先选择微信公众号',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $record = $distributionService->submitOfficialAccountArticleDraft($draft, (array) $payload['platform_codes']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '已提交微信公众号草稿：'.$record->target_url);
    }

    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function resolveOrganization(Admin $admin): Organization
    {
        $fallbackName = trim((string) ($admin->display_name ?: $admin->username)) ?: '默认企业';

        return Organization::query()->firstOrCreate(
            ['owner_admin_id' => $admin->id],
            [
                'name' => $fallbackName,
                'plan_code' => 'trial',
                'points' => 100,
                'balance' => 0,
                'status' => 'active',
            ]
        );
    }

    private function loadStandaloneDraft(Organization $organization, int $draftId): GeoArticleDraft
    {
        return GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->whereKey($draftId)
            ->whereHas('writingTask', function ($query): void {
                $query->whereNull('geo_report_id');
            })
            ->with([
                'article',
                'writingTask',
                'audits' => fn ($query) => $query->latest(),
                'publishRetests' => fn ($query) => $query->latest(),
                'publishRecords' => fn ($query) => $query->with('publishTarget')->latest(),
            ])
            ->firstOrFail();
    }
}
