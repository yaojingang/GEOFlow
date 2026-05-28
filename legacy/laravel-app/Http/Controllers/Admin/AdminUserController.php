<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * 管理员管理控制器（超级管理员专用）。
 *
 * 对齐 bak/admin/admin-users.php 核心能力：
 * 1. 查看管理员列表及统计；
 * 2. 创建普通管理员账号；
 * 3. 编辑、启停、删除普通管理员账号。
 */
class AdminUserController extends Controller
{
    /**
     * 管理员管理首页。
     */
    public function index(): View
    {
        $admins = $this->loadAdmins();

        return view('admin.admin-users.index', [
            'pageTitle' => __('admin.admin_users.page_title'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'admins' => $admins,
            'stats' => [
                'total_admins' => count($admins),
                'active_admins' => count(array_filter($admins, static fn (array $admin): bool => $admin['status'] === 'active')),
                'super_admins' => count(array_filter($admins, static fn (array $admin): bool => $admin['is_super_admin'])),
            ],
            'currentAdminId' => (int) (auth('admin')->id() ?? 0),
        ]);
    }

    /**
     * 编辑管理员基础信息；超级管理员只能编辑自己，密码留空时不修改。
     */
    public function update(int $adminId, Request $request): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        $isSelf = (int) $targetAdmin->id === $currentAdminId;
        if ($targetAdmin->isSuperAdmin() && ! $isSelf) {
            return back()->withErrors(__('admin.admin_users.error.cannot_edit_super_admin'));
        }

        $payload = $request->validate([
            'username' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_.-]{3,50}$/',
                Rule::unique('admins', 'username')->ignore($targetAdmin->id),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['nullable', 'string', 'min:8'],
        ], [
            'username.required' => __('admin.admin_users.error.username_required'),
            'username.regex' => __('admin.admin_users.error.username_invalid'),
            'username.unique' => __('admin.admin_users.error.username_exists'),
            'status.required' => __('admin.admin_users.error.status_invalid'),
            'status.in' => __('admin.admin_users.error.status_invalid'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
        ]);

        try {
            $attributes = [
                'username' => trim((string) $payload['username']),
                'display_name' => trim((string) ($payload['display_name'] ?? '')),
                'email' => trim((string) ($payload['email'] ?? '')),
                'status' => $isSelf ? (string) $targetAdmin->status : (string) $payload['status'],
            ];

            if (filled($payload['password'] ?? null)) {
                $attributes['password'] = (string) $payload['password'];
            }

            $targetAdmin->update($attributes);

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.update_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.update_error', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /**
     * 创建普通管理员。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{3,50}$/', 'unique:admins,username'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['required', 'string', 'min:8'],
        ], [
            'username.required' => __('admin.admin_users.error.username_required'),
            'username.regex' => __('admin.admin_users.error.username_invalid'),
            'username.unique' => __('admin.admin_users.error.username_exists'),
            'password.required' => __('admin.admin_users.error.password_required'),
            'confirm_password.required' => __('admin.admin_users.error.password_required'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
        ]);

        try {
            Admin::query()->create([
                'username' => trim((string) $payload['username']),
                'display_name' => trim((string) ($payload['display_name'] ?? '')),
                'email' => trim((string) ($payload['email'] ?? '')),
                'password' => (string) $payload['password'],
                'role' => 'admin',
                'status' => 'active',
                'created_by' => (int) (auth('admin')->id() ?? 0),
            ]);

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.create_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.create_error', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /**
     * 切换普通管理员状态（启用/停用）。
     */
    public function toggleStatus(int $adminId, Request $request): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        if ((int) $targetAdmin->id === $currentAdminId) {
            return back()->withErrors(__('admin.admin_users.error.cannot_toggle_self'));
        }
        if ($targetAdmin->isSuperAdmin()) {
            return back()->withErrors(__('admin.admin_users.error.cannot_toggle_super_admin'));
        }

        $requestedNextStatus = (string) $request->input('next_status', '');
        $nextStatus = $requestedNextStatus === 'active' ? 'active' : 'inactive';

        try {
            $targetAdmin->update([
                'status' => $nextStatus,
            ]);

            $messageKey = $nextStatus === 'active'
                ? 'admin.admin_users.message.enabled'
                : 'admin.admin_users.message.disabled';

            return redirect()->route('admin.admin-users.index')->with('message', __($messageKey));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.toggle_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 删除普通管理员账号。
     */
    public function destroy(int $adminId): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        if ((int) $targetAdmin->id === $currentAdminId) {
            return back()->withErrors(__('admin.admin_users.error.cannot_delete_self'));
        }
        if ($targetAdmin->isSuperAdmin()) {
            return back()->withErrors(__('admin.admin_users.error.cannot_delete_super_admin'));
        }

        try {
            DB::transaction(static function () use ($targetAdmin, $currentAdminId): void {
                DB::table('admins')
                    ->where('created_by', $targetAdmin->id)
                    ->update(['created_by' => null]);

                if (Schema::hasTable('article_reviews')) {
                    // article_reviews.admin_id is non-null in the legacy schema; keep old review rows valid.
                    DB::table('article_reviews')
                        ->where('admin_id', $targetAdmin->id)
                        ->update(['admin_id' => $currentAdminId]);
                }

                $targetAdmin->delete();
            });

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.delete_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.delete_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   username:string,
     *   email:string,
     *   display_name:string,
     *   role:string,
     *   status:string,
     *   is_super_admin:bool,
     *   last_login:string,
     *   created_at:string,
     *   creator_username:string,
     *   activity_count:int
     * }>
     */
    private function loadAdmins(): array
    {
        $query = Admin::query()
            ->select([
                'id',
                'username',
                'email',
                'display_name',
                'role',
                'status',
                'last_login',
                'created_at',
                'created_by',
            ])
            ->with(['creator:id,username'])
            // 与 bak 一致：超级管理员置顶，其余按创建时间和 ID 升序。
            ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->orderBy('id');

        if (Schema::hasTable('admin_activity_logs')) {
            $query->withCount('activityLogs as activity_count');
        }

        $admins = $query->get();

        return $admins->map(static function (Admin $admin): array {
            return [
                'id' => (int) $admin->id,
                'username' => (string) ($admin->username ?? ''),
                'email' => (string) ($admin->email ?? ''),
                'display_name' => (string) ($admin->display_name ?? ''),
                'role' => (string) ($admin->role ?? 'admin'),
                'status' => (string) ($admin->status ?? 'active'),
                'is_super_admin' => $admin->isSuperAdmin(),
                'last_login' => $admin->last_login?->format('Y-m-d H:i:s') ?? '',
                'created_at' => $admin->created_at?->format('Y-m-d H:i:s') ?? '',
                'creator_username' => (string) ($admin->creator?->username ?? ''),
                'activity_count' => (int) ($admin->activity_count ?? 0),
            ];
        })->all();
    }
}
