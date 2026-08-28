<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiWorkspaceUrlSanitizer;
use App\Models\AiWorkspaceStep;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final readonly class AiWorkspaceCapabilityPresenter
{
    /** @var array<string,list<string>> */
    private const METRIC_FIELDS = [
        'operational_report' => ['articles_created', 'articles_published', 'new_leads', 'total_tasks', 'active_tasks'],
        'visibility_diagnosis' => ['run_count', 'completed_count', 'source_count'],
        'task_draft' => ['task_id', 'status'],
        'article_draft' => ['article_id', 'status', 'review_status'],
        'knowledge_draft' => ['project_id', 'status'],
        'url_import_preview' => ['job_id', 'status'],
        'url_import_commit' => ['job_id', 'status'],
        'task_state' => ['task_id', 'status'],
        'distribution_enqueue' => ['queued_targets', 'status'],
        'site_settings_sync' => ['record_id', 'status'],
        'hosted_site_preflight' => ['record_id', 'status'],
    ];

    public function __construct(private AiCapabilityRegistry $registry) {}

    /** @param array<string,mixed>|null $result @return array<string,mixed>|null */
    public function present(?array $result, ?string $artifactName = null, ?string $artifactSourceUrl = null): ?array
    {
        if ($result === null) {
            return null;
        }

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $type = $this->plainText($result['artifact_type'] ?? 'generic', 60);
        $family = $this->family($type);
        $metrics = $this->metrics($type, $payload);
        $items = $this->items($type, $payload);
        $period = $type === 'operational_report' ? $this->period($payload) : null;
        $sourceUrl = AiWorkspaceUrlSanitizer::clean($result['source_url'] ?? $artifactSourceUrl);

        return [
            'schema_version' => 1,
            'type' => $type,
            'family' => $family,
            'summary' => $this->plainText($result['summary'] ?? '', 1000),
            'period' => $period,
            'metrics' => $metrics,
            'items' => $items,
            'source_name' => $this->plainText($artifactName ?? $result['artifact_name'] ?? '', 180),
            'source_route' => $this->plainText($result['source_route'] ?? '', 180),
            'source_url' => $sourceUrl,
            'outcome' => $this->plainText($result['outcome'] ?? 'completed', 40),
            'external_outcome_known' => (bool) ($result['external_outcome_known'] ?? true),
        ];
    }

    /** @return array<string,string|int|float|bool|null> */
    public function inputPresentation(AiWorkspaceStep $step): array
    {
        return $this->safeScalars((array) $step->target_summary);
    }

    /** @return array<string,string|int|float|bool|null> */
    public function editableInput(AiWorkspaceStep $step): array
    {
        $capability = $this->registry->get((string) $step->capability_key);
        $editableFields = collect($capability->inputSchema)
            ->filter(static fn (array $schema): bool => (bool) ($schema['editable'] ?? false))
            ->keys()
            ->all();

        return $this->safeScalars(Arr::only((array) $step->parameters, $editableFields));
    }

    /** @param array<string,mixed> $payload @return list<array{key:string,value:string|int|float}> */
    private function metrics(string $type, array $payload): array
    {
        if ($type === 'content_opportunities') {
            $opportunities = is_array($payload['opportunities'] ?? null) ? $payload['opportunities'] : [];

            return [['key' => 'opportunities', 'value' => count($opportunities)]];
        }
        if ($type === 'capability_catalog') {
            $items = is_array($payload['capabilities'] ?? null)
                ? $payload['capabilities']
                : (is_array($payload['items'] ?? null) ? $payload['items'] : []);
            $count = $payload['count'] ?? count($items);

            return is_int($count) || is_float($count) || is_string($count)
                ? [['key' => 'capabilities', 'value' => $count]]
                : [];
        }
        if ($type === 'distribution_matrix') {
            $matrix = is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [];

            return [
                ['key' => 'targets', 'value' => count($matrix)],
                ['key' => 'eligible_targets', 'value' => count(array_filter($matrix, static fn (mixed $item): bool => is_array($item) && ($item['eligible'] ?? false) === true))],
            ];
        }

        $aliases = [
            'run_count' => 'collection_runs',
            'completed_count' => 'completed_runs',
            'source_count' => 'sources_count',
            'task_id' => 'record_id',
            'article_id' => 'record_id',
            'project_id' => 'record_id',
            'job_id' => 'record_id',
        ];
        $metrics = [];
        foreach (self::METRIC_FIELDS[$type] ?? [] as $field) {
            $value = data_get($payload, $field);
            if (is_int($value) || is_float($value) || is_string($value)) {
                $metrics[] = ['key' => $aliases[$field] ?? $field, 'value' => $value];
            }
        }

        return collect($metrics)->unique('key')->values()->all();
    }

    /** @param array<string,mixed> $payload @return list<array{label:string,value:string|int|float}> */
    private function items(string $type, array $payload): array
    {
        $source = match ($type) {
            'visibility_diagnosis' => $payload['top_domains'] ?? $payload['sources'] ?? [],
            'content_opportunities' => $payload['opportunities'] ?? [],
            'capability_catalog' => $payload['capabilities'] ?? $payload['items'] ?? [],
            'navigation_only' => $payload['routes'] ?? $payload['entries'] ?? $payload['items'] ?? [],
            'distribution_matrix' => $payload['matrix'] ?? [],
            default => [],
        };
        if (! is_array($source)) {
            return [];
        }

        $labelFields = match ($type) {
            'visibility_diagnosis' => ['domain', 'name', 'label'],
            'content_opportunities' => ['keyword', 'title', 'name'],
            'capability_catalog' => ['name', 'title', 'key'],
            'navigation_only' => ['name', 'label', 'title'],
            'distribution_matrix' => ['article_title', 'channel_name'],
            default => ['name', 'title', 'label'],
        };
        $valueFields = match ($type) {
            'visibility_diagnosis' => ['mentions', 'count'],
            'content_opportunities' => ['priority', 'score'],
            'capability_catalog' => ['maturity', 'scope'],
            'navigation_only' => ['path', 'permission'],
            default => ['status', 'count'],
        };

        return collect($source)->take(6)->map(function (mixed $item) use ($labelFields, $valueFields): ?array {
            if (is_string($item) || is_int($item) || is_float($item)) {
                return ['label' => $this->plainText($item, 180), 'value' => ''];
            }
            if (! is_array($item)) {
                return null;
            }
            $label = collect($labelFields)->map(fn (string $field): string => $this->plainText($item[$field] ?? '', 180))->filter()->implode(' / ');
            if ($label === '') {
                return null;
            }
            $value = collect($valueFields)->map(fn (string $field): mixed => $item[$field] ?? null)
                ->first(static fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value));

            return ['label' => $label, 'value' => $value ?? ''];
        })->filter()->values()->all();
    }

    /** @param array<string,mixed> $payload @return array{from:string,to:string}|null */
    private function period(array $payload): ?array
    {
        $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
        $from = $this->plainText($period['from'] ?? '', 40);
        $to = $this->plainText($period['to'] ?? '', 40);

        return $from !== '' && $to !== '' ? ['from' => $from, 'to' => $to] : null;
    }

    private function family(string $type): string
    {
        return match ($type) {
            'operational_report' => 'metrics',
            'visibility_diagnosis' => 'diagnosis',
            'content_opportunities' => 'opportunities',
            'capability_catalog' => 'catalog',
            'navigation_only' => 'navigation',
            'task_draft', 'article_draft', 'knowledge_draft', 'url_import_preview', 'distribution_matrix' => 'preview',
            'url_import_commit', 'task_state', 'distribution_enqueue', 'distribution_receipt', 'site_settings_sync', 'hosted_site_preflight' => 'receipt',
            default => 'generic',
        };
    }

    /** @param array<string,mixed> $values @return array<string,string|int|float|bool|null> */
    private function safeScalars(array $values): array
    {
        $sensitive = ['api_key', 'password', 'token', 'secret', 'cookie', 'authorization', 'credential'];
        $safe = [];
        foreach ($values as $key => $value) {
            $normalized = Str::lower((string) $key);
            if (collect($sensitive)->contains(static fn (string $needle): bool => Str::contains($normalized, $needle))) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[(string) $key] = $value;
            } elseif (is_string($value)) {
                $safe[(string) $key] = $this->plainText($value, 240);
            }
        }

        return $safe;
    }

    private function plainText(mixed $value, int $limit): string
    {
        return Str::limit(trim(strip_tags((string) $value)), $limit, '');
    }
}
