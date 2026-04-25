<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Blade 后台会话登录/退出/语言切换（替代 bak/admin/index.php、logout.php）。
 */
class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminLoginLockService $adminLoginLockService
    ) {}

    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login', [
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);
        $username = trim((string) $credentials['username']);
        /** @var Admin|null $targetAdmin */
        $targetAdmin = Admin::query()->where('username', $username)->first();
        if ($targetAdmin instanceof Admin && $this->adminLoginLockService->isLocked($targetAdmin)) {
            return back()->withErrors([
                'username' => __('admin.login.error.account_locked'),
            ])->onlyInput('username');
        }

        $remember = $request->boolean('remember');

        if (! Auth::guard('admin')->attempt(
            ['username' => $username, 'password' => $credentials['password'], 'status' => 'active'],
            $remember
        )) {
            if ($targetAdmin instanceof Admin && $this->adminLoginLockService->recordFailedAttemptAndLock($targetAdmin)) {
                return back()->withErrors([
                    'username' => __('admin.login.error.account_locked'),
                ])->onlyInput('username');
            }

            return back()->withErrors([
                'username' => __('admin.login.error.invalid_credentials'),
            ])->onlyInput('username');
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $request->session()->regenerate();
        $this->adminLoginLockService->clearFailedAttempts((string) $admin->username);

        $admin->forceFill(['last_login' => now()])->save();
        AdminActivityLogger::logFromRequest($request, $admin, 'auth:login', [
            'username' => (string) $admin->username,
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if ($admin instanceof Admin) {
            AdminActivityLogger::logFromRequest($request, $admin, 'auth:logout', [
                'username' => (string) $admin->username,
            ]);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function switchLocale(Request $request, string $locale): RedirectResponse
    {
        if (! AdminWeb::isSupportedLocale($locale)) {
            $locale = 'zh_CN';
        }
        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        return redirect()->back();
    }
}
