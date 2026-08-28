<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminRecentActivityRequest;
use App\Models\Admin;
use App\Services\Admin\AdminRecentActivityService;
use Illuminate\Http\JsonResponse;

final class AdminRecentActivityController extends Controller
{
    public function __construct(private readonly AdminRecentActivityService $recentActivity) {}

    public function __invoke(ListAdminRecentActivityRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $validated = $request->validated();
        $page = $this->recentActivity->page(
            $admin,
            (int) ($validated['limit'] ?? 10),
            isset($validated['cursor']) ? (string) $validated['cursor'] : null,
        );

        return response()->json([
            'data' => $page['items'],
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }
}
