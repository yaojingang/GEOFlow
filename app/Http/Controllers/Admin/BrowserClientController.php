<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BrowserClientController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function index(Request $request): View
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return view('admin.account.browser-clients', [
            'pageTitle' => __('admin.browser_operations.clients.title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'tokens' => $this->tokens->listBrowserTokens($admin),
            'admin' => $admin,
        ]);
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        try {
            $this->tokens->revokeBrowserToken($tokenId, $admin);
        } catch (ApiException $exception) {
            if ($exception->getHttpStatus() === 404) {
                throw new NotFoundHttpException($exception->getMessage(), $exception);
            }

            return back()->withErrors($exception->getMessage());
        }

        AdminActivityLogger::logFromRequest($request, $admin, 'browser_client.revoked', [
            'token_id' => $tokenId,
        ]);

        return redirect()
            ->route('admin.account.browser-clients.index')
            ->with('message', __('admin.browser_operations.clients.revoked'));
    }
}
