<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiModelAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAiSourceProviderRequest;
use App\Http\Requests\Admin\StoreAiSourceProviderRequest;
use App\Http\Requests\Admin\TestAiSourceProviderRequest;
use App\Http\Requests\Admin\TestAiVisibilityModelBindingRequest;
use App\Http\Requests\Admin\UpdateAiSourceProviderRequest;
use App\Http\Requests\Admin\UpdateAiVisibilityModelBindingsRequest;
use App\Http\Requests\Admin\UpsertAiVisibilityModelApiRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiModelTestBoundaryHook;
use App\Services\Admin\AdminAiModelTestPreparationService;
use App\Services\Admin\AdminAiSourceProviderService;
use App\Services\Admin\AdminAiSystemConfigurationBoundaryHook;
use App\Services\Admin\GovernanceAiModelUsageSession;
use App\Services\Admin\GovernanceAiModelUsageSessionFactory;
use App\Services\AiWorkspace\AiWorkspaceModelCapabilityProbe;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiUsageReservation;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\DoubaoSearchCustomClient;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AiSourceProviderController extends Controller
{
    private const ARK_MODEL_SETTING_KEY = AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY;

    private const DEEPSEEK_MODEL_SETTING_KEY = AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY;

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly DoubaoSearchCustomClient $doubaoSearchCustomClient,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiVisibilityConfigurationResolver $configuration,
        private readonly AiWorkspaceModelCapabilityProbe $aiWorkspaceModelProbe,
        private readonly AdminAiModelTestBoundaryHook $modelTestBoundaryHook,
        private readonly AdminAiModelTestPreparationService $modelTestPreparation,
        private readonly AdminAiSourceProviderService $sourceProviderService,
        private readonly AdminAiSystemConfigurationBoundaryHook $boundaryHook,
        private readonly GovernanceAiModelUsageSessionFactory $usageSessions,
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->sourceProviderService->activeSuperAdmin($this->actor($request));
        $arkModelId = $this->configuredModelIdForActor($actor, self::ARK_MODEL_SETTING_KEY, 'ark');
        $deepSeekModelId = $this->configuredModelIdForActor($actor, self::DEEPSEEK_MODEL_SETTING_KEY, 'deepseek');

        return view('admin.ai-source-providers.index', [
            'pageTitle' => __('admin.ai_source_providers.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'providers' => $this->loadProviders(),
            'arkModels' => $this->sourceProviderService->modelOptions($actor, 'ark'),
            'deepSeekModels' => $this->sourceProviderService->modelOptions($actor, 'deepseek'),
            'arkModelId' => $arkModelId,
            'deepSeekModelId' => $deepSeekModelId,
            'arkApiConfig' => $this->loadModelApiConfig($actor, $arkModelId, 'ark'),
            'deepSeekApiConfig' => $this->loadModelApiConfig($actor, $deepSeekModelId, 'deepseek'),
            'stats' => $this->loadStats(),
            'defaultDoubaoEndpoint' => (string) config('geoflow.ai_visibility.doubao_search_endpoint', ''),
        ]);
    }

    public function create(Request $request): View
    {
        $this->sourceProviderService->activeSuperAdmin($this->actor($request));

        return view('admin.ai-source-providers.create', [
            'pageTitle' => __('admin.ai_source_providers.modal_create'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'provider' => null,
            'defaultDoubaoEndpoint' => (string) config('geoflow.ai_visibility.doubao_search_endpoint', ''),
        ]);
    }

    public function edit(Request $request, int $providerId): View
    {
        $this->sourceProviderService->activeSuperAdmin($this->actor($request));
        $provider = AiSourceProvider::query()
            ->select(['id', 'name', 'provider_key', 'endpoint_url', 'status', 'daily_limit', 'metadata_json'])
            ->whereKey($providerId)
            ->firstOrFail();

        return view('admin.ai-source-providers.edit', [
            'pageTitle' => __('admin.ai_source_providers.modal_edit'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'provider' => $this->providerFormData($provider),
            'defaultDoubaoEndpoint' => (string) config('geoflow.ai_visibility.doubao_search_endpoint', ''),
        ]);
    }

    public function store(StoreAiSourceProviderRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->boundaryHook->beforeMutation($actor);
        $this->sourceProviderService->createProvider($actor, $request->validated());

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.create_success'));
    }

    public function update(UpdateAiSourceProviderRequest $request, int $providerId): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->boundaryHook->beforeMutation($actor);
        $this->sourceProviderService->updateProvider($actor, $providerId, $request->validated());

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.update_success'));
    }

    public function destroy(DeleteAiSourceProviderRequest $request, int $providerId): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->boundaryHook->beforeMutation($actor);
        $this->sourceProviderService->deleteProvider($actor, $providerId);

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.delete_success'));
    }

    public function updateModelBindings(UpdateAiVisibilityModelBindingsRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $arkModelId = max(0, (int) ($payload['ark_model_id'] ?? 0));
        $deepSeekModelId = max(0, (int) ($payload['deepseek_model_id'] ?? 0));
        $actor = $this->actor($request);
        $this->boundaryHook->beforeMutation($actor);
        $this->sourceProviderService->updateModelBindings($actor, $arkModelId, $deepSeekModelId);

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.bindings_updated'));
    }

    public function upsertModelApi(UpsertAiVisibilityModelApiRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->boundaryHook->beforeMutation($actor);
        $this->sourceProviderService->upsertModelApi($actor, $request->validated());

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.api_config_saved'));
    }

    public function testProvider(TestAiSourceProviderRequest $request, int $providerId): JsonResponse
    {
        $payload = $request->validated();
        $query = $this->normalizeTestQuery((string) ($payload['query'] ?? 'GEOFlow'));
        $reservation = null;
        $outboundAttempted = false;
        try {
            $snapshot = $this->sourceProviderService->prepareProviderTest($this->actor($request), $providerId);
            $reservation = $snapshot->reservation;
            if ($reservation === null) {
                return $this->safeTestFailure('ai_model_unavailable');
            }
            $this->boundaryHook->beforeProviderOutbound($snapshot);
            $this->sourceProviderService->revalidateProviderBeforeOutbound($snapshot);
            $outboundAttempted = true;
            $result = $this->doubaoSearchCustomClient->search(
                $snapshot->providerForProbe(),
                $query,
                $snapshot->probeOptions(),
            );
            $this->boundaryHook->afterProviderOutbound($snapshot);
            $this->sourceProviderService->revalidateProviderAfterOutbound($snapshot);
            $this->usageQuota->recordProviderSuccess($reservation);
            $reservation = null;

            return response()->json([
                'success' => true,
                'message' => __('admin.ai_source_providers.test_success', ['count' => count($result->sources)]),
                'meta' => ['source_count' => count($result->sources), 'latency_ms' => $result->latencyMs],
            ]);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($reservation instanceof AiUsageReservation && $outboundAttempted) {
                $this->usageQuota->recordProviderAttempt($reservation);
            } elseif ($reservation instanceof AiUsageReservation) {
                $this->usageQuota->releaseProvider($reservation);
            }

            return $this->safeTestFailure($this->safeErrorCode($exception));
        }
    }

    public function testModelBinding(TestAiVisibilityModelBindingRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $bindingType = (string) $payload['binding_type'];
        $reservation = null;
        $outboundAttempted = false;
        $workspaceFailurePersisted = false;
        $usageSession = null;
        try {
            $snapshot = $this->modelTestPreparation->prepareSystemBinding(
                $this->actor($request),
                (int) $payload['model_id'],
                $bindingType,
            );
            $reservation = $snapshot->reservation;
            $usageSession = $this->usageSessions->create($snapshot);
            if ($reservation === null) {
                return $this->safeTestFailure('ai_model_unavailable');
            }
            $model = $snapshot->modelForWorkspaceProbe();
            $this->modelTestBoundaryHook->beforeRevalidation($snapshot);
            $this->modelTestPreparation->revalidateImmediatelyBeforeOutbound($snapshot);
            $this->safeHttp->resolveTarget($snapshot->endpoint);
            $probeAttempt = $this->aiWorkspaceModelProbe->start($model, $usageSession);
            $outboundAttempted = $usageSession->hasStartedProviderAttempt();
            $this->modelTestBoundaryHook->afterOutboundBeforePersist($snapshot);
            $this->modelTestPreparation->revalidateWorkspaceAfterOutbound($snapshot);
            $usageSession->finalizePendingOutcomes();
            if ($probeAttempt->requiresPlainTextFallback()) {
                $this->modelTestBoundaryHook->beforeRevalidation($snapshot);
                $this->modelTestPreparation->revalidateImmediatelyBeforeOutbound($snapshot);
                $this->safeHttp->resolveTarget($snapshot->endpoint);
            }
            try {
                $probeResult = $this->aiWorkspaceModelProbe->finish($model, $probeAttempt, $usageSession);
            } catch (Throwable $exception) {
                if ($probeAttempt->requiresPlainTextFallback()) {
                    $this->modelTestBoundaryHook->afterOutboundBeforePersist($snapshot);
                    $this->modelTestPreparation->revalidateWorkspaceAfterOutbound($snapshot);
                }
                $this->modelTestPreparation->persistWorkspaceFailure(
                    $snapshot,
                    $this->aiWorkspaceModelProbe->failureCode($exception),
                );
                $workspaceFailurePersisted = true;
                $usageSession->finalizePendingOutcomes();

                throw $exception;
            }
            if ($probeAttempt->requiresPlainTextFallback()) {
                $this->modelTestBoundaryHook->afterOutboundBeforePersist($snapshot);
            }
            $this->modelTestPreparation->persistWorkspaceReadiness($snapshot, $probeResult);
            $usageSession->succeededPending();
            $this->usageQuota->recordModelSuccess($reservation);
            $reservation = null;

            return response()->json([
                'success' => true,
                'message' => __('admin.ai_source_providers.model_test_success', [
                    'provider' => $bindingType === 'ark' ? 'Ark Web Search' : 'DeepSeek',
                ]),
                'meta' => [
                    'workspace_readiness' => $probeResult->profile,
                    'workspace_readiness_expires_at' => $probeResult->expiresAt->toISOString(),
                ],
            ]);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $outboundAttempted = $outboundAttempted
                || ($usageSession instanceof GovernanceAiModelUsageSession
                    && $usageSession->hasStartedProviderAttempt());
            if ($usageSession instanceof GovernanceAiModelUsageSession) {
                if ($exception instanceof AiModelAccessException) {
                    $usageSession->revokedPending();
                } else {
                    $usageSession->discardedPending();
                }
            }
            if ($outboundAttempted
                && ! $workspaceFailurePersisted
                && ! $exception instanceof AiModelAccessException
                && isset($snapshot)
            ) {
                try {
                    $this->modelTestPreparation->persistWorkspaceFailure(
                        $snapshot,
                        $this->aiWorkspaceModelProbe->failureCode($exception),
                    );
                } catch (AiModelAccessException $accessException) {
                    $exception = $accessException;
                } catch (Throwable $persistenceException) {
                    report($persistenceException);
                }
            }
            if ($reservation instanceof AiUsageReservation && $outboundAttempted) {
                $this->usageQuota->recordModelAttempt($reservation);
            } elseif ($reservation instanceof AiUsageReservation) {
                $this->usageQuota->releaseModel($reservation);
            }

            return $this->safeTestFailure($this->safeErrorCode($exception));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProviders(): array
    {
        if (! Schema::hasTable('ai_source_providers')) {
            return [];
        }

        $query = AiSourceProvider::query();
        if (Schema::hasTable('ai_visibility_runs')) {
            $query->withCount('visibilityRuns');
        }

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function (AiSourceProvider $provider): array {
                $metadata = is_array($provider->metadata_json) ? $provider->metadata_json : [];

                return [
                    'id' => (int) $provider->id,
                    'name' => (string) $provider->name,
                    'provider_key' => (string) $provider->provider_key,
                    'provider_label' => $this->providerLabel((string) $provider->provider_key),
                    'endpoint_url' => (string) ($provider->endpoint_url ?? ''),
                    'masked_api_key' => $this->apiKeyCrypto->mask((string) ($provider->getRawOriginal('api_key') ?? '')),
                    'status' => (string) ($provider->status ?? 'active'),
                    'daily_limit' => (int) ($provider->daily_limit ?? 0),
                    'used_today' => $provider->usage_date?->toDateString() === now()->toDateString()
                        ? (int) ($provider->used_today ?? 0)
                        : 0,
                    'total_used' => (int) ($provider->total_used ?? 0),
                    'visibility_runs_count' => (int) ($provider->visibility_runs_count ?? 0),
                    'metadata' => $metadata,
                    'sites_text' => $this->joinList($metadata['sites'] ?? []),
                    'block_hosts_text' => $this->joinList($metadata['block_hosts'] ?? []),
                    'created_at' => $provider->created_at?->toDateTimeString(),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerFormData(AiSourceProvider $provider): array
    {
        $metadata = is_array($provider->metadata_json) ? $provider->metadata_json : [];

        return [
            'id' => (int) $provider->id,
            'name' => (string) $provider->name,
            'endpoint_url' => (string) ($provider->endpoint_url ?? ''),
            'status' => (string) ($provider->status ?? 'active'),
            'daily_limit' => (int) ($provider->daily_limit ?? 0),
            'count' => (int) ($metadata['count'] ?? 10),
            'content_formats' => (string) ($metadata['content_formats'] ?? 'Markdown'),
            'need_summary' => (bool) ($metadata['need_summary'] ?? true),
            'need_content' => (bool) ($metadata['need_content'] ?? true),
            'need_url' => (bool) ($metadata['need_url'] ?? true),
            'auth_info_level' => (string) ($metadata['auth_info_level'] ?? ''),
            'sites' => $this->joinList($metadata['sites'] ?? []),
            'block_hosts' => $this->joinList($metadata['block_hosts'] ?? []),
        ];
    }

    /**
     * @return array{id:int,name:string,model_id:string,api_url:string,daily_limit:int,max_tokens:?int,masked_api_key:string}
     */
    private function loadModelApiConfig(Admin $actor, int $modelId, string $bindingType): array
    {
        $defaults = $this->defaultModelApiConfig($bindingType);
        if ($modelId <= 0 || ! Schema::hasTable('ai_models')) {
            return $defaults;
        }

        $model = AiModel::query()
            ->ownedBy($actor)
            ->systemOnly()
            ->active()
            ->unarchived()
            ->whereKey($modelId)
            ->first();
        if (! $model instanceof AiModel) {
            return $defaults;
        }

        return array_replace($defaults, [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'model_id' => (string) ($model->model_id ?? ''),
            'api_url' => (string) ($model->api_url ?? ''),
            'daily_limit' => (int) ($model->daily_limit ?? 0),
            'max_tokens' => $this->supportsModelMaxTokens() && $model->max_tokens !== null ? (int) $model->max_tokens : null,
            'masked_api_key' => $this->apiKeyCrypto->mask((string) ($model->getRawOriginal('api_key') ?? '')),
        ]);
    }

    /**
     * @return array{id:int,name:string,model_id:string,api_url:string,daily_limit:int,max_tokens:?int,masked_api_key:string}
     */
    private function defaultModelApiConfig(string $bindingType): array
    {
        if ($bindingType === 'ark') {
            return [
                'id' => 0,
                'name' => '豆包 Ark Web Search',
                'model_id' => 'doubao-seed-2-0-lite-260428',
                'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'daily_limit' => 0,
                'max_tokens' => null,
                'masked_api_key' => '-',
            ];
        }

        return [
            'id' => 0,
            'name' => 'DeepSeek 二次分析',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
            'daily_limit' => 0,
            'max_tokens' => (int) config('geoflow.ai_visibility.default_analysis_max_tokens', 4096),
            'masked_api_key' => '-',
        ];
    }

    /**
     * @return array{provider_count:int,active_provider_count:int,provider_today_usage:int,failed_runs:int}
     */
    private function loadStats(): array
    {
        $hasProviderTable = Schema::hasTable('ai_source_providers');
        $hasProviderUsageDate = $hasProviderTable
            && Schema::hasColumn('ai_source_providers', 'usage_date');
        $providerQuery = $hasProviderTable ? AiSourceProvider::query() : null;
        $runQuery = Schema::hasTable('ai_visibility_runs') ? AiVisibilityRun::query()->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS) : null;

        return [
            'provider_count' => $providerQuery ? (clone $providerQuery)->count() : 0,
            'active_provider_count' => $providerQuery ? (clone $providerQuery)->where('status', 'active')->count() : 0,
            'provider_today_usage' => $providerQuery && $hasProviderUsageDate
                ? (int) ((clone $providerQuery)->whereDate('usage_date', now()->toDateString())->sum('used_today') ?? 0)
                : 0,
            'failed_runs' => $runQuery ? (clone $runQuery)->where('status', AiVisibilityRun::STATUS_FAILED)->count() : 0,
        ];
    }

    private function getConfiguredModelId(string $settingKey): int
    {
        return (int) (SiteSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value') ?? 0);
    }

    private function configuredModelIdForActor(
        Admin $actor,
        string $settingKey,
        string $bindingType,
    ): int {
        $modelId = $this->getConfiguredModelId($settingKey);

        return $modelId > 0 && $this->configuration->isCallableAdminOwnedModelId($modelId, $bindingType, $actor)
            ? $modelId
            : 0;
    }

    private function supportsModelMaxTokens(): bool
    {
        return Schema::hasTable('ai_models') && Schema::hasColumn('ai_models', 'max_tokens');
    }

    private function normalizeTestQuery(string $query): string
    {
        $query = trim($query);

        return $query !== '' ? mb_substr($query, 0, 200, 'UTF-8') : 'GEOFlow';
    }

    private function joinList(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        return implode("\n", array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function providerLabel(string $providerKey): string
    {
        return match ($providerKey) {
            AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM => __('admin.ai_source_providers.provider.doubao_search_custom'),
            default => $providerKey,
        };
    }

    private function actor(Request $request): Admin
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }

    private function safeErrorCode(Throwable $exception): string
    {
        if ($exception instanceof AiModelAccessException) {
            return $exception->getErrorCode();
        }
        if ($exception instanceof AuthorizationException) {
            return 'ai_system_config_super_admin_only';
        }
        if ($exception instanceof ValidationException) {
            return 'ai_model_unavailable';
        }

        return 'ai_model_unavailable';
    }

    private function safeTestFailure(string $errorCode): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $errorCode,
            'message' => __('admin.ai_source_providers.test_failed', ['message' => $errorCode]),
        ], 422);
    }
}
