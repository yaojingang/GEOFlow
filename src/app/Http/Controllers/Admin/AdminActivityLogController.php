<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Support\AdminWeb;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * 管理员操作日志控制器（超级管理员专用）。
 *
 * 对齐 bak/admin/admin-activity-logs.php 核心能力：
 * 1. 按管理员和关键词筛选日志；
 * 2. 展示日志统计（总量、今日、近7天活跃管理员）；
 * 3. 列表分页与详情预览。
 */
class AdminActivityLogController extends Controller
{
    /**
     * 操作日志列表页。
     */
    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);
        $logs = $this->queryLogs($filters);

        return view('admin.admin-activity-logs.index', [
            'pageTitle' => __('admin.activity_logs.page_title'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filters,
            'logs' => $logs,
            'admins' => $this->loadAdmins(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * @return array{search:string,admin_id:int}
     */
    private function buildFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'admin_id' => max(0, (int) $request->query('admin_id', 0)),
        ];
    }

    /**
     * @param  array{search:string,admin_id:int}  $filters
     */
    private function queryLogs(array $filters): LengthAwarePaginator
    {
        $query = AdminActivityLog::query()
            ->select([
                'id',
                'admin_id',
                'admin_username',
                'admin_role',
                'action',
                'request_method',
                'page',
                'target_type',
                'target_id',
                'ip_address',
                'details',
                'created_at',
            ])
            ->with(['admin:id,username,display_name,role'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['admin_id'] > 0) {
            $query->where('admin_id', $filters['admin_id']);
        }

        if ($filters['search'] !== '') {
            $keyword = '%'.$filters['search'].'%';
            $query->where(static function (Builder $builder) use ($keyword): void {
                $builder
                    ->where('admin_username', 'like', $keyword)
                    ->orWhere('action', 'like', $keyword)
                    ->orWhere('page', 'like', $keyword)
                    ->orWhere('details', 'like', $keyword);
            });
        }

        return $query->paginate(50)->withQueryString();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function loadAdmins(): array
    {
        return Admin::query()
            ->select(['id', 'username', 'display_name', 'role'])
            ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END")
            ->orderBy('username')
            ->get()
            ->map(static function (Admin $admin): array {
                $displayName = trim((string) ($admin->display_name ?? ''));
                $username = (string) ($admin->username ?? '');

                return [
                    'id' => (int) $admin->id,
                    'name' => $displayName !== '' ? $displayName.' / '.$username : $username,
                ];
            })
            ->all();
    }

    /**
     * @return array{total_logs:int,today_logs:int,active_admins:int}
     */
    private function loadStats(): array
    {
        return [
            'total_logs' => AdminActivityLog::query()->count(),
            'today_logs' => AdminActivityLog::query()->whereDate('created_at', Carbon::today())->count(),
            'active_admins' => AdminActivityLog::query()
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->distinct('admin_id')
                ->count('admin_id'),
        ];
    }
}
