<?php

namespace App\Ai\Workspace;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class AiPlanCompiler
{
    public function __construct(
        private AiCapabilityRegistry $registry,
        private DistributionPayloadBuilder $distributionPayloads,
    ) {}

    /**
     * @param  list<array{capability:string,parameters:array<string,mixed>,depends_on?:list<int>,input_bindings?:array<string,array{step:int,path:string}>}>  $draftSteps
     */
    public function compile(Admin $admin, string $intent, array $draftSteps, int $version = 1): AiWorkflowPlan
    {
        if ($draftSteps === []) {
            throw new InvalidArgumentException('A workflow plan requires at least one step.');
        }
        $draftSteps = $this->expandIndependentSteps($draftSteps);
        if (count($draftSteps) > (int) config('ai-workspace.max_plan_steps', 100)) {
            throw new InvalidArgumentException('Workflow plan exceeds the configured step limit.');
        }

        $steps = [];
        $capabilityKeys = [];
        $highestRisk = 'low';
        foreach (array_values($draftSteps) as $position => $draftStep) {
            $key = (string) ($draftStep['capability'] ?? '');
            $capability = $this->registry->get($key);
            if (! $capability->allows($admin)) {
                throw new InvalidArgumentException('Capability permission denied: '.$key);
            }
            if (! $capability->isExecutable() || $capability->maturity === 'restricted') {
                throw new InvalidArgumentException('Capability cannot be compiled for execution: '.$key);
            }

            $dependsOn = $this->validateDependencies((array) ($draftStep['depends_on'] ?? []), $position + 1);
            $inputBindings = $this->validateInputBindings(
                $capability,
                (array) ($draftStep['input_bindings'] ?? []),
                $dependsOn,
                $steps,
            );
            $parameters = $this->validateParameters(
                $capability,
                (array) ($draftStep['parameters'] ?? []),
                array_keys($inputBindings),
            );
            $targetSummary = $inputBindings === []
                ? $this->targetSummaryFor($key, $parameters)
                : [
                    'binding_pending' => $inputBindings,
                    'declared_parameters' => $parameters,
                ];
            $capabilityKeys[] = $key;
            $highestRisk = $this->higherRisk($highestRisk, $capability->risk);
            $steps[] = [
                'position' => $position + 1,
                'depends_on' => $dependsOn,
                'input_bindings' => $inputBindings,
                'capability' => $key,
                'capability_name' => $capability->name,
                'capability_version' => $capability->version,
                'maturity' => $capability->maturity,
                'risk_level' => $capability->risk,
                'execution_scope' => $capability->executionScope,
                'data_classification' => $capability->dataClassification,
                'result_contract' => $capability->resultContract,
                'approval_policy' => $capability->approvalPolicy,
                'requires_approval' => $capability->requiresApproval(),
                'external_operation' => $capability->executionScope === 'external_write',
                'parameters' => $parameters,
                'target_summary' => $targetSummary,
            ];
        }

        $capabilityVersions = $this->registry->versions($capabilityKeys);
        $parameterDigest = AiPayloadDigest::make(collect($steps)->map(static fn (array $step): array => [
            'parameters' => $step['parameters'],
            'input_bindings' => $step['input_bindings'],
        ])->all());
        $targetDigest = AiPayloadDigest::make(array_column($steps, 'target_summary'));
        $planPayload = [
            'version' => $version,
            'intent' => $intent,
            'steps' => $steps,
            'capability_versions' => $capabilityVersions,
            'risk_level' => $highestRisk,
            'parameter_digest' => $parameterDigest,
            'target_digest' => $targetDigest,
        ];

        return new AiWorkflowPlan(
            version: $version,
            intent: $intent,
            steps: $steps,
            capabilityVersions: $capabilityVersions,
            riskLevel: $highestRisk,
            parameterDigest: $parameterDigest,
            targetDigest: $targetDigest,
            digest: AiPayloadDigest::make($planPayload),
        );
    }

    /** @return array<string,mixed> */
    public function validateParametersFor(string $capabilityKey, array $parameters): array
    {
        return $this->validateParameters($this->registry->get($capabilityKey), $parameters);
    }

    /** @param list<string> $deferredFields @return array<string,mixed> */
    private function validateParameters(AiCapabilityDefinition $capability, array $parameters, array $deferredFields = []): array
    {
        $original = $parameters;
        foreach ($deferredFields as $field) {
            if (array_key_exists($field, $parameters)) {
                throw new InvalidArgumentException('Bound parameters cannot also contain a literal value: '.$field);
            }
            $parameters[$field] = $this->bindingPlaceholder((array) ($capability->inputSchema[$field] ?? []));
        }
        $rules = [];
        foreach ($capability->inputSchema as $field => $schema) {
            $fieldRules = [(bool) ($schema['required'] ?? false) ? 'required' : 'nullable'];
            $fieldRules[] = match ($schema['type'] ?? 'string') {
                'integer' => 'integer',
                'integer_list' => 'array',
                'date' => 'date_format:Y-m-d',
                'url' => 'url:http,https',
                'enum' => 'string',
                default => 'string',
            };
            if (isset($schema['min'])) {
                $fieldRules[] = 'min:'.(int) $schema['min'];
            }
            if (isset($schema['max'])) {
                $fieldRules[] = 'max:'.(int) $schema['max'];
            }
            if (isset($schema['min_items'])) {
                $fieldRules[] = 'min:'.(int) $schema['min_items'];
            }
            if (isset($schema['max_items'])) {
                $fieldRules[] = 'max:'.(int) $schema['max_items'];
            }
            if (($schema['type'] ?? null) === 'enum') {
                $fieldRules[] = 'in:'.implode(',', array_map('strval', (array) ($schema['values'] ?? [])));
            }
            $rules[$field] = $fieldRules;
            if (($schema['type'] ?? null) === 'integer_list') {
                $rules[$field.'.*'] = ['integer', 'min:1', 'distinct'];
            }
        }

        $validator = Validator::make($parameters, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return array_intersect_key($validator->validated(), $capability->inputSchema, $original);
    }

    /** @return list<int> */
    private function validateDependencies(array $dependencies, int $position): array
    {
        $normalized = collect($dependencies)->map(static fn ($item): int => (int) $item)->unique()->sort()->values()->all();
        foreach ($normalized as $dependency) {
            if ($dependency < 1 || $dependency >= $position) {
                throw new InvalidArgumentException('Workflow dependencies must reference an earlier step.');
            }
        }

        return $normalized;
    }

    /** @return array<string,array{step:int,path:string}> */
    private function validateInputBindings(AiCapabilityDefinition $capability, array $bindings, array $dependencies, array $compiledSteps): array
    {
        $normalized = [];
        foreach ($bindings as $field => $binding) {
            if (! is_string($field) || ! array_key_exists($field, $capability->inputSchema) || ! is_array($binding)) {
                throw new InvalidArgumentException('Workflow input binding is invalid.');
            }
            $sourceStep = (int) ($binding['step'] ?? 0);
            $path = trim((string) ($binding['path'] ?? ''));
            if (! in_array($sourceStep, $dependencies, true)
                || $path === ''
                || preg_match('/\A[a-zA-Z0-9_.-]{1,160}\z/', $path) !== 1) {
                throw new InvalidArgumentException('Workflow input binding must reference a declared dependency and a safe artifact path.');
            }
            $source = collect($compiledSteps)->firstWhere('position', $sourceStep);
            $payloadSchema = (array) data_get($source, 'result_contract.payload_schema', []);
            $rootPath = explode('.', $path)[0];
            if (! is_array($source) || ! array_key_exists($rootPath, $payloadSchema)) {
                throw new InvalidArgumentException('Workflow input binding is not declared by the source result contract.');
            }
            $sourceType = (string) $payloadSchema[$rootPath];
            $targetType = (string) data_get($capability->inputSchema, $field.'.type', 'string');
            if (! hash_equals($sourceType, $targetType)) {
                throw new InvalidArgumentException('Workflow input binding types are incompatible.');
            }
            $normalized[$field] = ['step' => $sourceStep, 'path' => $path];
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param array<string,mixed> $schema */
    private function bindingPlaceholder(array $schema): mixed
    {
        return match ($schema['type'] ?? 'string') {
            'integer' => max(1, (int) ($schema['min'] ?? 1)),
            'integer_list' => [max(1, (int) ($schema['min'] ?? 1))],
            'date' => now()->toDateString(),
            'url' => 'https://binding.invalid/value',
            'enum' => (string) (array_values((array) ($schema['values'] ?? ['']))[0] ?? ''),
            default => '__bound_value__',
        };
    }

    /** @return array<string,mixed> */
    public function targetSummaryFor(string $capabilityKey, array $parameters): array
    {
        $summary = collect($parameters)
            ->only(['task_id', 'job_id', 'hosted_site_id', 'article_ids', 'channel_ids', 'url', 'name', 'title'])
            ->all();

        return match ($capabilityKey) {
            'task.status.change' => $summary + ['task_snapshot' => $this->taskSnapshot((int) $parameters['task_id'])],
            'article.draft' => $summary + [
                'category_snapshot' => $this->modelSnapshot(Category::query()->find((int) $parameters['category_id']), 'category'),
                'author_snapshot' => $this->modelSnapshot(Author::query()->find((int) $parameters['author_id']), 'author'),
            ],
            'distribution.preview', 'distribution.publish' => $summary + [
                'article_snapshots' => $this->articleSnapshots(
                    (array) $parameters['article_ids'],
                    $capabilityKey === 'distribution.publish',
                ),
                'channel_snapshots' => $this->channelSnapshots((array) $parameters['channel_ids']),
            ],
            'distribution.site_settings_sync' => $summary + [
                'channel_snapshots' => $this->channelSnapshots((array) $parameters['channel_ids']),
            ],
            'hosted_site.preflight' => $summary + ['hosted_site_snapshot' => $this->hostedSiteSnapshot((int) $parameters['hosted_site_id'])],
            'url_import.commit' => $summary + ['url_import_snapshot' => $this->urlImportSnapshot((int) $parameters['job_id'])],
            default => $summary,
        };
    }

    /** @param array<string,mixed> $parameters */
    public function lockTargetsFor(string $capabilityKey, array $parameters): void
    {
        match ($capabilityKey) {
            'task.status.change' => Task::query()->whereKey((int) $parameters['task_id'])->lockForUpdate()->firstOrFail(),
            'article.draft' => [
                Category::query()->whereKey((int) $parameters['category_id'])->lockForUpdate()->firstOrFail(),
                Author::query()->whereKey((int) $parameters['author_id'])->lockForUpdate()->firstOrFail(),
            ],
            'distribution.publish' => $this->lockDistributionTargets($parameters),
            'distribution.site_settings_sync' => DistributionChannel::query()
                ->whereIn('id', (array) $parameters['channel_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(),
            'url_import.commit' => UrlImportJob::query()->whereKey((int) $parameters['job_id'])->lockForUpdate()->firstOrFail(),
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function taskSnapshot(int $id): array
    {
        $task = Task::query()->find($id);
        if (! $task instanceof Task) {
            throw new InvalidArgumentException('Task target does not exist: '.$id);
        }

        return [
            'id' => $id,
            'updated_at' => $task->updated_at?->toISOString(),
            'revision' => AiPayloadDigest::make($task->only([
                'status', 'schedule_enabled', 'publish_scope', 'distribution_strategy', 'article_limit', 'max_retry_count',
            ])),
        ];
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function articleSnapshots(array $ids, bool $requireTask = false): array
    {
        $normalized = collect($ids)->map(static fn ($id): int => (int) $id)->unique()->sort()->values();
        $articles = Article::query()->with([
            'category:id,name,slug',
            'author:id,name',
            'task.distributionChannels',
            'articleImages.image',
        ])->whereIn('id', $normalized)->get()->keyBy('id');

        return $normalized->map(function (int $id) use ($articles, $requireTask): array {
            $article = $articles->get($id);
            if (! $article instanceof Article) {
                throw new InvalidArgumentException('Article target does not exist: '.$id);
            }
            if ($requireTask && ! $article->task instanceof Task) {
                throw new InvalidArgumentException('Article task target does not exist: '.$id);
            }

            return [
                'id' => $id,
                'updated_at' => $article->updated_at?->toISOString(),
                'revision' => AiPayloadDigest::make([
                    'status' => $article->status,
                    'review_status' => $article->review_status,
                    'task_id' => $article->task_id,
                    'title' => $article->title,
                    'content_hash' => hash('sha256', (string) $article->content),
                ]),
                'outbound_payload_digest' => AiPayloadDigest::make($this->distributionPayloads->build($article)),
                'task_snapshot' => $article->task instanceof Task ? [
                    'id' => (int) $article->task->id,
                    'updated_at' => $article->task->updated_at?->toISOString(),
                    'revision' => AiPayloadDigest::make([
                        'attributes' => $article->task->only([
                            'status', 'publish_scope', 'distribution_strategy', 'distribution_cursor', 'schedule_enabled',
                        ]),
                        'distribution_channels' => $article->task->distributionChannels->map(static fn (DistributionChannel $channel): array => [
                            'id' => (int) $channel->id,
                            'trigger' => (string) $channel->pivot->trigger,
                            'remote_status' => (string) $channel->pivot->remote_status,
                            'failure_policy' => (string) $channel->pivot->failure_policy,
                            'max_attempts' => (int) $channel->pivot->max_attempts,
                            'sort_order' => (int) $channel->pivot->sort_order,
                        ])->values()->all(),
                    ]),
                ] : null,
            ];
        })->all();
    }

    /** @param array<string,mixed> $parameters */
    private function lockDistributionTargets(array $parameters): array
    {
        $articles = Article::query()
            ->whereIn('id', (array) $parameters['article_ids'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $tasks = Task::query()
            ->whereIn('id', $articles->pluck('task_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $channels = DistributionChannel::query()
            ->whereIn('id', (array) $parameters['channel_ids'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [$articles, $tasks, $channels];
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function channelSnapshots(array $ids): array
    {
        $normalized = collect($ids)->map(static fn ($id): int => (int) $id)->unique()->sort()->values();
        $channels = DistributionChannel::query()->whereIn('id', $normalized)->get()->keyBy('id');

        return $normalized->map(function (int $id) use ($channels): array {
            $channel = $channels->get($id);
            if (! $channel instanceof DistributionChannel) {
                throw new InvalidArgumentException('Distribution channel target does not exist: '.$id);
            }

            return [
                'id' => $id,
                'updated_at' => $channel->updated_at?->toISOString(),
                'revision' => AiWorkspaceChannelRevision::make($channel),
            ];
        })->all();
    }

    /** @return array<string,mixed> */
    private function hostedSiteSnapshot(int $id): array
    {
        $profile = HostedSiteProfile::query()->with('channel')->find($id);
        if (! $profile instanceof HostedSiteProfile || ! $profile->channel instanceof DistributionChannel) {
            throw new InvalidArgumentException('Hosted site target does not exist: '.$id);
        }

        return [
            'id' => $id,
            'updated_at' => $profile->updated_at?->toISOString(),
            'channel' => $this->channelSnapshots([(int) $profile->channel->id])[0],
        ];
    }

    /** @return array<string,mixed> */
    private function urlImportSnapshot(int $id): array
    {
        $job = UrlImportJob::query()->find($id);
        if (! $job instanceof UrlImportJob) {
            throw new InvalidArgumentException('URL import target does not exist: '.$id);
        }

        return [
            'id' => $id,
            'status' => (string) $job->status,
            'updated_at' => $job->updated_at?->toISOString(),
            'result_digest' => hash('sha256', (string) $job->result_json),
        ];
    }

    /** @return array<string,mixed> */
    private function modelSnapshot(mixed $model, string $label): array
    {
        if ($model === null) {
            throw new InvalidArgumentException(ucfirst($label).' target does not exist.');
        }

        return ['id' => (int) $model->getKey(), 'updated_at' => $model->updated_at?->toISOString()];
    }

    private function higherRisk(string $left, string $right): string
    {
        $levels = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return ($levels[$right] ?? 4) > ($levels[$left] ?? 4) ? $right : $left;
    }

    /**
     * External distribution work is tracked per article so one enqueue failure
     * can produce an accurate partial result without hiding other articles.
     *
     * @param  list<array<string,mixed>>  $draftSteps
     * @return list<array<string,mixed>>
     */
    private function expandIndependentSteps(array $draftSteps): array
    {
        $groups = [];
        $positionMap = [];
        $nextPosition = 1;
        foreach (array_values($draftSteps) as $index => $draftStep) {
            $group = [];
            if (($draftStep['capability'] ?? null) === 'distribution.site_settings_sync') {
                $parameters = (array) ($draftStep['parameters'] ?? []);
                $channelIds = array_values(array_unique(array_map('intval', (array) ($parameters['channel_ids'] ?? []))));
                if ($channelIds === []) {
                    throw ValidationException::withMessages(['channel_ids' => ['The channel ids field must have at least 1 item.']]);
                }
                foreach ($channelIds as $channelId) {
                    $group[] = [
                        'capability' => 'distribution.site_settings_sync',
                        'parameters' => array_replace($parameters, ['channel_ids' => [$channelId]]),
                    ];
                }
            } elseif (($draftStep['capability'] ?? null) === 'distribution.publish') {
                $parameters = (array) ($draftStep['parameters'] ?? []);
                $articleIds = array_values(array_unique(array_map('intval', (array) ($parameters['article_ids'] ?? []))));
                if ($articleIds === []) {
                    throw ValidationException::withMessages(['article_ids' => ['The article ids field must have at least 1 item.']]);
                }
                foreach ($articleIds as $articleId) {
                    $group[] = [
                        'capability' => 'distribution.publish',
                        'parameters' => array_replace($parameters, ['article_ids' => [$articleId]]),
                    ];
                }
            } else {
                $group[] = $draftStep;
            }
            $logicalPosition = $index + 1;
            $positionMap[$logicalPosition] = range($nextPosition, $nextPosition + count($group) - 1);
            $nextPosition += count($group);
            $groups[] = [
                'steps' => $group,
                'depends_on' => array_key_exists('depends_on', $draftStep) ? (array) $draftStep['depends_on'] : null,
                'input_bindings' => (array) ($draftStep['input_bindings'] ?? []),
            ];
        }

        $expanded = [];
        foreach ($groups as $index => $group) {
            $logicalPosition = $index + 1;
            $logicalDependencies = $group['depends_on'] ?? ($logicalPosition > 1 ? [$logicalPosition - 1] : []);
            $expandedDependencies = [];
            foreach (array_values(array_unique(array_map('intval', $logicalDependencies))) as $dependency) {
                if ($dependency < 1 || $dependency >= $logicalPosition || ! isset($positionMap[$dependency])) {
                    throw new InvalidArgumentException('Workflow dependencies must reference an earlier step.');
                }
                array_push($expandedDependencies, ...$positionMap[$dependency]);
            }
            $expandedDependencies = array_values(array_unique($expandedDependencies));
            sort($expandedDependencies);

            $bindings = [];
            foreach ($group['input_bindings'] as $field => $binding) {
                $sourceLogicalPosition = (int) ($binding['step'] ?? 0);
                $sourcePositions = $positionMap[$sourceLogicalPosition] ?? [];
                if (count($sourcePositions) !== 1) {
                    throw new InvalidArgumentException('Workflow input binding cannot reference an expanded step group.');
                }
                $bindings[$field] = [
                    'step' => $sourcePositions[0],
                    'path' => (string) ($binding['path'] ?? ''),
                ];
            }

            foreach ($group['steps'] as $step) {
                $expanded[] = array_replace($step, [
                    'depends_on' => $expandedDependencies,
                    'input_bindings' => $bindings,
                ]);
            }
        }

        return $expanded;
    }
}
