<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\SystemUpdaterBridgeService;
use App\Services\Admin\SystemUpdateStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    public function index(
        Request $request,
        SystemUpdateStateService $stateService,
        SystemUpdaterBridgeService $updaterBridgeService,
    ): View {
        $this->ensureUpdateCenterEnabled();
        $historyScope = $request->query('history') === 'archived' ? 'archived' : 'recent';

        return view('admin.system-updates.index', [
            'pageTitle' => __('admin.system_updates.page_title'),
            'activeMenu' => 'dashboard',
            'summary' => array_merge($stateService->summary($historyScope), [
                'updater_bridge' => $updaterBridgeService->summary(),
            ]),
        ]);
    }

    public function check(AdminUpdateMetadataService $metadataService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();

        $metadataService->forgetCachedMetadata();
        $metadataService->fetchState();

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.checked'));
    }

    private function ensureUpdateCenterEnabled(): void
    {
        abort_unless((bool) config('geoflow.update_center_enabled', true), 404);
    }
}
