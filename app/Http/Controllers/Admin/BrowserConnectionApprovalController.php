<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\BrowserOperations\DeviceAuthorizationService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BrowserConnectionApprovalController extends Controller
{
    public function show(Request $request, DeviceAuthorizationService $authorizations): View
    {
        $userCode = trim((string) $request->query('user_code'));

        return view('admin.manual-publications.browser-connect', [
            'pageTitle' => __('admin.browser_operations.connect.title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'userCode' => $userCode,
            'authorization' => $userCode !== '' ? $authorizations->findByUserCode($userCode) : null,
        ]);
    }

    public function decision(Request $request, DeviceAuthorizationService $authorizations): RedirectResponse
    {
        $payload = $request->validate([
            'user_code' => ['required', 'string', 'max:16'],
            'decision' => ['required', 'in:approve,deny'],
        ]);
        /** @var Admin $admin */
        $admin = $request->user('admin');

        try {
            $authorizations->decide((string) $payload['user_code'], $admin, $payload['decision'] === 'approve');
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.manual-publications.browser-connect.show', ['user_code' => $payload['user_code']])
            ->with('message', __('admin.browser_operations.connect.decision_saved'));
    }
}
