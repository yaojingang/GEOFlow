<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminAccountPasswordRequest;
use App\Http\Requests\Admin\UpdateAdminAccountProfileRequest;
use App\Models\Admin;
use App\Support\AdminAccountProfileVersion;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AdminAccountController extends Controller
{
    public function show(): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return view('admin.account.show', [
            'pageTitle' => __('admin.account.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'admin' => $admin,
        ]);
    }

    public function updateProfile(UpdateAdminAccountProfileRequest $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $payload = $request->validated();

        DB::transaction(function () use ($admin, $payload): void {
            $lockedAdmin = Admin::query()->whereKey($admin->getKey())->lockForUpdate()->firstOrFail();
            $currentVersion = AdminAccountProfileVersion::for($lockedAdmin);
            if (! hash_equals($currentVersion, (string) $payload['profile_version'])) {
                throw ValidationException::withMessages([
                    'profile_version' => __('admin.account.validation.profile_conflict'),
                ]);
            }

            $lockedAdmin->update([
                'display_name' => $payload['display_name'] ?? null,
                'email' => $payload['email'] ?? null,
            ]);
        });

        return back()->with('message', __('admin.account.message.profile_updated'));
    }

    public function updatePassword(UpdateAdminAccountPasswordRequest $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $payload = $request->validated();

        DB::transaction(function () use ($admin, $payload): void {
            $lockedAdmin = Admin::query()->whereKey($admin->getKey())->lockForUpdate()->firstOrFail();
            if (! Hash::check((string) $payload['current_password'], (string) $lockedAdmin->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('admin.account.validation.current_password'),
                ]);
            }

            $lockedAdmin->forceFill(['password' => (string) $payload['password']])->save();
            $lockedAdmin->revokeAuthenticationCredentials();
        });

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('message', __('admin.account.message.password_updated'));
    }
}
