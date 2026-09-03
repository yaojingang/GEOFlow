<?php

namespace App\Http\Controllers\Admin;

use App\Data\Admin\AdminAiActorContext;
use App\Data\Admin\GovernanceAiModelData;
use App\Data\Admin\SharedAiModelData;
use App\Exceptions\AiModelAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelRequest;
use App\Http\Requests\Admin\UpdateAiModelRequest;
use App\Http\Requests\Admin\UpdateChunkingConfigRequest;
use App\Http\Requests\Admin\UpdateDefaultEmbeddingRequest;
use App\Http\Requests\Admin\UpdatePersonalAiDefaultsRequest;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiActorContextService;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\Admin\AdminAiModelTestBoundaryHook;
use App\Services\Admin\AdminAiModelTestPreparationService;
use App\Services\Admin\AdminAiSettingsService;
use App\Services\Admin\AdminAiSystemSettingsService;
use App\Services\Admin\GovernanceAiModelUsageSession;
use App\Services\Admin\GovernanceAiModelUsageSessionFactory;
use App\Services\AiWorkspace\AiWorkspaceModelCapabilityProbe;
use App\Services\GeoFlow\AiModelTestDiagnosisService;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiUsageReservation;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelTestDiagnosisService $modelTestDiagnosis,
        private readonly AiWorkspaceModelCapabilityProbe $aiWorkspaceModelProbe,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
        private readonly AdminAiModelAccessResolver $accessResolver,
        private readonly AdminAiActorContextService $actorContextService,
        private readonly AdminAiModelMutationService $mutationService,
        private readonly AdminAiModelTestPreparationService $testPreparation,
        private readonly AdminAiModelTestBoundaryHook $testBoundaryHook,
        private readonly AdminAiSettingsService $aiSettingsService,
        private readonly AdminAiSystemSettingsService $systemSettingsService,
        private readonly GovernanceAiModelUsageSessionFactory $usageSessions,
    ) {}

    /**
     * AI 模型列表页。
     *
     * 输出页面所需完整数据：模型列表、可选 embedding 模型、默认 embedding 模型 ID、
     * 以及 pgvector 可用状态，保证页面在一个请求内即可渲染。
     */
    public function index(Request $request): View
    {
        $authenticatedActor = $this->activeActor($request);
        $actorContext = $this->actorContextService->resolve($authenticatedActor);
        $actor = $actorContext->actor;
        Gate::forUser($actor)->authorize('viewAny', AiModel::class);
        $personalSettings = AdminAiSetting::query()
            ->where('admin_id', $actor->getKey())
            ->first();
        $myModels = $this->loadModels(
            $this->accessResolver->managementQuery($actor),
            (int) ($personalSettings?->default_embedding_model_id ?? 0),
        );
        $ordinaryPartitions = $actor->isSuperAdmin()
            ? ['shared_models' => [], 'default_options' => []]
            : $this->loadOrdinaryVisibleModelPartitions($actorContext);
        $sharedModels = $ordinaryPartitions['shared_models'];
        $governancePaginator = $actor->isSuperAdmin() ? $this->loadGovernanceModels($actor) : null;
        $governanceModels = $governancePaginator?->items() ?? [];
        $personalDefaultModelOptions = $actor->isSuperAdmin()
            ? $this->personalDefaultModelOptions($actor)
            : $ordinaryPartitions['default_options'];

        $viewData = [
            'pageTitle' => __('admin.ai_models.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $myModels,
            'myModels' => $myModels,
            'sharedModels' => $sharedModels,
            'governanceModels' => $governanceModels,
            'governancePaginator' => $governancePaginator,
            'showSystemConfiguration' => $actor->isSuperAdmin(),
            'actorIsSuperAdmin' => $actor->isSuperAdmin(),
            'accessPreview' => $actor->isSuperAdmin()
                ? null
                : $this->accessPreview($actorContext, $personalDefaultModelOptions),
            'personalDefaultModelOptions' => $personalDefaultModelOptions,
            'personalDefaultChatModelId' => (int) ($personalSettings?->default_chat_model_id ?? 0),
            'personalDefaultEmbeddingModelId' => (int) ($personalSettings?->default_embedding_model_id ?? 0),
            'contentMaxTokens' => $this->defaultContentMaxTokens(),
            'supportsModelMaxTokens' => $this->supportsModelMaxTokens(),
        ];
        if ($actor->isSuperAdmin()) {
            $viewData += [
                'embeddingModels' => $this->systemSettingsService->modelOptions($actor, 'embedding'),
                'chatModels' => $this->systemSettingsService->modelOptions($actor, 'chat'),
                'defaultEmbeddingModelId' => $this->getDefaultEmbeddingModelId(),
                'chunkingConfig' => $this->getChunkingConfig(),
                'pgvectorEnabled' => $this->isPgvectorEnabled(),
            ];
        }

        return view('admin.ai-models.index', $viewData);
    }

    public function updatePersonalDefaults(UpdatePersonalAiDefaultsRequest $request): RedirectResponse
    {
        $actor = $this->activeActor($request);

        try {
            $this->aiSettingsService->setDefaults(
                $actor,
                $request->modelReference('default_chat_model_id'),
                $request->modelReference('default_embedding_model_id'),
                $actor,
            );
        } catch (AiModelAccessException $exception) {
            return back()->withErrors([
                'personal_defaults' => __('admin.ai_models.error.personal_default_rejected', [
                    'code' => $exception->getErrorCode(),
                ]),
            ]);
        }

        return redirect()
            ->route('admin.ai-models.index')
            ->with('message', __('admin.ai_models.message.personal_defaults_updated'));
    }

    /**
     * AI 模型创建页。
     */
    public function create(Request $request): View
    {
        $actor = $this->activeActor($request);
        Gate::forUser($actor)->authorize('create', AiModel::class);

        return view('admin.ai-models.create', [
            'pageTitle' => __('admin.ai_models.create_page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'contentMaxTokens' => $this->defaultContentMaxTokens(),
            'supportsModelMaxTokens' => $this->supportsModelMaxTokens(),
            'actorIsSuperAdmin' => $actor->isSuperAdmin(),
        ]);
    }

    /**
     * AI 模型编辑页。
     */
    public function edit(Request $request, int $modelId): View
    {
        $columns = [
            'id',
            'name',
            'version',
            'model_id',
            'model_type',
            'api_url',
            'failover_priority',
            'daily_limit',
            'status',
            'access_scope',
        ];
        if ($this->supportsModelMaxTokens()) {
            $columns[] = 'max_tokens';
        }

        $model = $this->configurableModel($request, $modelId, 'update', $columns);

        return view('admin.ai-models.edit', [
            'pageTitle' => __('admin.ai_models.modal_edit'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'model' => $model,
            'contentMaxTokens' => $this->defaultContentMaxTokens(),
            'supportsModelMaxTokens' => $this->supportsModelMaxTokens(),
            'actorIsSuperAdmin' => $this->activeActor($request)->isSuperAdmin(),
        ]);
    }

    /**
     * 创建 AI 模型。
     *
     * 说明：
     * - 创建时 API Key 必填，并在写入前加密；
     * - 首次新增个人 Chat 或 Embedding 模型时，安全补充对应的个人默认模型。
     */
    public function store(StoreAiModelRequest $request): RedirectResponse
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 404);
        $payload = $request->validated();

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey === '') {
            return back()->withErrors(__('admin.ai_models.error.required_fields'));
        }

        try {
            $encryptedApiKey = $this->encryptApiKey($apiKey);
        } catch (\RuntimeException) {
            return back()
                ->withInput(Arr::except($payload, ['api_key']))
                ->withErrors(__('admin.ai_models.error.crypto_key_missing'));
        }

        $modelType = $this->normalizeModelType((string) ($payload['model_type'] ?? 'chat'));
        $createData = [
            'name' => trim((string) $payload['name']),
            'version' => trim((string) ($payload['version'] ?? '')),
            'api_key' => $encryptedApiKey,
            'model_id' => trim((string) $payload['model_id']),
            'model_type' => $modelType,
            'api_url' => trim((string) ($payload['api_url'] ?? '')),
            'failover_priority' => max(1, (int) ($payload['failover_priority'] ?? 100)),
            'daily_limit' => max(0, (int) ($payload['daily_limit'] ?? 0)),
            'status' => 'active',
        ];
        if ($this->supportsModelMaxTokens()) {
            $createData['max_tokens'] = $this->normalizeMaxTokensForModelType($payload['max_tokens'] ?? null, $modelType);
        }

        $createdModel = $this->mutationService->create(
            $actor,
            $createData,
            (string) ($payload['access_scope'] ?? AiModel::ACCESS_SCOPE_USER_CONTENT),
        )->model;

        if ($createdModel->model_type === 'chat') {
            $this->invalidateQualityModelAfterCommit(
                (int) $createdModel->id,
                'AI 质检智能切换候选模型已增加',
            );
        }

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.create_success'));
    }

    /**
     * 更新 AI 模型。
     *
     * 说明：
     * - 编辑时 API Key 可留空（留空表示保留原值）；
     * - 更新后清理已失效或类型不兼容的个人默认模型引用；
     * - 超级管理员更新系统默认 embedding 时继续保持原有兼容清理。
     */
    public function update(UpdateAiModelRequest $request, int $modelId): RedirectResponse
    {
        $actor = $this->activeActor($request);
        $model = $request->aiModel();
        $payload = $request->validated();

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
        if ($this->supportsModelMaxTokens()) {
            $updateData['max_tokens'] = $this->normalizeMaxTokensForModelType($payload['max_tokens'] ?? null, $modelType);
        }

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey !== '') {
            try {
                $updateData['api_key'] = $this->encryptApiKey($apiKey);
            } catch (\RuntimeException) {
                return back()
                    ->withInput(Arr::except($payload, ['api_key']))
                    ->withErrors(__('admin.ai_models.error.crypto_key_missing'));
            }
        }

        $updateResult = $this->mutationService->update(
            $actor,
            (int) $model->getKey(),
            $updateData,
            (string) ($payload['access_scope'] ?? $model->access_scope),
        );
        if (! $updateResult->succeeded()) {
            return back()
                ->withInput(Arr::except($payload, ['api_key']))
                ->withErrors(__('admin.ai_models.error.title_generation_in_use'));
        }

        $model = $updateResult->model;
        $this->invalidateQualityModelAfterCommit(
            (int) $model->getKey(),
            'AI 质检模型配置已更新',
        );

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.update_success'));
    }

    /**
     * 删除 AI 模型（被任务引用时阻止删除）。
     *
     * 删除策略：
     * - 仅当任务引用数为 0 时允许删除；
     * - 删除前清理所有管理员的个人默认引用；
     * - 超级管理员删除系统默认 embedding 时同步清理全局设置。
     */
    public function destroy(Request $request, int $modelId): RedirectResponse
    {
        $actor = $this->activeActor($request);
        $result = $this->mutationService->delete($actor, $modelId);
        if ($result->error === 'title_generation') {
            return back()->withErrors(__('admin.ai_models.error.title_generation_in_use'));
        }
        if ($result->error === 'task') {
            return back()->withErrors(__('admin.ai_models.error.in_use', ['count' => $result->dependencyCount]));
        }

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.delete_success'));
    }

    /**
     * 测试单个 AI 模型的 API 连通性。
     *
     * 只发起最小化请求，并纳入统一每日额度与调用统计，不返回敏感密钥。
     */
    public function testConnection(Request $request, int $modelId): JsonResponse
    {
        $startedAt = microtime(true);
        $reservation = null;
        $outboundAttempted = false;
        $apiKey = '';
        $providerReturned = false;
        $usageSession = null;
        $model = new AiModel;
        $model->setAttribute($model->getKeyName(), $modelId);
        $model->exists = true;

        try {
            $actor = $this->activeActor($request);
            $snapshot = $this->testPreparation->prepare($actor, $modelId);
            $model = $snapshot->modelForWorkspaceProbe();
            $reservation = $snapshot->reservation;
            $usageSession = $this->usageSessions->create($snapshot);
            $modelType = $snapshot->modelType;
            $endpoint = $snapshot->endpoint;
            $apiKey = $snapshot->apiKey();
            $modelName = trim($snapshot->providerModelId);
            $isGemini = $snapshot->gemini;
            $usesOpenAiResponses = $snapshot->usesOpenAiResponses;

            if ($endpoint === '') {
                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_error_api_url_missing'),
                    $startedAt,
                    $modelType,
                    diagnosis: $this->modelTestDiagnosis->forLocalFailure('api_url_missing'),
                );
            }
            if ($apiKey === '') {
                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_error_api_key_missing'),
                    $startedAt,
                    $modelType,
                    $endpoint,
                    diagnosis: $this->modelTestDiagnosis->forLocalFailure('api_key_missing'),
                );
            }
            if ($modelName === '') {
                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_error_model_missing'),
                    $startedAt,
                    $modelType,
                    $endpoint,
                    diagnosis: $this->modelTestDiagnosis->forLocalFailure('model_id_missing'),
                );
            }

            if ($reservation === null) {
                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_error_daily_limit'),
                    $startedAt,
                    $modelType,
                    $endpoint,
                    diagnosis: $this->modelTestDiagnosis->forLocalFailure('daily_limit_reached'),
                );
            }

            $this->testBoundaryHook->beforeRevalidation($snapshot);
            $actorIsSuperAdmin = $this->testPreparation->revalidateImmediatelyBeforeOutbound($snapshot);

            if ($modelType === 'chat' && $actorIsSuperAdmin) {
                $this->safeHttp->resolveTarget($endpoint);
                $probeAttempt = $this->aiWorkspaceModelProbe->start($model, $usageSession);
                $outboundAttempted = $usageSession->hasStartedProviderAttempt();
                $this->testBoundaryHook->afterOutboundBeforePersist($snapshot);
                $this->testPreparation->revalidateWorkspaceAfterOutbound($snapshot);
                $usageSession->finalizePendingOutcomes();
                try {
                    if ($probeAttempt->requiresPlainTextFallback()) {
                        $this->testBoundaryHook->beforeRevalidation($snapshot);
                        $this->testPreparation->revalidateImmediatelyBeforeOutbound($snapshot);
                        $this->safeHttp->resolveTarget($snapshot->endpoint);
                    }
                    $probeResult = $this->aiWorkspaceModelProbe->finish($model, $probeAttempt, $usageSession);
                } catch (Throwable $exception) {
                    if ($probeAttempt->requiresPlainTextFallback()) {
                        $this->testBoundaryHook->afterOutboundBeforePersist($snapshot);
                        $this->testPreparation->revalidateWorkspaceAfterOutbound($snapshot);
                    }
                    $this->testPreparation->persistWorkspaceFailure(
                        $snapshot,
                        $this->aiWorkspaceModelProbe->failureCode($exception),
                    );
                    $usageSession->finalizePendingOutcomes();

                    throw $exception;
                }
                if ($probeAttempt->requiresPlainTextFallback()) {
                    $this->testBoundaryHook->afterOutboundBeforePersist($snapshot);
                }
                $this->testPreparation->persistWorkspaceReadiness($snapshot, $probeResult);
                $usageSession->succeededPending();
                $result = $probeResult->responseData();
                try {
                    $this->usageQuota->recordModelSuccess($reservation);
                } catch (Throwable $exception) {
                    report($exception);
                }
                $reservation = null;

                return $this->modelTestResponse(
                    true,
                    __('admin.ai_models.test_success', ['type' => 'Chat']),
                    $startedAt,
                    $modelType,
                    (string) $result['endpoint'],
                    (int) $result['http_status'],
                    [
                        'workspace_ready' => true,
                        'readiness_status' => (string) $result['readiness_status'],
                        'readiness_profile' => (array) $result['profile'],
                        'readiness_expires_at' => (string) $result['expires_at'],
                    ],
                );
            }

            $request = $this->http->acceptJson()
                ->asJson()
                ->connectTimeout(8)
                ->timeout(45);

            $request = $isGemini
                ? $request->withHeaders(['x-goog-api-key' => $apiKey])
                : $request->withToken($apiKey);

            $testPayload = $this->buildTestPayload($modelName, $modelType, $isGemini, $usesOpenAiResponses);
            $response = $this->safeHttp->post(
                $request,
                $endpoint,
                $testPayload,
                (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024),
                function () use (&$outboundAttempted, $usageSession, $model, $testPayload): void {
                    $usageSession->begin(
                        model: $model,
                        callKey: 'direct.p1',
                        requestPayload: json_encode($testPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    );
                    $outboundAttempted = $usageSession->hasStartedProviderAttempt();
                },
            );
            $providerReturned = true;

            $this->testBoundaryHook->afterOutboundBeforePersist($snapshot);
            $this->testPreparation->revalidateAfterOutbound($snapshot);
            $json = $response->json();
            if (! $response->successful()) {
                $usageSession->failed('direct.p1', 'ai_provider_http_failure', $json['usage'] ?? null);
                if ($reservation instanceof AiUsageReservation) {
                    $this->recordModelTestAttempt($reservation);
                }

                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_failed_with_status', [
                        'status' => (string) $response->status(),
                        'message' => $this->safeRemoteDetail($response, $apiKey),
                    ]),
                    $startedAt,
                    $modelType,
                    $endpoint,
                    $response->status(),
                    diagnosis: $this->modelTestDiagnosis->forHttpFailure($model, $apiKey, $response->status()),
                );
            }

            if (! $this->isValidTestResponse($json, $modelType, $isGemini, $usesOpenAiResponses)) {
                $usageSession->discarded('direct.p1', 'ai_provider_response_invalid', $json['usage'] ?? null);
                if ($reservation instanceof AiUsageReservation) {
                    $this->recordModelTestAttempt($reservation);
                }

                return $this->modelTestResponse(
                    false,
                    __('admin.ai_models.test_invalid_response', [
                        'message' => $this->safeRemoteDetail($response, $apiKey),
                    ]),
                    $startedAt,
                    $modelType,
                    $endpoint,
                    $response->status(),
                    diagnosis: $this->modelTestDiagnosis->forInvalidResponse($model, $apiKey),
                );
            }

            if ($reservation instanceof AiUsageReservation) {
                try {
                    $this->usageQuota->recordModelSuccess($reservation);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            $usageSession->retainUsage('direct.p1', $json['usage'] ?? null);
            $usageSession->succeededPending();

            return $this->modelTestResponse(
                true,
                __('admin.ai_models.test_success', ['type' => $modelType === 'embedding' ? 'Embedding' : 'Chat']),
                $startedAt,
                $modelType,
                $endpoint,
                $response->status()
            );
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $outboundAttempted = $outboundAttempted
                || ($usageSession instanceof GovernanceAiModelUsageSession
                    && $usageSession->hasStartedProviderAttempt());
            if ($usageSession instanceof GovernanceAiModelUsageSession) {
                if ($exception instanceof AiModelAccessException) {
                    $usageSession->revokedPending();
                } elseif ($providerReturned) {
                    $usageSession->discardedPending();
                } else {
                    $usageSession->failed('direct.p1');
                    $usageSession->discardedPending();
                }
            }
            if ($reservation instanceof AiUsageReservation) {
                if ($outboundAttempted) {
                    $this->recordModelTestAttempt($reservation);
                } else {
                    $this->releaseModelTestReservation($reservation);
                }
            }

            Log::warning('AI model connection test failed.', [
                'ai_model_id' => (int) $model->id,
                'exception' => $exception::class,
                'reason_code' => property_exists($exception, 'reasonCode') ? (string) $exception->reasonCode : null,
            ]);

            return $this->modelTestResponse(
                false,
                __('admin.ai_models.test_exception', ['message' => $this->safeExceptionDetail($exception)]),
                $startedAt,
                $this->normalizeModelType((string) ($model->model_type ?? 'chat')),
                diagnosis: $this->modelTestDiagnosis->forException($exception, $model, $apiKey),
            );
        }
    }

    /**
     * 更新默认 embedding 模型设置。
     *
     * 约束：
     * - 只允许选择 active + embedding 的模型；
     * - 允许传 0，表示恢复自动选择策略。
     */
    public function updateDefaultEmbedding(UpdateDefaultEmbeddingRequest $request): RedirectResponse
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 404);
        $this->systemSettingsService->updateDefaultEmbedding($actor, $request->modelId());

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.embedding_default_updated'));
    }

    /**
     * 更新知识库切片策略。
     */
    public function updateChunkingConfig(UpdateChunkingConfigRequest $request): RedirectResponse
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 404);
        $this->systemSettingsService->updateChunking($actor, $request->strategy(), $request->modelId());

        return redirect()->route('admin.ai-models.index')->with('message', __('admin.ai_models.message.chunking_config_updated'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadModels(Builder $query, int $defaultEmbeddingModelId): array
    {
        $supportsMaxTokens = $this->supportsModelMaxTokens();
        $columns = [
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
            'usage_date',
            'total_used',
            'status',
            'created_at',
            'updated_at',
        ];
        if ($supportsMaxTokens) {
            $columns[] = 'max_tokens';
        }
        foreach ([
            'ai_workspace_readiness_status',
            'ai_workspace_readiness_profile',
            'ai_workspace_readiness_checked_at',
            'ai_workspace_readiness_expires_at',
            'ai_workspace_readiness_failure_code',
        ] as $workspaceColumn) {
            if (Schema::hasColumn('ai_models', $workspaceColumn)) {
                $columns[] = $workspaceColumn;
            }
        }

        $models = $query
            ->select($columns)
            ->withCount([
                'tasks as content_task_count' => fn ($query) => $query->withTrashed(),
                'qualityTasks as quality_task_count' => fn ($query) => $query->withTrashed(),
            ])
            ->addSelect([
                'article_count' => Article::query()
                    ->selectRaw('COUNT(articles.id)')
                    ->join('tasks', 'articles.task_id', '=', 'tasks.id')
                    ->whereColumn('tasks.ai_model_id', 'ai_models.id'),
            ])
            ->orderByDesc('created_at')
            ->get();

        return $models->map(function (AiModel $model) use ($defaultEmbeddingModelId, $supportsMaxTokens): array {
            $modelType = $this->normalizeModelType((string) ($model->model_type ?? 'chat'));
            $workspaceReadinessStatus = (string) ($model->ai_workspace_readiness_status ?? '');
            if ($workspaceReadinessStatus === 'ready' && $model->ai_workspace_readiness_expires_at?->isPast()) {
                $workspaceReadinessStatus = 'stale';
            }

            return [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'version' => (string) ($model->version ?? ''),
                'model_id' => $this->modelTestDiagnosis->modelIdForDisplay((string) $model->model_id),
                'model_type' => $modelType,
                'api_url' => (string) ($model->api_url ?? ''),
                'failover_priority' => (int) ($model->failover_priority ?? 100),
                'daily_limit' => (int) ($model->daily_limit ?? 0),
                'used_today' => $model->currentUsage(),
                'total_used' => (int) ($model->total_used ?? 0),
                'status' => (string) ($model->status ?? 'active'),
                'max_tokens' => $supportsMaxTokens && $model->max_tokens !== null ? (int) $model->max_tokens : null,
                'task_count' => (int) ($model->content_task_count ?? 0) + (int) ($model->quality_task_count ?? 0),
                'article_count' => (int) ($model->article_count ?? 0),
                'api_key_configured' => $this->decryptApiKey((string) ($model->getRawOriginal('api_key') ?? '')) !== '',
                'is_default_embedding' => $modelType === 'embedding' && $defaultEmbeddingModelId === (int) $model->id,
                'workspace_readiness_status' => $workspaceReadinessStatus,
                'workspace_readiness_profile' => (array) ($model->ai_workspace_readiness_profile ?? []),
                'workspace_readiness_checked_at' => $model->ai_workspace_readiness_checked_at?->toISOString(),
                'workspace_readiness_expires_at' => $model->ai_workspace_readiness_expires_at?->toISOString(),
                'workspace_readiness_failure_code' => (string) ($model->ai_workspace_readiness_failure_code ?? ''),
            ];
        })->all();
    }

    /**
     * @return array{
     *   shared_models:array<int,array<string,mixed>>,
     *   default_options:array<int,array{id:int,name:string,version:string,model_type:string,source:string}>
     * }
     */
    private function loadOrdinaryVisibleModelPartitions(AdminAiActorContext $context): array
    {
        $actor = $context->actor;
        $ownerIds = [(int) $actor->getKey()];
        if ($context->hasActiveSharedProvider()) {
            $ownerIds[] = (int) $context->sharedProvider?->getKey();
        }
        $models = AiModel::query()
            ->select([
                'id',
                'owner_admin_id',
                'name',
                'version',
                'model_type',
                'status',
                'failover_priority',
                'access_scope',
                'archived_at',
            ])
            ->whereIn('owner_admin_id', $ownerIds)
            ->userContent()
            ->orderByRaw('CASE WHEN owner_admin_id = ? THEN 0 ELSE 1 END', [(int) $actor->getKey()])
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();

        return [
            'shared_models' => $models
                ->where('owner_admin_id', '!=', (int) $actor->getKey())
                ->map(static fn (AiModel $model): array => SharedAiModelData::fromModel($model, true)->toArray())
                ->values()
                ->all(),
            'default_options' => $models
                ->filter(static fn (AiModel $model): bool => (string) $model->status === 'active' && $model->archived_at === null)
                ->map(static fn (AiModel $model): array => [
                    'id' => (int) $model->getKey(),
                    'name' => (string) $model->name,
                    'version' => (string) $model->version,
                    'model_type' => (string) ($model->model_type ?: 'chat'),
                    'source' => (int) $model->owner_admin_id === (int) $actor->getKey() ? 'personal' : 'shared',
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function loadGovernanceModels(Admin $actor): LengthAwarePaginator
    {
        $paginator = $this->accessResolver
            ->governanceQuery($actor)
            ->where('owner_admin_id', '!=', $actor->getKey())
            ->with('owner:id,username,display_name,role,status')
            ->orderBy('owner_admin_id')
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->paginate(50, ['*'], 'governance_page');
        $paginator->setCollection(
            $paginator->getCollection()
                ->map(static fn (AiModel $model): array => GovernanceAiModelData::fromModel($model)->toArray()),
        );

        return $paginator;
    }

    /**
     * @param  array<int, array{id:int,name:string,version:string,model_type:string,source:string}>  $availableModels
     * @return array{mode:string,provider_available:bool,provider_name:string,needs_repair:bool}
     */
    private function accessPreview(AdminAiActorContext $context, array $availableModels): array
    {
        $actor = $context->actor;
        $provider = $context->sharedProvider;
        $providerAvailable = $context->hasActiveSharedProvider();

        return [
            'mode' => $actor->shared_ai_config_owner_id === null ? 'independent' : 'shared',
            'provider_available' => $providerAvailable,
            'provider_name' => (string) ($provider?->display_name ?: $provider?->username),
            'needs_repair' => $availableModels === [],
        ];
    }

    /** @return array<int, array{id:int,name:string,version:string,model_type:string,source:string}> */
    private function personalDefaultModelOptions(Admin $actor): array
    {
        return $this->accessResolver
            ->usableQuery($actor)
            ->select(['id', 'owner_admin_id', 'name', 'version', 'model_type'])
            ->get()
            ->map(static fn (AiModel $model): array => [
                'id' => (int) $model->getKey(),
                'name' => (string) $model->name,
                'version' => (string) $model->version,
                'model_type' => (string) ($model->model_type ?: 'chat'),
                'source' => (int) $model->owner_admin_id === (int) $actor->getKey() ? 'personal' : 'shared',
            ])
            ->all();
    }

    /**
     * 规范化 max_tokens 输入：仅聊天模型保留正整数，其他类型视为留空。
     */
    private function normalizeMaxTokensForModelType(mixed $value, string $modelType): ?int
    {
        if ($this->normalizeModelType($modelType) !== 'chat') {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function defaultContentMaxTokens(): int
    {
        return max(256, (int) config('geoflow.content_max_tokens', 16384));
    }

    private function supportsModelMaxTokens(): bool
    {
        return Schema::hasTable('ai_models') && Schema::hasColumn('ai_models', 'max_tokens');
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
     * @return array{strategy:string,model_id:int}
     */
    private function getChunkingConfig(): array
    {
        $settings = SiteSetting::query()
            ->whereIn('setting_key', ['knowledge_chunk_strategy', 'knowledge_chunking_model_id'])
            ->pluck('setting_value', 'setting_key');
        $strategy = (string) ($settings['knowledge_chunk_strategy'] ?? 'rule');

        return [
            'strategy' => in_array($strategy, ['rule', 'auto', 'semantic_llm'], true) ? $strategy : 'rule',
            'model_id' => max(0, (int) ($settings['knowledge_chunking_model_id'] ?? 0)),
        ];
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
        } catch (Throwable) {
            return false;
        }
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

    /**
     * @return array<string, mixed>
     */
    private function buildTestPayload(
        string $modelName,
        string $modelType,
        bool $isGemini = false,
        bool $usesOpenAiResponses = false
    ): array {
        if ($isGemini) {
            if ($modelType === 'embedding') {
                return [
                    'requests' => [
                        [
                            'model' => 'models/'.$this->normalizeGeminiModelName($modelName),
                            'content' => [
                                'parts' => [
                                    ['text' => $this->formatGeminiRetrievalQuery('GEOFlow embedding connection test')],
                                ],
                            ],
                            'output_dimensionality' => 3072,
                        ],
                    ],
                ];
            }

            $generationConfig = ['maxOutputTokens' => 64];
            if ($this->supportsGeminiSamplingParameters($modelName)) {
                $generationConfig['temperature'] = 0;
            }

            $thinkingLevel = $this->resolveGeminiTestThinkingLevel($modelName);
            if ($thinkingLevel !== null) {
                $generationConfig['thinkingConfig'] = [
                    'thinkingLevel' => $thinkingLevel,
                ];
            }

            return [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => 'Reply with OK.'],
                        ],
                    ],
                ],
                'generationConfig' => $generationConfig,
            ];
        }

        if ($modelType === 'embedding') {
            return [
                'model' => $modelName,
                'input' => 'GEOFlow embedding connection test',
            ];
        }

        if ($usesOpenAiResponses) {
            return [
                'model' => $modelName,
                'input' => 'Reply with OK.',
                'max_output_tokens' => 128,
            ];
        }

        return [
            'model' => $modelName,
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with OK.'],
            ],
            'temperature' => 0,
            'max_tokens' => 8,
        ];
    }

    private function isValidTestResponse(
        mixed $json,
        string $modelType,
        bool $isGemini = false,
        bool $usesOpenAiResponses = false
    ): bool {
        if (! is_array($json)) {
            return false;
        }

        if ($isGemini) {
            if ($modelType === 'embedding') {
                return isset($json['embeddings'][0]['values']) && is_array($json['embeddings'][0]['values']);
            }

            foreach (($json['candidates'] ?? []) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                foreach (($candidate['content']['parts'] ?? []) as $part) {
                    if (is_array($part) && trim((string) ($part['text'] ?? '')) !== '') {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($modelType === 'embedding') {
            return isset($json['data'][0]['embedding']) && is_array($json['data'][0]['embedding']);
        }

        if ($usesOpenAiResponses) {
            if (trim((string) ($json['output_text'] ?? '')) !== '') {
                return true;
            }

            foreach (($json['output'] ?? []) as $output) {
                if (! is_array($output)) {
                    continue;
                }

                foreach (($output['content'] ?? []) as $part) {
                    if (is_array($part) && trim((string) ($part['text'] ?? '')) !== '') {
                        return true;
                    }
                }
            }

            return false;
        }

        return isset($json['choices'][0]['message']['content'])
            || isset($json['choices'][0]['text'])
            || isset($json['choices'][0]['delta']['content']);
    }

    private function normalizeGeminiModelName(string $modelName): string
    {
        $modelName = trim($modelName);

        return preg_replace('#^models/#', '', $modelName) ?: $modelName;
    }

    private function formatGeminiRetrievalQuery(string $query): string
    {
        return 'task: search result | query: '.trim($query);
    }

    private function resolveGeminiTestThinkingLevel(string $modelName): ?string
    {
        $modelName = strtolower($this->normalizeGeminiModelName($modelName));

        if (preg_match('/^gemini-3\.\d+-/', $modelName) === 1) {
            return 'low';
        }

        if (str_starts_with($modelName, 'gemini-3-flash')) {
            return 'minimal';
        }

        if (str_starts_with($modelName, 'gemini-3-')) {
            return 'low';
        }

        return null;
    }

    private function supportsGeminiSamplingParameters(string $modelName): bool
    {
        $modelName = strtolower($this->normalizeGeminiModelName($modelName));
        if (preg_match('/^gemini-(\d+)(?:\.(\d+))?-/', $modelName, $matches) !== 1) {
            return true;
        }

        $major = (int) ($matches[1] ?? 0);
        $minor = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

        return $major < 3 || ($major === 3 && $minor < 5);
    }

    private function modelTestResponse(
        bool $success,
        string $message,
        float $startedAt,
        string $modelType,
        string $endpoint = '',
        ?int $httpStatus = null,
        array $extraMeta = [],
        ?array $diagnosis = null,
    ): JsonResponse {
        $meta = [
            'model_type' => $modelType,
            'http_status' => $httpStatus,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'endpoint' => $success ? $endpoint : '',
        ] + $extraMeta;
        if (! $success && $diagnosis !== null) {
            $meta['diagnosis'] = $diagnosis;
        }

        return response()->json([
            'success' => $success,
            'message' => $message,
            'meta' => $meta,
        ], $success ? 200 : 422);
    }

    private function safeRemoteDetail(Response $response, string $apiKey): string
    {
        $payload = $response->json();
        $message = $this->extractRemoteMessage($payload);
        if ($message !== null) {
            return $this->sanitizeRemoteText($message, $apiKey);
        }

        foreach (preg_split('/\R/u', mb_substr($response->body(), 0, 4000, 'UTF-8')) ?: [] as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $eventPayload = json_decode(trim(substr($line, strlen('data:'))), true);
            $message = $this->extractRemoteMessage($eventPayload);
            if ($message !== null) {
                return $this->sanitizeRemoteText($message, $apiKey);
            }
        }

        $plainBody = trim(strip_tags(mb_substr($response->body(), 0, 2000, 'UTF-8')));
        if ($plainBody !== '') {
            return $this->sanitizeRemoteText($plainBody, $apiKey);
        }

        return 'The upstream service returned no readable error detail.';
    }

    private function extractRemoteMessage(mixed $payload): ?string
    {
        $candidates = is_array($payload) ? [
            data_get($payload, 'error.message'),
            data_get($payload, 'error.detail'),
            data_get($payload, 'error'),
            data_get($payload, 'detail'),
            data_get($payload, 'message'),
            data_get($payload, 'msg'),
        ] : [];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function sanitizeRemoteText(string $text, string $apiKey): string
    {
        $sanitized = str_replace($apiKey, '[redacted]', $text);
        if (strlen($apiKey) >= 8) {
            $prefix = substr($apiKey, 0, 8);
            $suffix = substr($apiKey, -4);
            $sanitized = str_replace($prefix, '[redacted]', $sanitized);
            $sanitized = preg_replace('/(?:\*|•|x){2,}\s*'.preg_quote($suffix, '/').'/iu', '[redacted]', $sanitized) ?? $sanitized;
            $sanitized = str_replace($suffix, '[redacted]', $sanitized);
        }
        $sanitized = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]{8,}/iu', 'Bearer [redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/u', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\bark-[A-Za-z0-9._-]{4,}\b/iu', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\s+/u', ' ', trim($sanitized)) ?? trim($sanitized);

        return mb_substr($sanitized, 0, 500, 'UTF-8');
    }

    private function safeExceptionDetail(Throwable $exception): string
    {
        if ($exception instanceof AiModelAccessException) {
            return $exception->getErrorCode();
        }

        if ($exception instanceof OutboundRequestBlockedException) {
            return match ($exception->reasonCode) {
                'dns_resolution_failed' => 'DNS resolution failed. Check container DNS and retry.',
                'unsafe_address', 'mapped_address' => 'The API address was blocked by the outbound security policy.',
                'response_too_large' => 'The upstream response exceeded the configured safety limit.',
                default => 'The outbound request was blocked ('.$exception->reasonCode.').',
            };
        }

        if ($exception instanceof OutboundRequestFailedException) {
            return 'The network request failed. Check DNS, TLS, and upstream availability.';
        }

        return 'An unexpected backend error occurred. Check the application log.';
    }

    private function recordModelTestAttempt(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelOutboundAttempt($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function releaseModelTestReservation(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->releaseModel($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function invalidateQualityModelAfterCommit(int $modelId, string $reason): void
    {
        try {
            $this->qualityInvalidationService->invalidateModel($modelId, $reason);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('AI quality model invalidation failed after model mutation.', [
                'ai_model_id' => $modelId,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @param list<string>|null $columns */
    private function configurableModel(
        Request $request,
        int $modelId,
        string $ability,
        ?array $columns = null,
    ): AiModel {
        $actor = $this->activeActor($request);
        $query = $this->accessResolver->managementQuery($actor);
        if ($columns !== null) {
            $query->select($columns);
        }

        $model = $query->whereKey($modelId)->firstOrFail();
        Gate::forUser($actor)->authorize($ability, $model);

        return $model;
    }

    private function activeActor(Request $request): Admin
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin && (string) $actor->status === 'active', 404);

        return $actor;
    }
}
