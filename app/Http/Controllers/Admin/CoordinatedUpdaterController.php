<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SystemUpdater\UpdaterPlanRequest;
use App\Http\Requests\SystemUpdater\UpdaterSubmissionRequest;
use App\Services\Api\ManagementInstance;
use App\Services\SystemUpdater\RemoteUpdaterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CoordinatedUpdaterController extends Controller
{
    public function index(Request $request, RemoteUpdaterService $updater, ManagementInstance $instance): View
    {
        abort_unless(config('geoflow.update_center_enabled', true), 404);
        $input = $request->validate(['request' => ['sometimes', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/']]);
        $capabilities = null;
        $receipt = null;
        $error = null;
        $points = [];
        try {
            if (isset($input['request'])) {
                $receipt = $updater->lookup($request, $input['request'], true);
            } else {
                $capabilities = $updater->status($request)['capabilities'];
                $points = $updater->recoveryPoints($request)['recovery_points'];
            }
        } catch (ApiException $exception) {
            $error = $exception->getMessage();
        }

        return view('admin.system-updates.operations', [
            'pageTitle' => __('updater.title'), 'activeMenu' => 'dashboard',
            'capabilities' => $capabilities, 'receipt' => $receipt, 'error' => $error,
            'points' => $points, 'instanceId' => $instance->id(), 'requestId' => $input['request'] ?? null,
            'plan' => $request->session()->get('coordinated_updater_plan'),
        ]);
    }

    public function plan(UpdaterPlanRequest $request, RemoteUpdaterService $updater): RedirectResponse
    {
        abort_unless(config('geoflow.update_center_enabled', true), 404);
        $request->session()->forget('coordinated_updater_plan');
        try {
            $plan = $updater->plan($request, $request->validated());
            $request->session()->put('coordinated_updater_plan', $plan + [
                'client_request_id' => (string) Str::uuid(), 'management_instance_id' => $request->validated('instance_id'),
            ]);
        } catch (ApiException $exception) {
            return to_route('admin.system-updates.updater.console')->withErrors(['updater' => $exception->getMessage()]);
        }

        return to_route('admin.system-updates.updater.console');
    }

    public function submit(UpdaterSubmissionRequest $request, RemoteUpdaterService $updater): RedirectResponse
    {
        abort_unless(config('geoflow.update_center_enabled', true), 404);
        $redirect = to_route('admin.system-updates.updater.console', ['request' => $request->validated('client_request_id')]);
        $request->session()->forget('coordinated_updater_plan');
        try {
            $updater->submit($request, $request->businessInput());
        } catch (ApiException $exception) {
            return $redirect->withErrors(['updater' => $exception->getMessage()]);
        }

        return $redirect;
    }

    public function retired(Request $request): RedirectResponse
    {
        abort_unless(config('geoflow.update_center_enabled', true), 404);

        $request->session()->forget(['system_updater_plan', 'coordinated_updater_plan']);

        return to_route('admin.system-updates.updater.console')->withErrors(['updater' => __('updater.plan_required')]);
    }
}
