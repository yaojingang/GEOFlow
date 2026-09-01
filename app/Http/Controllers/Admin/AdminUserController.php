<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminAiSharingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Models\Admin;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiSharingService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    public function __construct(
        private readonly AdminAiSharingService $sharingService,
        private readonly AdminAiDependencyInspector $dependencyInspector,
    ) {}

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

    public function create(): View
    {
        /** @var Admin $actor */
        $actor = auth('admin')->user();

        return view('admin.admin-users.create', [
            'pageTitle' => __('admin.admin_users.modal_create'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'sharedProvider' => $actor,
        ]);
    }

    public function edit(int $adminId): View
    {
        /** @var Admin $actor */
        $actor = auth('admin')->user();
        $targetAdmin = Admin::query()
            ->select([
                'id',
                'username',
                'display_name',
                'email',
                'role',
                'status',
                'shared_ai_config_owner_id',
                'ai_config_access_version',
            ])
            ->with(['sharedAiConfigOwner:id,username,display_name,status'])
            ->whereKey($adminId)
            ->firstOrFail();
        $isSelf = (int) $targetAdmin->id === (int) (auth('admin')->id() ?? 0);

        abort_if($targetAdmin->isSuperAdmin() && ! $isSelf, 403);

        return view('admin.admin-users.edit', [
            'pageTitle' => __('admin.admin_users.modal_edit'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'targetAdmin' => $targetAdmin,
            'isSelf' => $isSelf,
            'sharedProvider' => $targetAdmin->sharedAiConfigOwner ?? $actor,
            'switchSharedProvider' => $targetAdmin->sharedAiConfigOwner !== null
                && ! $targetAdmin->sharedAiConfigOwner->is($actor)
                    ? $actor
                    : null,
            'sharingImpact' => $this->dependencyInspector->sharingImpact($targetAdmin),
        ]);
    }

    /**
     * 编辑管理员基础信息；超级管理员只能编辑自己，密码留空时不修改。
     */
    public function update(int $adminId, UpdateAdminUserRequest $request): RedirectResponse
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

        $payload = $request->validated();

        try {
            /** @var Admin $actor */
            $actor = $request->user('admin');
            $this->sharingService->updateAdmin(
                $actor,
                $targetAdmin,
                $payload,
                $targetAdmin->isSuperAdmin() ? null : (string) $payload['ai_config_mode'],
                $targetAdmin->isSuperAdmin()
                    ? null
                    : (int) $payload['expected_ai_config_access_version'],
                $targetAdmin->isSuperAdmin() || blank($payload['expected_shared_ai_config_owner_id'] ?? null)
                    ? null
                    : (int) $payload['expected_shared_ai_config_owner_id'],
                ! $targetAdmin->isSuperAdmin() && (bool) ($payload['switch_shared_provider'] ?? false),
            );

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.update_success'));
        } catch (AdminAiSharingException $exception) {
            return back()
                ->withErrors(['ai_config_mode' => __('admin.admin_users.error.ai_config_access_conflict')])
                ->withInput($request->safeInput());
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(__('admin.admin_users.message.update_error'))
                ->withInput($request->safeInput());
        }
    }

    /**
     * 创建普通管理员。
     */
    public function store(StoreAdminUserRequest $request): RedirectResponse
    {
        $payload = $request->validated();

        try {
            /** @var Admin $actor */
            $actor = $request->user('admin');
            $this->sharingService->createOrdinaryAdmin(
                $actor,
                $payload,
                (string) $payload['ai_config_mode'],
            );

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.create_success'));
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(__('admin.admin_users.message.create_error'))
                ->withInput($request->safeInput());
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
            /** @var Admin $actor */
            $actor = $request->user('admin');
            $this->sharingService->changeOrdinaryStatus($actor, $targetAdmin, $nextStatus);

            $messageKey = $nextStatus === 'active'
                ? 'admin.admin_users.message.enabled'
                : 'admin.admin_users.message.disabled';

            return redirect()->route('admin.admin-users.index')->with('message', __($messageKey));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(__('admin.admin_users.message.toggle_error'));
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
            DB::transaction(function () use ($targetAdmin, $currentAdminId): void {
                $lockedTarget = Admin::query()
                    ->whereKey($targetAdmin->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $dependencies = $this->dependencyInspector->deletionDependencies($lockedTarget);
                if ($dependencies->blocksDeletion()) {
                    throw AdminAiSharingException::deleteBlocked(
                        (int) $lockedTarget->getKey(),
                        $dependencies->counts(),
                    );
                }

                DB::table('admins')
                    ->where('created_by', $lockedTarget->id)
                    ->update(['created_by' => null]);

                if (Schema::hasTable('article_reviews')) {
                    // article_reviews.admin_id is non-null in the legacy schema; keep old review rows valid.
                    DB::table('article_reviews')
                        ->where('admin_id', $lockedTarget->id)
                        ->update(['admin_id' => $currentAdminId]);
                }

                $lockedTarget->revokeAuthenticationCredentials();
                $lockedTarget->delete();
            });

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.delete_success'));
        } catch (AdminAiSharingException $exception) {
            $context = $exception->context();

            return back()->withErrors([
                'admin' => __('admin.admin_users.error.delete_has_ai_dependencies', [
                    'models' => (int) ($context['owned_model_count'] ?? 0),
                    'tasks' => (int) ($context['pending_task_count'] ?? 0),
                    'dependents' => (int) ($context['dependent_admin_count'] ?? 0),
                ]),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(__('admin.admin_users.message.delete_error'));
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
     *   activity_count:int,
     *   ai_config_mode:string,
     *   shared_provider_name:string,
     *   shared_provider_status:string
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
                'shared_ai_config_owner_id',
            ])
            ->with([
                'creator:id,username',
                'sharedAiConfigOwner:id,username,display_name,status',
            ])
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
                'ai_config_mode' => $admin->isSuperAdmin()
                    ? 'super_self'
                    : ($admin->shared_ai_config_owner_id === null ? 'independent' : 'shared'),
                'shared_provider_name' => (string) ($admin->sharedAiConfigOwner?->name ?? ''),
                'shared_provider_status' => (string) ($admin->sharedAiConfigOwner?->status ?? ''),
            ];
        })->all();
    }
}
