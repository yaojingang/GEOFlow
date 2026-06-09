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
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

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

    /**
     * 登录页只在首次部署、默认管理员尚未成功登录时给出一次性提示。
     * 未显式配置密码时，Seeder 可能生成随机密码，只能提示查看初始化日志。
     *
     * @return array{enabled: bool, mode?: string, username?: string, password?: string, storage_key?: string}
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

        $password = (string) config('geoflow.initial_admin_password', '');
        $storageKey = 'geoflow.initial-admin-hint.'.sha1($username.'|'.(string) config('geoflow.app_version'));

        if ($password !== '') {
            if (Hash::check($password, (string) $admin->password)) {
                return [
                    'enabled' => true,
                    'mode' => 'known',
                    'username' => $username,
                    'password' => $password,
                    'storage_key' => $storageKey,
                ];
            }

            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'mode' => 'log',
            'username' => $username,
            'storage_key' => $storageKey,
        ];
    }
}
