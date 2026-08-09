<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiVisibility\AiProviderEndpointPolicy;
use App\Services\GeoFlow\AiVisibility\AiStructuredOutputHealthCheck;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityResult;
use App\Services\GeoFlow\AiVisibility\DoubaoSearchCustomClient;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class AiSourceProviderController extends Controller
{
    private const ARK_MODEL_SETTING_KEY = AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY;

    private const DEEPSEEK_MODEL_SETTING_KEY = AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY;

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly DoubaoSearchCustomClient $doubaoSearchCustomClient,
        private readonly AiStructuredOutputHealthCheck $structuredOutputHealthCheck,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiVisibilityConfigurationResolver $configuration,
    ) {}

    public function index(): View
    {
        $arkModelId = $this->getConfiguredModelId(self::ARK_MODEL_SETTING_KEY);
        $deepSeekModelId = $this->getConfiguredModelId(self::DEEPSEEK_MODEL_SETTING_KEY);

        return view('admin.ai-source-providers.index', [
            'pageTitle' => __('admin.ai_source_providers.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'providers' => $this->loadProviders(),
            'chatModels' => $this->loadActiveChatModels(),
            'arkModelId' => $arkModelId,
            'deepSeekModelId' => $deepSeekModelId,
            'arkApiConfig' => $this->loadModelApiConfig($arkModelId, 'ark'),
            'deepSeekApiConfig' => $this->loadModelApiConfig($deepSeekModelId, 'deepseek'),
            'stats' => $this->loadStats(),
            'defaultDoubaoEndpoint' => (string) config('geoflow.ai_visibility.doubao_search_endpoint', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateProviderPayload($request, false);
        $providerData = $this->providerAttributes($payload, $request);
        if (! $this->endpointPolicy->acceptsSearchApi((string) $providerData['endpoint_url'])) {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.unsupported_provider'));
        }

        try {
            $encryptedApiKey = $this->apiKeyCrypto->encrypt(trim((string) $payload['api_key']));
        } catch (RuntimeException) {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.crypto_key_missing'));
        }

        AiSourceProvider::query()->create(array_merge(
            $providerData,
            [
                'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                'api_key' => $encryptedApiKey,
                'status' => 'active',
            ],
        ));

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.create_success'));
    }

    public function update(Request $request, int $providerId): RedirectResponse
    {
        $provider = AiSourceProvider::query()->whereKey($providerId)->firstOrFail();
        $payload = $this->validateProviderPayload($request, true);
        $updateData = $this->providerAttributes($payload, $request);
        $updateData['status'] = $this->normalizeStatus((string) ($payload['status'] ?? 'active'));
        if (! $this->endpointPolicy->acceptsSearchApi((string) $updateData['endpoint_url'])) {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.unsupported_provider'));
        }

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if (! $this->endpointPolicy->sameOrigin((string) $provider->endpoint_url, (string) $updateData['endpoint_url'])
            && $apiKey === '') {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.api_key_required'));
        }
        if ($apiKey !== '') {
            try {
                $updateData['api_key'] = $this->apiKeyCrypto->encrypt($apiKey);
            } catch (RuntimeException) {
                return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.crypto_key_missing'));
            }
        }

        $provider->update($updateData);

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.update_success'));
    }

    public function destroy(int $providerId): RedirectResponse
    {
        $provider = AiSourceProvider::query()->whereKey($providerId)->firstOrFail();

        if (Schema::hasTable('ai_visibility_runs') && $provider->visibilityRuns()->exists()) {
            return back()->withErrors(__('admin.ai_source_providers.error.provider_in_use'));
        }

        $provider->delete();

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.delete_success'));
    }

    public function updateModelBindings(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'ark_model_id' => ['nullable', 'integer', 'min:0'],
            'deepseek_model_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $arkModelId = max(0, (int) ($payload['ark_model_id'] ?? 0));
        $deepSeekModelId = max(0, (int) ($payload['deepseek_model_id'] ?? 0));

        if ($arkModelId > 0 && ! $this->isCallableArkModelId($arkModelId)) {
            return back()->withErrors(__('admin.ai_source_providers.error.ark_model_unavailable'));
        }

        if ($deepSeekModelId > 0 && ! $this->isCallableDeepSeekModelId($deepSeekModelId)) {
            return back()->withErrors(__('admin.ai_source_providers.error.deepseek_model_unavailable'));
        }

        $this->setConfiguredModelId(self::ARK_MODEL_SETTING_KEY, $arkModelId);
        $this->setConfiguredModelId(self::DEEPSEEK_MODEL_SETTING_KEY, $deepSeekModelId);

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.bindings_updated'));
    }

    public function upsertModelApi(Request $request): RedirectResponse
    {
        $payload = $this->validateModelApiPayload($request);
        $bindingType = (string) $payload['binding_type'];
        $model = $this->configuredModelForBinding($bindingType);
        $apiKey = trim((string) ($payload['api_key'] ?? ''));

        if ($model === null && $apiKey === '') {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.api_key_required'));
        }
        if ($model instanceof AiModel
            && $apiKey === ''
            && trim((string) ($model->getRawOriginal('api_key') ?? '')) === '') {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.api_key_required'));
        }

        if (! $this->isCallableModelApiPayload($bindingType, $payload)) {
            $errorKey = $bindingType === 'ark' ? 'ark_model_unavailable' : 'deepseek_model_unavailable';

            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.'.$errorKey));
        }
        if ($model instanceof AiModel
            && ! $this->endpointPolicy->sameOrigin((string) ($model->api_url ?? ''), (string) $payload['api_url'])
            && $apiKey === '') {
            return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.api_key_required'));
        }

        $modelData = [
            'name' => trim((string) $payload['name']),
            'version' => $bindingType === 'ark' ? 'Ark Responses API' : 'DeepSeek API',
            'model_id' => trim((string) $payload['model_id']),
            'model_type' => 'chat',
            'api_url' => trim((string) $payload['api_url']),
            'failover_priority' => $bindingType === 'ark' ? 40 : 45,
            'daily_limit' => max(0, (int) ($payload['daily_limit'] ?? 0)),
            'status' => 'active',
        ];
        if ($this->supportsModelMaxTokens()) {
            $modelData['max_tokens'] = $this->normalizeModelMaxTokens($payload['max_tokens'] ?? null);
        }

        if ($apiKey !== '') {
            try {
                $modelData['api_key'] = $this->apiKeyCrypto->encrypt($apiKey);
            } catch (RuntimeException) {
                return back()->withInput($request->except('api_key'))->withErrors(__('admin.ai_source_providers.error.crypto_key_missing'));
            }
        }

        $model = $model instanceof AiModel
            ? tap($model)->update($modelData)
            : AiModel::query()->create($modelData);

        $this->setConfiguredModelId($this->modelSettingKey($bindingType), (int) $model->id);

        return redirect()
            ->route('admin.ai-source-providers.index')
            ->with('message', __('admin.ai_source_providers.message.api_config_saved'));
    }

    public function testProvider(Request $request, int $providerId): JsonResponse
    {
        $payload = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
        ]);

        $provider = AiSourceProvider::query()->whereKey($providerId)->firstOrFail();
        $query = $this->normalizeTestQuery((string) ($payload['query'] ?? 'GEOFlow'));

        $reservation = null;
        try {
            $this->assertProviderReady($provider);
            $reservation = $this->usageQuota->reserveProvider($provider);
            if ($reservation === null) {
                throw new RuntimeException('搜索源已达到每日调用上限');
            }
            $result = $this->doubaoSearchCustomClient->search($provider, $query, $this->providerOptions($provider));
            $this->usageQuota->recordProviderSuccess($reservation);
            $reservation = null;

            return $this->testResultResponse($result, __('admin.ai_source_providers.test_success', [
                'count' => count($result->sources),
            ]));
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->usageQuota->recordProviderAttempt($reservation);
            }

            return response()->json([
                'success' => false,
                'message' => __('admin.ai_source_providers.test_failed', [
                    'message' => $this->previewMessage($exception->getMessage()),
                ]),
            ], 422);
        }
    }

    public function testModelBinding(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'binding_type' => ['required', 'in:ark,deepseek'],
            'model_id' => ['required', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:200'],
        ]);

        $bindingType = (string) $payload['binding_type'];
        $model = AiModel::query()->whereKey((int) $payload['model_id'])->firstOrFail();
        $query = $this->normalizeTestQuery((string) ($payload['query'] ?? 'GEOFlow'));

        $reservation = null;
        try {
            if ($bindingType === 'ark' && ! $this->isCallableArkModel($model)) {
                throw new RuntimeException(__('admin.ai_source_providers.error.ark_model_unavailable'));
            }
            if ($bindingType === 'deepseek' && ! $this->isCallableDeepSeekModel($model)) {
                throw new RuntimeException(__('admin.ai_source_providers.error.deepseek_model_unavailable'));
            }
            $reservation = $this->usageQuota->reserveModel($model);
            if ($reservation === null) {
                throw new RuntimeException('模型已达到每日调用上限');
            }

            $result = $bindingType === 'ark'
                ? $this->structuredOutputHealthCheck->testArkResponsesStructuredOutput($model, $query)
                : $this->structuredOutputHealthCheck->testDeepSeekJsonOutput($model, $query);
            $this->usageQuota->recordModelSuccess($reservation);
            $reservation = null;

            return $this->structuredTestResponse($result, __('admin.ai_source_providers.model_test_success', [
                'provider' => $bindingType === 'ark' ? 'Ark Web Search' : 'DeepSeek',
            ]));
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->usageQuota->recordModelAttempt($reservation);
            }

            return response()->json([
                'success' => false,
                'message' => __('admin.ai_source_providers.test_failed', [
                    'message' => $this->previewMessage($exception->getMessage()),
                ]),
            ], 422);
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
     * @return array{id:int,name:string,model_id:string,api_url:string,daily_limit:int,max_tokens:?int,masked_api_key:string}
     */
    private function loadModelApiConfig(int $modelId, string $bindingType): array
    {
        $defaults = $this->defaultModelApiConfig($bindingType);
        if ($modelId <= 0 || ! Schema::hasTable('ai_models')) {
            return $defaults;
        }

        $model = AiModel::query()->whereKey($modelId)->first();
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
     * @return array<int, array{id:int,name:string,model_id:string,api_url:string,provider_hint:string}>
     */
    private function loadActiveChatModels(): array
    {
        if (! Schema::hasTable('ai_models')) {
            return [];
        }

        return AiModel::query()
            ->select(['id', 'name', 'model_id', 'api_url'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('name')
            ->get()
            ->map(fn (AiModel $model): array => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'model_id' => (string) ($model->model_id ?? ''),
                'api_url' => (string) ($model->api_url ?? ''),
                'provider_hint' => $this->modelProviderHint($model),
            ])
            ->all();
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
        $runQuery = Schema::hasTable('ai_visibility_runs') ? AiVisibilityRun::query() : null;

        return [
            'provider_count' => $providerQuery ? (clone $providerQuery)->count() : 0,
            'active_provider_count' => $providerQuery ? (clone $providerQuery)->where('status', 'active')->count() : 0,
            'provider_today_usage' => $providerQuery && $hasProviderUsageDate
                ? (int) ((clone $providerQuery)->whereDate('usage_date', now()->toDateString())->sum('used_today') ?? 0)
                : 0,
            'failed_runs' => $runQuery ? (clone $runQuery)->where('status', AiVisibilityRun::STATUS_FAILED)->count() : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProviderPayload(Request $request, bool $isUpdate): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'endpoint_url' => ['nullable', 'url', 'max:500'],
            'api_key' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:2000'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'search_type' => ['nullable', 'in:web'],
            'content_formats' => ['nullable', 'in:Markdown,Text'],
            'need_summary' => ['nullable', 'boolean'],
            'need_content' => ['nullable', 'boolean'],
            'need_url' => ['nullable', 'boolean'],
            'auth_info_level' => ['nullable', 'string', 'max:80'],
            'sites' => ['nullable', 'string', 'max:1000'],
            'block_hosts' => ['nullable', 'string', 'max:1000'],
        ];

        if ($isUpdate) {
            $rules['status'] = ['nullable', 'in:active,inactive'];
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateModelApiPayload(Request $request): array
    {
        return $request->validate([
            'binding_type' => ['required', 'in:ark,deepseek'],
            'name' => ['required', 'string', 'max:100'],
            'model_id' => ['required', 'string', 'max:100'],
            'api_url' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function providerAttributes(array $payload, Request $request): array
    {
        return [
            'name' => trim((string) $payload['name']),
            'endpoint_url' => $this->providerEndpoint((string) ($payload['endpoint_url'] ?? '')),
            'daily_limit' => max(0, (int) ($payload['daily_limit'] ?? 0)),
            'metadata_json' => [
                'count' => max(1, min(20, (int) ($payload['count'] ?? config('geoflow.ai_visibility.default_search_count', 10)))),
                'search_type' => (string) ($payload['search_type'] ?? 'web'),
                'need_summary' => $request->boolean('need_summary'),
                'need_content' => $request->boolean('need_content'),
                'need_url' => $request->boolean('need_url', true),
                'content_formats' => (string) ($payload['content_formats'] ?? 'Markdown'),
                'auth_info_level' => trim((string) ($payload['auth_info_level'] ?? '')),
                'sites' => $this->parseList((string) ($payload['sites'] ?? '')),
                'block_hosts' => $this->parseList((string) ($payload['block_hosts'] ?? '')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerOptions(AiSourceProvider $provider): array
    {
        return $provider->visibilitySearchOptions();
    }

    private function providerEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint !== '') {
            return $endpoint;
        }

        return (string) config('geoflow.ai_visibility.doubao_search_endpoint', 'https://open.feedcoopapi.com/search_api/web_search');
    }

    private function assertProviderReady(AiSourceProvider $provider): void
    {
        if ((string) ($provider->provider_key ?? '') !== AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM) {
            throw new RuntimeException(__('admin.ai_source_providers.error.unsupported_provider'));
        }

        if ((string) ($provider->status ?? 'inactive') !== 'active') {
            throw new RuntimeException(__('admin.ai_source_providers.error.provider_inactive'));
        }
        if (! $this->endpointPolicy->acceptsSearchApi((string) ($provider->endpoint_url ?? ''))) {
            throw new RuntimeException(__('admin.ai_source_providers.error.unsupported_provider'));
        }
    }

    private function testResultResponse(AiVisibilityResult $result, string $message): JsonResponse
    {
        $sources = array_map(static fn ($source): array => [
            'title' => $source->title,
            'url' => $source->url,
            'domain' => $source->domain,
        ], array_slice($result->sources, 0, 3));

        return response()->json([
            'success' => true,
            'message' => $message,
            'meta' => [
                'provider_type' => $result->providerType,
                'provider_key' => $result->providerKey,
                'model_id' => $result->modelId,
                'source_count' => count($result->sources),
                'latency_ms' => $result->latencyMs,
                'answer_preview' => mb_substr($result->answerText, 0, 240, 'UTF-8'),
                'sources' => $sources,
                'structured_output' => [
                    'source_count' => count($result->sources),
                    'sources' => $sources,
                ],
            ],
        ]);
    }

    /**
     * @param  array{provider:string,endpoint:string,http_status:int,latency_ms:int,structured_output:array<string,mixed>,raw_preview:string}  $result
     */
    private function structuredTestResponse(array $result, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'meta' => [
                'provider_type' => 'model_structured_output',
                'provider_key' => $result['provider'],
                'http_status' => $result['http_status'],
                'latency_ms' => $result['latency_ms'],
                'endpoint' => $result['endpoint'],
                'structured_output' => $result['structured_output'],
                'answer_preview' => $result['raw_preview'],
            ],
        ]);
    }

    private function isCallableArkModelId(int $modelId): bool
    {
        return $this->configuration->isCallableModelId($modelId, 'ark');
    }

    private function isCallableDeepSeekModelId(int $modelId): bool
    {
        return $this->configuration->isCallableModelId($modelId, 'deepseek');
    }

    private function isCallableArkModel(AiModel $model): bool
    {
        return $this->configuration->isCallableModel($model, 'ark');
    }

    private function isCallableDeepSeekModel(AiModel $model): bool
    {
        return $this->configuration->isCallableModel($model, 'deepseek');
    }

    private function modelProviderHint(AiModel $model): string
    {
        if ($this->isCallableArkModel($model)) {
            return 'ark';
        }

        if ($this->isCallableDeepSeekModel($model)) {
            return 'deepseek';
        }

        return 'chat';
    }

    private function getConfiguredModelId(string $settingKey): int
    {
        return (int) (SiteSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value') ?? 0);
    }

    private function configuredModelForBinding(string $bindingType): ?AiModel
    {
        $modelId = $this->getConfiguredModelId($this->modelSettingKey($bindingType));
        if ($modelId <= 0) {
            return null;
        }

        $model = AiModel::query()->whereKey($modelId)->first();

        return $model instanceof AiModel ? $model : null;
    }

    private function setConfiguredModelId(string $settingKey, int $modelId): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $settingKey],
            ['setting_value' => (string) max(0, $modelId)],
        );
    }

    private function modelSettingKey(string $bindingType): string
    {
        return $bindingType === 'ark'
            ? self::ARK_MODEL_SETTING_KEY
            : self::DEEPSEEK_MODEL_SETTING_KEY;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isCallableModelApiPayload(string $bindingType, array $payload): bool
    {
        return $this->endpointPolicy->acceptsModelApi(
            $bindingType,
            (string) ($payload['api_url'] ?? ''),
        );
    }

    private function supportsModelMaxTokens(): bool
    {
        return Schema::hasTable('ai_models') && Schema::hasColumn('ai_models', 'max_tokens');
    }

    private function normalizeModelMaxTokens(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['active', 'inactive'], true) ? $status : 'active';
    }

    private function normalizeTestQuery(string $query): string
    {
        $query = trim($query);

        return $query !== '' ? mb_substr($query, 0, 200, 'UTF-8') : 'GEOFlow';
    }

    /**
     * @return list<string>
     */
    private function parseList(string $value): array
    {
        $items = preg_split('/[\r\n,]+/u', $value) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), $items),
            static fn (string $item): bool => $item !== '',
        ));
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

    private function previewMessage(string $message): string
    {
        return mb_substr(trim($message), 0, 300, 'UTF-8');
    }
}
