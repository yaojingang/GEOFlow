<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AI 模型配置控制器。
 *
 * 对齐 bak 的 ai-models 页面能力：
 * 1. 模型增删改（聊天模型 + embedding 模型）；
 * 2. 默认 embedding 模型配置；
 * 3. 模型使用统计展示（任务引用数、文章产出数、调用次数）；
 * 4. 兼容历史 enc:v1 API Key 的加解密读写。
 */
class AiModelController extends Controller
{
    /**
     * 注入统一 API Key 加解密工具，避免控制器内重复维护密钥兼容逻辑。
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * AI 模型列表页。
     *
     * 输出页面所需完整数据：模型列表、可选 embedding 模型、默认 embedding 模型 ID、
     * 以及 pgvector 可用状态，保证页面在一个请求内即可渲染。
     */
    public function index(): View
    {
        return view('admin.ai-models.index', [
            'pageTitle' => __('admin.ai_models.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->loadModels(),
            'embeddingModels' => $this->loadActiveEmbeddingModels(),
            'defaultEmbeddingModelId' => $this->getDefaultEmbeddingModelId(),
            'pgvectorEnabled' => $this->isPgvectorEnabled(),
        ]);
    }

    /**
     * 创建 AI 模型。
     *
     * 说明：
     * - 创建时 API Key 必填，并在写入前加密；
     * - 若新增的是 embedding 模型，且系统尚未设置默认 embedding，则自动兜底为该模型。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateModelPayload($request, false);

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey === '') {
            return back()->withErrors(__('admin.ai_models.error.required_fields'));
        }

        try {
            $createdModel = AiModel::query()->create([
                'name' => trim((string) $payload['name']),
                'version' => trim((string) ($payload['version'] ?? '')),
                'api_key' => $this->encryptApiKey($apiKey),
                'model_id' => trim((string) $payload['model_id']),
                'model_type' => $this->normalizeModelType((string) ($payload['model_type'] ?? 'chat')),
                'api_url' => trim((string) ($payload['api_url'] ?? '')),
                'failover_priority' => max(1, (int) ($payload['failover_priority'] ?? 100)),
                'daily_limit' => max(0, (int) ($payload['daily_limit'] ?? 0)),
                'status' => 'active',
            ]);
        } catch (\RuntimeException) {
            return back()->withInput()->withErrors(__('admin.ai_models.error.crypto_key_missing'));
        }

        // 当系统尚未指定默认 embedding 模型时，首次创建 embedding 模型自动兜底。
        if ($createdModel->model_type === 'embedding' && $this->getDefaultEmbeddingModelId() <= 0) {
            $this->setDefaultEmbeddingModelId((int) $createdModel->id);
        }

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.create_success'));
    }

    /**
     * 更新 AI 模型。
     *
     * 说明：
     * - 编辑时 API Key 可留空（留空表示保留原值）；
     * - 若当前模型是默认 embedding，但更新后不再是“active + embedding”，
     *   则清空默认 embedding 配置，避免指向不可用模型。
     */
    public function update(Request $request, int $modelId): RedirectResponse
    {
        $model = AiModel::query()->whereKey($modelId)->firstOrFail();
        $payload = $this->validateModelPayload($request, true);

        $modelType = $this->normalizeModelType((string) ($payload['model_type'] ?? 'chat'));
        $status = (string) ($payload['status'] ?? 'active');
        if (! in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $updateData = [
            'name' => trim((string) $payload['name']),
            'version' => trim((string) ($payload['version'] ?? '')),
            'model_id' => trim((string) $payload['model_id']),
            'model_type' => $modelType,
            'api_url' => trim((string) ($payload['api_url'] ?? '')),
            'failover_priority' => max(1, (int) ($payload['failover_priority'] ?? 100)),
            'daily_limit' => max(0, (int) ($payload['daily_limit'] ?? 0)),
            'status' => $status,
        ];

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey !== '') {
            try {
                $updateData['api_key'] = $this->encryptApiKey($apiKey);
            } catch (\RuntimeException) {
                return back()->withInput()->withErrors(__('admin.ai_models.error.crypto_key_missing'));
            }
        }

        $model->update($updateData);

        $defaultEmbeddingModelId = $this->getDefaultEmbeddingModelId();
        if ($defaultEmbeddingModelId === (int) $model->id && ($modelType !== 'embedding' || $status !== 'active')) {
            $this->setDefaultEmbeddingModelId(0);
        }

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.update_success'));
    }

    /**
     * 删除 AI 模型（被任务引用时阻止删除）。
     *
     * 删除策略：
     * - 仅当任务引用数为 0 时允许删除；
     * - 若删除目标是默认 embedding 模型，同步清空默认设置。
     */
    public function destroy(int $modelId): RedirectResponse
    {
        $model = AiModel::query()->whereKey($modelId)->firstOrFail();
        $taskCount = $model->tasks()->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.ai_models.error.in_use', ['count' => $taskCount]));
        }

        $model->delete();
        if ($this->getDefaultEmbeddingModelId() === (int) $model->id) {
            $this->setDefaultEmbeddingModelId(0);
        }

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.delete_success'));
    }

    /**
     * 更新默认 embedding 模型设置。
     *
     * 约束：
     * - 只允许选择 active + embedding 的模型；
     * - 允许传 0，表示恢复自动选择策略。
     */
    public function updateDefaultEmbedding(Request $request): RedirectResponse
    {
        $modelId = max(0, (int) $request->input('default_embedding_model_id', 0));

        if ($modelId > 0) {
            $available = AiModel::query()
                ->whereKey($modelId)
                ->where('status', 'active')
                ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
                ->exists();

            if (! $available) {
                return back()->withErrors(__('admin.ai_models.error.embedding_unavailable'));
            }
        }

        $this->setDefaultEmbeddingModelId($modelId);

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.embedding_default_updated'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadModels(): array
    {
        $models = AiModel::query()
            ->select([
                'id',
                'name',
                'version',
                'api_key',
                'model_id',
                'model_type',
                'api_url',
                'failover_priority',
                'daily_limit',
                'used_today',
                'total_used',
                'status',
                'created_at',
                'updated_at',
            ])
            ->withCount('tasks as task_count')
            ->addSelect([
                'article_count' => Article::query()
                    ->selectRaw('COUNT(articles.id)')
                    ->join('tasks', 'articles.task_id', '=', 'tasks.id')
                    ->whereColumn('tasks.ai_model_id', 'ai_models.id'),
            ])
            ->orderByDesc('created_at')
            ->get();

        $defaultEmbeddingModelId = $this->getDefaultEmbeddingModelId();

        return $models->map(function (AiModel $model) use ($defaultEmbeddingModelId): array {
            $modelType = $this->normalizeModelType((string) ($model->model_type ?? 'chat'));

            return [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'version' => (string) ($model->version ?? ''),
                'model_id' => (string) $model->model_id,
                'model_type' => $modelType,
                'api_url' => (string) ($model->api_url ?? ''),
                'failover_priority' => (int) ($model->failover_priority ?? 100),
                'daily_limit' => (int) ($model->daily_limit ?? 0),
                'used_today' => (int) ($model->used_today ?? 0),
                'total_used' => (int) ($model->total_used ?? 0),
                'status' => (string) ($model->status ?? 'active'),
                'task_count' => (int) ($model->task_count ?? 0),
                'article_count' => (int) ($model->article_count ?? 0),
                'masked_api_key' => $this->maskApiKey((string) ($model->getRawOriginal('api_key') ?? '')),
                'is_default_embedding' => $modelType === 'embedding' && $defaultEmbeddingModelId === (int) $model->id,
            ];
        })->all();
    }

    /**
     * 可用于默认 embedding 下拉框的模型列表。
     *
     * @return array<int, array{id:int,name:string,model_id:string}>
     */
    private function loadActiveEmbeddingModels(): array
    {
        return AiModel::query()
            ->select(['id', 'name', 'model_id'])
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
            ->orderBy('name')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $model): array => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'model_id' => (string) ($model->model_id ?? ''),
            ])
            ->all();
    }

    /**
     * 校验模型表单字段。
     *
     * @param  bool  $isUpdate  true 表示编辑模式（允许 api_key 为空）
     * @return array<string, mixed>
     */
    private function validateModelPayload(Request $request, bool $isUpdate): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'api_key' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:500'],
            'model_id' => ['required', 'string', 'max:100'],
            'model_type' => ['required', 'in:chat,embedding'],
            'api_url' => ['nullable', 'string', 'max:500'],
            'failover_priority' => ['nullable', 'integer', 'min:1'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
        ];
        if ($isUpdate) {
            $rules['status'] = ['nullable', 'in:active,inactive'];
        }

        return $request->validate($rules);
    }

    /**
     * 标准化模型类型，避免脏数据影响下拉筛选和默认模型逻辑。
     */
    private function normalizeModelType(string $modelType): string
    {
        $normalized = trim(strtolower($modelType));

        return in_array($normalized, ['chat', 'embedding'], true) ? $normalized : 'chat';
    }

    /**
     * 读取默认 embedding 模型 ID。
     */
    private function getDefaultEmbeddingModelId(): int
    {
        return (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);
    }

    /**
     * 更新默认 embedding 模型 ID。
     */
    private function setDefaultEmbeddingModelId(int $modelId): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) max(0, $modelId)]
        );
    }

    /**
     * pgvector 可用性检测（仅在 PostgreSQL 下尝试查询扩展）。
     *
     * 说明：
     * - 非 pgsql 直接返回 false；
     * - 查询异常统一回退为 false，避免阻塞页面主流程。
     */
    private function isPgvectorEnabled(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $row = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'vector' LIMIT 1");

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 对 API key 做掩码显示。
     */
    private function maskApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->mask($storedApiKey);
    }

    /**
     * 写入 ai_models 前的 API key 加密（兼容旧系统 enc:v1）。
     */
    private function encryptApiKey(string $apiKey): string
    {
        return $this->apiKeyCrypto->encrypt($apiKey);
    }

    /**
     * 读取 ai_models 中加密 API key（兼容历史 enc:v1 数据）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }
}
