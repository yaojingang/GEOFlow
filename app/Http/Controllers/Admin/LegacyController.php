<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Support\AdminWeb;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * bak/admin 各菜单入口的 Blade 占位页（统一布局 + 文案）；后续可拆成独立控制器。
 */
class LegacyController extends Controller
{
    private function stub(string $pageTitleKey, string $activeMenu, ?string $stubHint = null): View
    {
        return view('admin.stub', [
            'pageTitle' => __($pageTitleKey),
            'activeMenu' => $activeMenu,
            'adminSiteName' => AdminWeb::siteName(),
            'stubHint' => $stubHint,
        ]);
    }

    /**
     * 任务管理列表（最小可用版）：支持关键词、状态筛选与分页。
     */
    public function tasks(Request $request): View
    {
        $filters = $this->buildTaskFilters($request);

        return view('admin.tasks.index', [
            'pageTitle' => __('admin.tasks.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'tasks' => $this->queryTasks($filters),
            'filters' => $filters,
            'statusOptions' => [
                'active' => __('admin.tasks.status.running'),
                'paused' => __('admin.tasks.status.paused'),
            ],
        ]);
    }

    /**
     * @return array{keyword: string, status: string}
     */
    private function buildTaskFilters(Request $request): array
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $status = (string) $request->query('status', '');

        if (! in_array($status, ['active', 'paused'], true)) {
            $status = '';
        }

        return [
            'keyword' => $keyword,
            'status' => $status,
        ];
    }

    /**
     * @param  array{keyword: string, status: string}  $filters
     */
    private function queryTasks(array $filters): LengthAwarePaginator
    {
        try {
            $query = Task::query()
                ->select([
                    'id',
                    'name',
                    'status',
                    'title_library_id',
                    'ai_model_id',
                    'created_count',
                    'published_count',
                    'loop_count',
                    'updated_at',
                    'created_at',
                ])
                ->with([
                    'titleLibrary:id,name',
                    'aiModel:id,name',
                ])
                ->orderByDesc('id');

            if ($filters['keyword'] !== '') {
                $query->where('name', 'like', '%'.$filters['keyword'].'%');
            }

            if ($filters['status'] !== '') {
                $query->where('status', $filters['status']);
            }

            return $query->paginate(15)->withQueryString();
        } catch (QueryException) {
            return $this->emptyTasksPaginator();
        }
    }

    /**
     * 兜底空分页：测试环境缺少 tasks 表时保持页面可渲染。
     */
    private function emptyTasksPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: 15,
            currentPage: max(1, (int) request()->query('page', 1)),
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    public function articles(): View
    {
        return $this->stub('admin.articles.page_title', 'articles');
    }

    public function materials(): View
    {
        return $this->stub('admin.materials.page_title', 'materials');
    }

    public function aiConfigurator(): View
    {
        return view('admin.ai-configurator.index', [
            'pageTitle' => __('admin.ai_configurator.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'stats' => $this->loadAiConfiguratorStats(),
        ]);
    }

    /**
     * AI 模型配置页（迁移占位）。
     */
    public function aiModels(): View
    {
        return $this->stub('admin.ai_models.page_title', 'ai_config');
    }

    /**
     * 正文提示词配置页（迁移占位）。
     */
    public function aiPrompts(): View
    {
        return $this->stub('admin.ai_prompts.page_title', 'ai_config');
    }

    /**
     * 特殊提示词配置页（迁移占位）。
     */
    public function aiSpecialPrompts(): View
    {
        return $this->stub('admin.ai_special.page_title', 'ai_config');
    }

    public function siteSettings(): View
    {
        return $this->stub('admin.site_settings.page_title', 'site_settings');
    }

    public function securitySettings(): View
    {
        return $this->stub('admin.security.page_title', 'security');
    }

    public function adminUsers(): View
    {
        return $this->stub('admin.admin_users.page_title', 'admin_users');
    }

    public function apiTokens(): View
    {
        return $this->stub('admin.api_tokens.page_title', 'admin_users');
    }

    public function adminActivityLogs(): View
    {
        return $this->stub('admin.activity_logs.page_title', 'admin_users');
    }

    /**
     * 加载 AI 配置器概览统计。
     *
     * @return array{model_count:int,prompt_count:int,total_usage:int,today_usage:int}
     */
    private function loadAiConfiguratorStats(): array
    {
        return [
            'model_count' => AiModel::query()->where('status', 'active')->count(),
            'prompt_count' => Prompt::query()->count(),
            'total_usage' => (int) (AiModel::query()->sum('total_used') ?? 0),
            'today_usage' => (int) (AiModel::query()->sum('used_today') ?? 0),
        ];
    }
}
