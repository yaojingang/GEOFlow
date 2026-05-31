<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeoTopicPipelineJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\Organization;
use App\Services\Geo\GeoTopicToPublishPackagePipeline;
use App\Services\Geo\GeoWebWorkbenchClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GeoTopicPipelineController extends Controller
{
    public function run(Request $request, GeoTopicToPublishPackagePipeline $pipeline): RedirectResponse
    {
        $payload = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'platform_codes' => ['required', 'array', 'min:2'],
            'platform_codes.*' => ['required', 'string'],
            'max_references' => ['nullable', 'integer', 'min:1', 'max:5'],
        ], [
            'platform_codes.min' => '至少选择两个 AI 平台，才能做交叉对比',
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再运行完整 GEO 选题链路');
        }

        $platformCodes = $this->normalizePlatformCodes((array) $payload['platform_codes']);
        if (count($platformCodes) < 2) {
            return back()->withErrors('至少选择两个已启用 AI 平台，才能做交叉对比');
        }

        if ((bool) config('geoflow.geo_async_jobs', false)) {
            GeoTopicPipelineJob::dispatch(
                (int) $admin->id,
                (int) $organization->id,
                (int) $brandProfile->id,
                (string) $payload['topic'],
                $platformCodes,
                (int) ($payload['max_references'] ?? 3)
            );

            return redirect()
                ->route('admin.geo.workspace')
                ->with('message', '完整 GEO 选题链路已进入队列，完成后可在草稿到发布链路查看结果');
        }

        try {
            $draft = $pipeline->run(
                $admin,
                $organization,
                $brandProfile,
                (string) $payload['topic'],
                $platformCodes,
                (int) ($payload['max_references'] ?? 3)
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return redirect()
                ->route('admin.geo.workspace')
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '完整 GEO 选题链路已跑完：'.$draft->title);
    }

    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function resolveOrganization(Admin $admin): Organization
    {
        $fallbackName = trim((string) ($admin->display_name ?: $admin->username)) ?: '默认企业';

        return Organization::query()->firstOrCreate(
            ['owner_admin_id' => $admin->id],
            [
                'name' => $fallbackName,
                'plan_code' => 'trial',
                'points' => 100,
                'balance' => 0,
                'status' => 'active',
            ]
        );
    }

    private function loadBrandProfile(Organization $organization): ?BrandProfile
    {
        return BrandProfile::query()
            ->where('organization_id', $organization->id)
            ->latest()
            ->first();
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private function normalizePlatformCodes(array $codes): array
    {
        $activeCodes = array_merge($this->defaultPlatformCodes(), $this->activeAiModelCodes());
        $webWorkbenchEnabled = in_array(GeoWebWorkbenchClient::PLATFORM_CODE, $activeCodes, true);

        return collect($codes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->filter(static function (string $code) use ($activeCodes, $webWorkbenchEnabled): bool {
                if (in_array($code, $activeCodes, true)) {
                    return true;
                }

                return $webWorkbenchEnabled
                    && str_starts_with($code, GeoWebWorkbenchClient::PLATFORM_CODE.':')
                    && preg_match('/^'.preg_quote(GeoWebWorkbenchClient::PLATFORM_CODE, '/').':[a-z0-9_-]+$/', $code) === 1;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function defaultPlatformCodes(): array
    {
        $defaults = [
            ['name' => GeoWebWorkbenchClient::PLATFORM_NAME, 'code' => GeoWebWorkbenchClient::PLATFORM_CODE, 'api_mode' => 'web_workbench', 'cost_per_query' => 1],
            ['name' => 'DeepSeek 模拟', 'code' => 'deepseek_mock', 'api_mode' => 'mock', 'cost_per_query' => 1],
            ['name' => 'Kimi 模拟', 'code' => 'kimi_mock', 'api_mode' => 'mock', 'cost_per_query' => 1],
            ['name' => '通义千问模拟', 'code' => 'qwen_mock', 'api_mode' => 'mock', 'cost_per_query' => 1],
        ];

        foreach ($defaults as $default) {
            GeoAiPlatform::query()->updateOrCreate(
                ['code' => $default['code']],
                $default + ['status' => 'active']
            );
        }

        return GeoAiPlatform::query()
            ->where('status', 'active')
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function activeAiModelCodes(): array
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')
                    ->orWhere('daily_limit', 0)
                    ->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get(['id'])
            ->map(static fn (AiModel $model): string => 'ai_model:'.(int) $model->id)
            ->values()
            ->all();
    }
}
