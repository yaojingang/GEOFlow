<?php

namespace App\Services\AiWorkspace;

use App\Ai\Agents\GeoHubAgent;
use App\Ai\Agents\GeoHubPlanDrafterAgent;
use App\Ai\Agents\IntentResolverAgent;
use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiPayloadDigest;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceModelRuntime
{
    public function __construct(
        private ApiKeyCrypto $apiKeyCrypto,
        private AiUsageQuotaService $usageQuota,
        private AiCapabilityRegistry $registry,
    ) {}

    /** @return array<string,mixed> */
    public function resolveIntent(string $prompt, ?int $adminId = null): array
    {
        $catalog = $this->registry->all()
            ->map(static fn ($capability): array => [
                'key' => $capability->key,
                'description' => $capability->description,
                'input_schema' => $capability->inputSchema,
            ])->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->prompt(new IntentResolverAgent($catalog), $prompt, 'ai_workspace_intent', $adminId)->toArray();
    }

    /** @param array<string,mixed> $resolution @return list<array<string,mixed>> */
    public function draftPlan(string $prompt, array $resolution, ?int $adminId = null): array
    {
        $workflowSteps = collect((array) ($resolution['workflow_steps'] ?? []));
        $allowedCapabilities = collect((array) ($resolution['candidate_capabilities'] ?? []))
            ->pluck('key')
            ->merge($workflowSteps->pluck('capability'))
            ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values();
        $requiredOperations = $workflowSteps
            ->filter(static fn (mixed $step): bool => is_array($step)
                && is_string($step['operation_id'] ?? null)
                && trim((string) $step['operation_id']) !== ''
                && is_string($step['capability'] ?? null)
                && trim((string) $step['capability']) !== '')
            ->map(static fn (array $step): array => [
                'operation_id' => trim((string) $step['operation_id']),
                'capability' => trim((string) $step['capability']),
                'parameters' => (array) ($step['parameters'] ?? []),
            ])
            ->values();
        if ($allowedCapabilities->isEmpty()) {
            throw new RuntimeException('意图结果没有可用于计划草案的能力。');
        }
        if ($requiredOperations->isEmpty()
            || $requiredOperations->count() !== $workflowSteps->count()
            || $requiredOperations->pluck('operation_id')->unique()->count() !== $requiredOperations->count()) {
            throw new RuntimeException('意图结果缺少唯一的操作标识。');
        }
        $catalog = $this->registry->all()
            ->filter(static fn ($capability): bool => $capability->isExecutable()
                && $capability->maturity !== 'restricted'
                && $allowedCapabilities->contains($capability->key))
            ->map(static fn ($capability): array => [
                'key' => $capability->key,
                'description' => $capability->description,
                'input_schema' => $capability->inputSchema,
            ])->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = json_encode($resolution, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $response = $this->prompt(new GeoHubPlanDrafterAgent($catalog, $context), $prompt, 'ai_workspace_plan', $adminId);

        $steps = collect((array) ($response->toArray()['steps'] ?? []))
            ->filter(static fn (mixed $step): bool => is_array($step)
                && is_string($step['operation_id'] ?? null)
                && trim((string) $step['operation_id']) !== ''
                && isset($step['capability'])
                && is_array($step['parameters'] ?? null))
            ->map(static function (array $step): array {
                $bindings = collect((array) ($step['input_bindings'] ?? []))
                    ->filter(static fn (mixed $binding): bool => is_array($binding) && isset($binding['parameter'], $binding['step'], $binding['path']))
                    ->mapWithKeys(static fn (array $binding): array => [
                        (string) $binding['parameter'] => [
                            'step' => (int) $binding['step'],
                            'path' => (string) $binding['path'],
                        ],
                    ])->all();

                $normalized = [
                    'operation_id' => trim((string) $step['operation_id']),
                    'capability' => (string) $step['capability'],
                    'parameters' => (array) $step['parameters'],
                    'input_bindings' => $bindings,
                ];
                if (array_key_exists('depends_on', $step)) {
                    $normalized['depends_on'] = array_values(array_map('intval', (array) $step['depends_on']));
                }

                return $normalized;
            })->values();
        if ($steps->contains(static fn (array $step): bool => ! $allowedCapabilities->contains($step['capability']))) {
            throw new RuntimeException('计划草案包含意图范围外的能力。');
        }
        if ($steps->pluck('operation_id')->unique()->count() !== $steps->count()
            || $steps->count() !== $requiredOperations->count()
            || $steps->pluck('operation_id')->all() !== $requiredOperations->pluck('operation_id')->all()) {
            throw new RuntimeException('计划草案没有逐项保留意图操作。');
        }
        $draftOperations = $steps->keyBy('operation_id');
        foreach ($requiredOperations as $requiredOperation) {
            $draftOperation = $draftOperations->get($requiredOperation['operation_id']);
            if (! is_array($draftOperation)
                || ! hash_equals($requiredOperation['capability'], (string) $draftOperation['capability'])
                || AiPayloadDigest::make($requiredOperation['parameters']) !== AiPayloadDigest::make((array) $draftOperation['parameters'])) {
                throw new RuntimeException('计划草案改变或遗漏了意图操作。');
            }
        }

        return $steps->all();
    }

    /** @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,structured_output:array<string,mixed>,raw_preview:string} */
    public function probeStructuredOutput(AiModel $model, string $prompt): array
    {
        $catalog = $this->registry->all()
            ->map(static fn ($capability): array => [
                'key' => $capability->key,
                'description' => $capability->description,
                'input_schema' => $capability->inputSchema,
            ])->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
        $modelId = trim((string) $model->model_id);
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new RuntimeException('对话模型配置不完整');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $provider = OpenAiRuntimeProvider::registerProvider(
            'ai_workspace_probe_'.(int) $model->id,
            $driver,
            $providerUrl,
            $apiKey,
        );
        $startedAt = hrtime(true);
        $response = $this->withConcurrencySlot(
            fn (): object => (new IntentResolverAgent($catalog))->prompt($prompt, [], $provider, $modelId),
        );
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $structured = $response->toArray();
        foreach (['mode', 'intent', 'candidate_capabilities', 'requested_steps', 'known_parameters', 'missing_parameters'] as $key) {
            if (! array_key_exists($key, $structured)) {
                throw new RuntimeException('AI 工作台结构化输出缺少字段：'.$key);
            }
        }

        return [
            'provider' => $driver,
            'endpoint' => $providerUrl,
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'structured_output' => $structured,
            'raw_preview' => Str::limit(json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 500, ''),
        ];
    }

    /** @param iterable<int,mixed> $messages */
    public function answer(string $prompt, iterable $messages = [], ?int $adminId = null): string
    {
        $response = $this->prompt(new GeoHubAgent($messages), $prompt, 'ai_workspace_answer', $adminId);

        return trim((string) $response->text);
    }

    /** @param callable(string):void $onDelta */
    public function streamAnswer(string $prompt, callable $onDelta, iterable $messages = [], ?int $adminId = null): string
    {
        return $this->withConcurrencySlot(function () use ($prompt, $onDelta, $messages, $adminId): string {
            $agent = new GeoHubAgent($messages);
            $lastException = null;
            foreach ($this->models() as $model) {
                $reservation = null;
                $emitted = false;
                try {
                    [$provider, $reservation] = $this->modelContext($model, 'ai_workspace_answer', $adminId);
                    $stream = $agent->stream($prompt, [], $provider, (string) $model->model_id);
                    foreach ($stream as $event) {
                        if ($event instanceof TextDelta && $event->delta !== '') {
                            $emitted = true;
                            $onDelta($event->delta);
                        }
                    }
                    $this->usageQuota->recordModelSuccess($reservation);

                    return trim((string) $stream->text);
                } catch (Throwable $exception) {
                    if ($reservation !== null) {
                        $this->usageQuota->recordModelAttempt($reservation);
                    }
                    $lastException = new RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, (string) $model->api_url), 0, $exception);
                    if ($emitted) {
                        throw $lastException;
                    }
                }
            }

            throw $lastException ?? new RuntimeException('没有可用的对话模型');
        });
    }

    private function prompt(object $agent, string $prompt, string $slot, ?int $adminId = null): object
    {
        return $this->withConcurrencySlot(function () use ($agent, $prompt, $slot, $adminId): object {
            $lastException = null;
            foreach ($this->models() as $model) {
                $reservation = null;
                try {
                    [$provider, $reservation] = $this->modelContext($model, $slot, $adminId);
                    $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id);
                    $this->usageQuota->recordModelSuccess($reservation);

                    return $response;
                } catch (Throwable $exception) {
                    if ($reservation !== null) {
                        $this->usageQuota->recordModelAttempt($reservation);
                    }
                    $lastException = new RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, (string) $model->api_url), 0, $exception);
                }
            }

            throw $lastException ?? new RuntimeException('没有可用的对话模型');
        });
    }

    private function models(): iterable
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')->orWhereNotIn('model_type', ['embedding', 'image']);
            })
            ->when((bool) config('ai-workspace.require_verified_model', true), function ($query): void {
                $query->where('ai_workspace_structured_output_status', 'ready')
                    ->whereNotNull('ai_workspace_structured_output_verified_at');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
    }

    /** @return array{string,mixed} */
    private function modelContext(AiModel $model, string $slot, ?int $adminId = null): array
    {
        if ($adminId !== null) {
            $key = 'ai-workspace:model-budget:'.$adminId.':'.now()->toDateString();
            $limit = (int) config('ai-workspace.admin_daily_model_calls', 200);
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new RuntimeException('当前管理员今日的 AI 工作台模型额度已用完。');
            }
            RateLimiter::hit($key, max(60, now()->diffInSeconds(now()->endOfDay())));
        }
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
        $modelId = trim((string) $model->model_id);
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new RuntimeException('对话模型配置不完整');
        }

        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            throw new RuntimeException('对话模型不可用或已达到今日限额');
        }

        $provider = OpenAiRuntimeProvider::registerProvider(
            $slot.'_'.(int) $model->id,
            OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId),
            $providerUrl,
            $apiKey,
        );

        return [$provider, $reservation];
    }

    private function withConcurrencySlot(callable $operation): mixed
    {
        $cache = Cache::store(app()->environment('testing')
            ? (string) config('cache.default')
            : (string) config('ai-workspace.concurrency_cache_store', 'redis'));
        $lock = $cache->lock('ai-workspace:claim', 10);
        if (! $lock->get()) {
            throw new RuntimeException('AI 工作台调度锁繁忙。');
        }
        try {
            $modelCalls = max(0, (int) $cache->get('ai-workspace:model-calls', 0));
            $workflowRuns = AiWorkspaceRun::query()->where('state', 'running')->count();
            if (($modelCalls + $workflowRuns) >= (int) config('ai-workspace.global_concurrency', 10)) {
                throw new RuntimeException('AI 工作台已达到全局并发上限。');
            }
            $cache->put('ai-workspace:model-calls', $modelCalls + 1, now()->addMinutes(20));
        } finally {
            $lock->release();
        }

        try {
            return $operation();
        } finally {
            $cache->decrement('ai-workspace:model-calls');
        }
    }
}
