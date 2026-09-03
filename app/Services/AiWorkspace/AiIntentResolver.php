<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiIntentResolution;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\Admin;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Illuminate\Support\Str;
use Throwable;

final readonly class AiIntentResolver
{
    private const TASK_COUNT_PATTERNS = [
        '系统有几个任务', '目前有几个任务', '有多少个任务', '多少个任务', '几个任务',
        '任务数量', '任务总数', '当前任务数', 'how many tasks', 'task count',
    ];

    public function __construct(
        private AiCapabilityRegistry $registry,
        private AiWorkspaceModelRuntime $runtime,
        private AiExecutionErrorSanitizer $errorSanitizer,
    ) {}

    public function resolve(
        string $prompt,
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
        ?callable $onModelComplete = null,
        ?callable $onModelFailure = null,
    ): AiIntentResolution {
        if ($this->isTaskCountQuestion($prompt)) {
            return $this->fromRules($prompt);
        }

        if ((bool) config('ai-workspace.runtime_enabled', false)) {
            $modelCompletion = null;
            try {
                $resolution = $this->fromModel($this->runtime->resolveIntent(
                    $prompt,
                    $actor,
                    static function (array $telemetry, mixed $receipt = null, mixed $usageDelivery = null) use (&$modelCompletion): void {
                        $modelCompletion = [$telemetry, $receipt, $usageDelivery];
                    },
                ), $prompt);
                if (is_array($modelCompletion)) {
                    if ($onModelComplete !== null) {
                        $onModelComplete(...$modelCompletion);
                    } elseif ($modelCompletion[2] instanceof AiWorkspaceModelUsageDelivery) {
                        $modelCompletion[2]->succeeded();
                    }
                }

                return $resolution;
            } catch (Throwable $exception) {
                if (is_array($modelCompletion) && $modelCompletion[2] instanceof AiWorkspaceModelUsageDelivery) {
                    $exception instanceof AiModelAccessException
                        ? $modelCompletion[2]->revoked($exception->getErrorCode())
                        : $modelCompletion[2]->discarded('ai_result_not_committed');
                }
                if ($onModelFailure !== null) {
                    $onModelFailure($exception);
                }
                if ($exception instanceof AiModelAccessException || $exception instanceof PermanentAiProviderException) {
                    throw $exception;
                }
                report(new \RuntimeException($this->errorSanitizer->sanitize($exception)));
            }
        }

        return $this->fromRules($prompt);
    }

    public function resolveRulesOnly(string $prompt): AiIntentResolution
    {
        return $this->fromRules($prompt);
    }

    /** @param array<string,mixed> $result */
    private function fromModel(array $result, string $prompt): AiIntentResolution
    {
        $candidates = collect((array) ($result['candidate_capabilities'] ?? []))
            ->filter(function (mixed $candidate): bool {
                if (! is_array($candidate) || ! isset($candidate['key'])) {
                    return false;
                }

                try {
                    $this->registry->get((string) $candidate['key']);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            })
            ->map(static fn (array $candidate): array => [
                'key' => (string) $candidate['key'],
                'confidence' => min(1, max(0, (float) ($candidate['confidence'] ?? 0))),
                'reason' => Str::limit((string) ($candidate['reason'] ?? ''), 240, ''),
            ])
            ->sortByDesc('confidence')
            ->unique('key')
            ->values()
            ->all();

        $mode = (string) ($result['mode'] ?? 'answer');
        if ($mode === 'workflow' && $candidates === []) {
            return $this->fromRules($prompt);
        }

        $parameters = $this->safeParameters((array) ($result['known_parameters'] ?? []));
        $candidateKeys = collect($candidates)->pluck('key');
        $rawRequestedSteps = collect((array) ($result['requested_steps'] ?? []));
        $requestedSteps = $rawRequestedSteps
            ->filter(static fn (mixed $step): bool => is_array($step)
                && is_string($step['operation_id'] ?? null)
                && trim((string) $step['operation_id']) !== ''
                && is_string($step['capability'] ?? null)
                && $candidateKeys->contains($step['capability']))
            ->map(function (array $step) use ($parameters, $prompt): array {
                $capability = (string) $step['capability'];
                $requiredFields = collect($this->registry->get($capability)->inputSchema)
                    ->filter(static fn (array $schema): bool => (bool) ($schema['required'] ?? false))
                    ->keys()
                    ->all();

                return [
                    'operation_id' => Str::limit(trim((string) $step['operation_id']), 64, ''),
                    'capability' => $capability,
                    'parameters' => $this->safeParameters((array) ($step['parameters'] ?? []))
                        + $parameters
                        + $this->extractParameters($prompt, $capability),
                    'missing_parameters' => array_values(array_intersect(
                        array_filter(array_map('strval', (array) ($step['missing_parameters'] ?? []))),
                        $requiredFields,
                    )),
                ];
            })->values();
        $hasInvalidRequestedSteps = $rawRequestedSteps->count() !== $requestedSteps->count();
        $hasNoRequestedSteps = $mode === 'workflow' && $requestedSteps->isEmpty();
        $hasDuplicateOperationIds = $requestedSteps->pluck('operation_id')->unique()->count() !== $requestedSteps->count();
        $requestedSteps = $requestedSteps->unique('operation_id')->values();
        if ($mode === 'workflow' && $requestedSteps->isEmpty() && $candidates !== []) {
            $capability = (string) $candidates[0]['key'];
            $requestedSteps = collect([[
                'operation_id' => 'operation-1',
                'capability' => $capability,
                'parameters' => $parameters + $this->extractParameters($prompt, $capability),
                'missing_parameters' => [],
            ]]);
        }
        $knownParameters = (array) ($requestedSteps->first()['parameters'] ?? $parameters);
        $workflowSteps = $mode === 'workflow'
            ? $requestedSteps->map(static fn (array $step): array => [
                'operation_id' => $step['operation_id'],
                'capability' => $step['capability'],
                'parameters' => $step['parameters'],
            ])->all()
            : [];
        $missing = $requestedSteps->flatMap(fn (array $step): array => array_merge(
            $this->missingParameters($step['capability'], $step['parameters']),
            $step['missing_parameters'],
        ))->unique()->values()->all();
        $requiredFields = $requestedSteps->flatMap(fn (array $step): array => collect(
            $this->registry->get($step['capability'])->inputSchema,
        )->filter(static fn (array $schema): bool => (bool) ($schema['required'] ?? false))->keys()->all())->unique();

        return new AiIntentResolution(
            mode: $mode === 'workflow' ? 'workflow' : 'answer',
            intent: Str::limit((string) ($result['intent'] ?? $prompt), 120, ''),
            candidates: $candidates,
            knownParameters: $knownParameters,
            missingParameters: array_values(array_unique(array_merge(
                $missing,
                $requiredFields->intersect(array_map('strval', (array) ($result['missing_parameters'] ?? [])))->all(),
            ))),
            ambiguities: array_values(array_unique(array_merge(
                array_filter(array_map('strval', (array) ($result['ambiguities'] ?? []))),
                $hasInvalidRequestedSteps ? ['部分操作无法映射到已登记能力，请确认操作范围。'] : [],
                $hasNoRequestedSteps ? ['尚未识别到明确的系统操作，请补充要执行的动作。'] : [],
                $hasDuplicateOperationIds ? ['检测到重复的操作标识，请确认各项操作。'] : [],
            ))),
            semanticConfidence: min(1, max(0, (float) ($result['semantic_confidence'] ?? 0))),
            objectConfidence: min(1, max(0, (float) ($result['object_confidence'] ?? 0))),
            completenessConfidence: $missing === []
                ? min(1, max(0, (float) ($result['completeness_confidence'] ?? 1)))
                : 0.4,
            source: 'model',
            workflowSteps: $workflowSteps,
        );
    }

    private function fromRules(string $prompt): AiIntentResolution
    {
        $normalized = mb_strtolower(trim($prompt), 'UTF-8');
        $patterns = [
            'distribution.site_settings_sync' => ['同步站点设置', '站点设置同步', 'sync site settings'],
            'url_import.commit' => ['提交 url 导入', '确认 url 导入', '提交导入任务', 'commit url import'],
            'hosted_site.preflight' => ['预检', 'preflight', 'health check', '健康检查'],
            'task.status.change' => ['启动任务', '启用任务', '停止任务', '暂停任务', 'start task', 'stop task'],
            'distribution.publish' => ['执行分发', '发布到', '多站发布', '多站分发', 'publish to'],
            'distribution.preview' => ['分发预览', '发布预览', '分发计划', 'distribution preview'],
            'url_import.preview' => ['url 导入', '网址导入', '链接导入', 'import url', '导入预览'],
            'knowledge.draft' => ['知识草稿', '企业知识', '知识库草稿', 'knowledge draft'],
            'article.draft' => ['文章草稿', '写一篇', '创建文章', '起草文章', '起草一篇', 'article draft'],
            'task.draft' => ['任务草稿', '创建任务', '新建任务', 'create task'],
            'analytics.weekly_report' => ['周报', '本周运营', 'weekly report'],
            'analytics.daily_report' => [
                '日报', '今日运营', '今天数据', 'daily report',
                ...self::TASK_COUNT_PATTERNS,
            ],
            'visibility.diagnose' => ['可见性', 'geo 诊断', '品牌诊断', '信源诊断', 'visibility'],
            'content.opportunities' => ['内容机会', '选题机会', '选题建议', '关键词机会', 'content opportunit'],
            'system.capabilities.explain' => ['能做什么', '有哪些能力', '系统功能', '后台入口', 'capabilities'],
        ];

        $matches = [];
        $matchPositions = [];
        foreach ($patterns as $key => $needles) {
            $hits = collect($needles)->filter(static fn (string $needle): bool => str_contains($normalized, $needle))->count();
            if ($hits > 0) {
                $matches[$key] = min(0.98, 0.88 + ($hits * 0.05));
                $matchPositions[$key] = collect($needles)
                    ->map(static fn (string $needle): int|false => mb_strpos($normalized, $needle, 0, 'UTF-8'))
                    ->filter(static fn (int|false $position): bool => $position !== false)
                    ->min();
            }
        }
        arsort($matches);

        if ($matches === []) {
            return new AiIntentResolution(
                mode: 'answer',
                intent: 'general_question',
                candidates: [],
                knownParameters: [],
                missingParameters: [],
                ambiguities: [],
                semanticConfidence: 0.95,
                objectConfidence: 1,
                completenessConfidence: 1,
            );
        }

        $key = (string) array_key_first($matches);
        $confidence = (float) $matches[$key];
        $parameters = $this->extractParameters($prompt, $key);
        $compound = preg_match('/然后|并且|接着|再|and\s+then|,\s*then/iu', $prompt) === 1;
        $workflowOperations = collect();
        if ($compound) {
            $segments = preg_split('/然后|并且|接着|再|and\s+then|,\s*then/iu', $prompt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($segments as $segment) {
                $segmentNormalized = mb_strtolower(trim($segment), 'UTF-8');
                $segmentMatches = [];
                foreach ($patterns as $segmentKey => $needles) {
                    $hits = collect($needles)->filter(
                        static fn (string $needle): bool => str_contains($segmentNormalized, $needle)
                    )->count();
                    if ($hits > 0) {
                        $segmentMatches[$segmentKey] = min(0.98, 0.88 + ($hits * 0.05));
                    }
                }
                arsort($segmentMatches);
                if ($segmentMatches !== []) {
                    $segmentKey = (string) array_key_first($segmentMatches);
                    $workflowOperations->push([
                        'capability' => $segmentKey,
                        'parameters' => $this->extractParameters($segment, $segmentKey),
                    ]);
                }
            }
        }
        if ($workflowOperations->count() < 2) {
            if ($compound && count($matches) > 1) {
                asort($matchPositions);
                $stepKeys = array_keys($matchPositions);
            } else {
                $stepKeys = [$key];
            }
            $workflowOperations = collect($stepKeys)->map(fn (string $stepKey): array => [
                'capability' => $stepKey,
                'parameters' => $this->extractParameters($prompt, $stepKey),
            ]);
        }
        $workflowSteps = $workflowOperations->map(static fn (array $operation, int $index): array => [
            'operation_id' => 'operation-'.($index + 1),
            'capability' => $operation['capability'],
            'parameters' => $operation['parameters'],
        ])->values()->all();
        $missing = collect($workflowSteps)->flatMap(
            fn (array $step): array => $this->missingParameters($step['capability'], $step['parameters'])
        )->unique()->values()->all();
        $ties = array_keys(array_filter($matches, static fn (float $score): bool => abs($score - $confidence) < 0.001));

        return new AiIntentResolution(
            mode: 'workflow',
            intent: $key,
            candidates: collect($matches)->take(3)->map(
                static fn (float $score, string $candidateKey): array => [
                    'key' => $candidateKey,
                    'confidence' => $score,
                    'reason' => '关键词与能力描述匹配',
                ]
            )->values()->all(),
            knownParameters: (array) ($workflowSteps[0]['parameters'] ?? $parameters),
            missingParameters: $missing,
            ambiguities: count($ties) > 1 && ! $compound ? ['检测到多个同等可能的操作，请选择目标能力。'] : [],
            semanticConfidence: $confidence,
            objectConfidence: $this->objectConfidence($key, $parameters),
            completenessConfidence: $missing === [] ? 1 : max(0.2, 1 - (count($missing) * 0.3)),
            workflowSteps: $workflowSteps,
        );
    }

    private function isTaskCountQuestion(string $prompt): bool
    {
        $normalized = mb_strtolower(trim($prompt), 'UTF-8');

        return collect(self::TASK_COUNT_PATTERNS)
            ->contains(static fn (string $pattern): bool => str_contains($normalized, $pattern));
    }

    /** @return array<string,mixed> */
    private function extractParameters(string $prompt, ?string $capabilityKey): array
    {
        $parameters = [];
        if (preg_match('#https?://[^\s<>"\']+#iu', $prompt, $matches) === 1) {
            $parameters['url'] = rtrim($matches[0], '，。,.');
        }
        if (preg_match('/(?:任务|task)\s*[#ID:：-]*\s*(\d+)/iu', $prompt, $matches) === 1) {
            $parameters['task_id'] = (int) $matches[1];
        }
        if (preg_match('/(?:导入任务|import\s*job)\s*[#ID:：-]*\s*(\d+)/iu', $prompt, $matches) === 1) {
            $parameters['job_id'] = (int) $matches[1];
        }
        if (preg_match('/(?:站点|site)\s*[#ID:：-]*\s*(\d+)/iu', $prompt, $matches) === 1) {
            $parameters['hosted_site_id'] = (int) $matches[1];
        }
        if (preg_match_all('/(?:文章|article)\s*[#ID:：-]*\s*(\d+)/iu', $prompt, $matches) > 0) {
            $parameters['article_ids'] = array_values(array_unique(array_map('intval', $matches[1])));
        }
        if (preg_match_all('/(?:渠道|站点|channel)\s*[#ID:：-]*\s*(\d+)/iu', $prompt, $matches) > 0) {
            $parameters['channel_ids'] = array_values(array_unique(array_map('intval', $matches[1])));
        }

        $quoted = null;
        if (preg_match('/[“「\"]([^”」\"]{1,500})[”」\"]/u', $prompt, $matches) === 1) {
            $quoted = trim($matches[1]);
        }
        $namedObject = null;
        if (preg_match('/(?:品牌|主题|对象|query)\s*[：:]\s*([^，。\n]{1,200})/iu', $prompt, $matches) === 1) {
            $namedObject = trim($matches[1]);
        }

        return match ($capabilityKey) {
            'visibility.diagnose' => $parameters + (($quoted ?: $namedObject) ? ['query' => $quoted ?: $namedObject] : []),
            'content.opportunities' => $parameters + ($quoted ? ['theme' => $quoted] : []),
            'task.draft' => $parameters + $this->extractTaskDraftParameters($prompt, $quoted),
            'knowledge.draft' => $parameters + ($quoted ? ['name' => $quoted] : []),
            'task.status.change' => $parameters + ['action' => preg_match('/停止|暂停|stop/iu', $prompt) === 1 ? 'stop' : 'start'],
            default => $parameters,
        };
    }

    /** @return array<string,int|string> */
    private function extractTaskDraftParameters(string $prompt, ?string $quoted): array
    {
        $parameters = $quoted ? ['name' => Str::limit($quoted, 100, '')] : [];

        if (preg_match('/(?:文章数量|文章数|文章上限|article\s*(?:count|limit))\s*(?:为|是|[:：=])?\s*(\d+)/iu', $prompt, $matches) === 1
            || preg_match('/(?:生成|创建|需要)\s*(\d+)\s*篇(?:文章)?/iu', $prompt, $matches) === 1) {
            $parameters['article_limit'] = (int) $matches[1];
        }

        if (preg_match('/(?:发布间隔|publish\s*interval)\s*(?:为|是|[:：=])?\s*(\d+)\s*(秒|分钟|分|小时|时|天|seconds?|minutes?|hours?|days?)?/iu', $prompt, $matches) === 1
            || preg_match('/每\s*(\d+)\s*(秒|分钟|分|小时|时|天|seconds?|minutes?|hours?|days?)\s*(?:发布|执行)?/iu', $prompt, $matches) === 1) {
            $parameters['publish_interval'] = $this->durationInSeconds((int) $matches[1], (string) ($matches[2] ?? '秒'));
        }

        return $parameters;
    }

    private function durationInSeconds(int $value, string $unit): int
    {
        $multiplier = match (mb_strtolower($unit, 'UTF-8')) {
            '分钟', '分', 'minute', 'minutes' => 60,
            '小时', '时', 'hour', 'hours' => 3600,
            '天', 'day', 'days' => 86400,
            default => 1,
        };

        return $value > intdiv(PHP_INT_MAX, $multiplier)
            ? PHP_INT_MAX
            : $value * $multiplier;
    }

    /** @return list<string> */
    private function missingParameters(string $key, array $parameters): array
    {
        $capability = $this->registry->get($key);

        return collect($capability->inputSchema)
            ->filter(static fn (array $schema, string $field): bool => (bool) ($schema['required'] ?? false)
                && (! array_key_exists($field, $parameters) || $parameters[$field] === '' || $parameters[$field] === []))
            ->keys()->values()->all();
    }

    private function objectConfidence(string $key, array $parameters): float
    {
        $objectFields = ['task.status.change' => 'task_id', 'hosted_site.preflight' => 'hosted_site_id'];
        $field = $objectFields[$key] ?? null;

        return $field === null || isset($parameters[$field]) ? 1 : 0.35;
    }

    /** @return array<string,mixed> */
    private function safeParameters(array $parameters): array
    {
        return collect($parameters)->filter(
            static fn (mixed $value): bool => (is_scalar($value) && $value !== '')
                || (is_array($value) && count($value) <= 100 && collect($value)->every(static fn (mixed $item): bool => is_scalar($item)))
        )->all();
    }
}
