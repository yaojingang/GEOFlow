<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

/**
 * Blade 后台会话登录/退出/语言切换（替代 bak/admin/index.php、logout.php）。
 */
class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminLoginLockService $adminLoginLockService,
        private readonly AnonymousUsageTelemetry $anonymousUsageTelemetry,
    ) {}

    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login', [
            'adminSiteName' => AdminWeb::siteName(),
            'initialAdminHint' => $this->initialAdminHint(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);
        $username = trim((string) $credentials['username']);
        $ipAddress = (string) $request->ip();
        if ($this->adminLoginLockService->tooManyAttempts($username, $ipAddress)) {
            return $this->temporaryLockoutResponse($username, $ipAddress);
        }

        /** @var Admin|null $targetAdmin */
        $targetAdmin = Admin::query()->where('username', $username)->first();
        if ($targetAdmin instanceof Admin && $this->adminLoginLockService->isLocked($targetAdmin)) {
            return back()->withErrors([
                'username' => __('admin.login.error.account_locked'),
            ])->onlyInput('username');
        }

        // 后台以长期维护为主，默认保持登录 30 天，避免频繁掉线影响管理操作。
        $remember = $request->has('remember') ? $request->boolean('remember') : true;

        if (! Auth::guard('admin')->attempt(
            ['username' => $username, 'password' => $credentials['password'], 'status' => 'active'],
            $remember
        )) {
            if ($this->adminLoginLockService->recordFailedAttempt($username, $ipAddress)) {
                return $this->temporaryLockoutResponse($username, $ipAddress);
            }

            return back()->withErrors([
                'username' => __('admin.login.error.invalid_credentials'),
            ])->onlyInput('username');
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $request->session()->regenerate();
        $request->session()->put(Admin::AUTH_VERSION_SESSION_KEY, (int) $admin->auth_version);
        $this->adminLoginLockService->clearFailedAttempts((string) $admin->username, $ipAddress);

        $admin->forceFill(['last_login' => now()])->save();
        AdminActivityLogger::logFromRequest($request, $admin, 'auth:login', [
            'username' => (string) $admin->username,
        ]);
        defer(fn () => $this->anonymousUsageTelemetry->reportAdminLogin($admin, 'web'));

        return redirect()->intended(route('admin.dashboard'));
    }

    private function temporaryLockoutResponse(string $username, string $ipAddress): RedirectResponse
    {
        $seconds = max(1, $this->adminLoginLockService->availableIn($username, $ipAddress));

        return back()->withErrors([
            'username' => __('admin.login.error.too_many_attempts', ['seconds' => $seconds]),
        ])->onlyInput('username');
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

    /**
     * 登录页只在首次部署、默认管理员尚未成功登录时给出一次性提示。
     * 提示中永远不包含密码，只引导管理员查看受保护的初始化日志。
     *
     * @return array{enabled: bool, username?: string, storage_key?: string}
     */
    private function initialAdminHint(): array
    {
        if (! (bool) config('geoflow.initial_admin_hint_enabled', true)) {
            return ['enabled' => false];
        }

        $username = trim((string) config('geoflow.initial_admin_username', 'admin'));
        if ($username === '') {
            return ['enabled' => false];
        }

        try {
            /** @var Admin|null $admin */
            $admin = Admin::query()
                ->where('username', $username)
                ->first(['id', 'username', 'password', 'status', 'last_login']);
        } catch (Throwable) {
            return ['enabled' => false];
        }

        if (! $admin instanceof Admin || (string) $admin->status !== 'active' || $admin->last_login !== null) {
            return ['enabled' => false];
        }

        $storageKey = 'geoflow.initial-admin-hint.'.sha1($username.'|'.(string) config('geoflow.app_version'));

        return [
            'enabled' => true,
            'username' => $username,
            'storage_key' => $storageKey,
        ];
    }
}
