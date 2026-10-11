<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinkPoolThemeAdaptation;
use App\Services\LinkPool\LinkPoolThemeAdaptationService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LinkPoolThemeAdaptationController extends Controller
{
    public function __construct(
        private readonly LinkPoolThemeAdaptationService $adaptations,
        private readonly LinkPoolSiteService $sites,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['site_key' => ['required', 'string', 'max:100']]);
        $task = $this->adaptations->start($data['site_key'], auth('admin')->user());

        return response()->json($this->present($task), 202);
    }

    public function show(string $adaptationId): JsonResponse
    {
        $task = LinkPoolThemeAdaptation::query()->findOrFail($adaptationId);
        $this->assertSite($task->site_key);

        return response()->json($this->present($task));
    }

    public function rollback(string $adaptationId): JsonResponse
    {
        $task = LinkPoolThemeAdaptation::query()->findOrFail($adaptationId);
        $this->assertSite($task->site_key);
        $this->adaptations->rollback($task, auth('admin')->user());

        return response()->json($this->present($task->refresh()));
    }

    private function assertSite(string $siteKey): void
    {
        abort_if($siteKey !== 'primary' && $this->sites->profile($siteKey) === null, 404);
    }

    /** @return array<string,mixed> */
    private function present(LinkPoolThemeAdaptation $task): array
    {
        return $task->only([
            'id', 'site_key', 'theme_id', 'status', 'phase', 'error_code', 'error_message',
            'article_url', 'candidate_revision_id', 'started_at', 'finished_at',
            'created_at',
        ]) + ['status_url' => route('admin.link-pool.theme-adaptations.show', $task->id, false)];
    }
}
