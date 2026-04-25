<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminWelcomeModalService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 欢迎弹窗：用户关闭后持久化已读状态与关闭时间。
 */
class AdminWelcomeController extends Controller
{
    /**
     * 记录关闭时间并同步 welcome_seen_version。
     */
    public function dismiss(Request $request, AdminWelcomeModalService $welcomeModalService): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $admin->forceFill([
            'welcome_dismissed_at' => now(),
            'welcome_seen_version' => $welcomeModalService->currentWelcomeVersionKey(),
        ])->save();

        AdminActivityLogger::logFromRequest($request, $admin, 'welcome:dismiss', [
            'admin_id' => (int) $admin->id,
        ]);

        return response()->json(['success' => true]);
    }
}
