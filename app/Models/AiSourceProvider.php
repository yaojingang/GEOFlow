<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSourceProvider extends Model
{
    public const PROVIDER_DOUBAO_SEARCH_CUSTOM = 'doubao_search_custom';

    protected $hidden = [
        'api_key',
    ];

    protected $fillable = [
        'name',
        'provider_key',
        'endpoint_url',
        'api_key',
        'status',
        'daily_limit',
        'used_today',
        'usage_date',
        'total_used',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'usage_date' => 'date',
            'total_used' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    public function visibilityRuns(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'ai_source_provider_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function visibilitySearchOptions(): array
    {
        $metadata = is_array($this->metadata_json) ? $this->metadata_json : [];

        return [
            'count' => max(1, min(20, (int) ($metadata['count'] ?? config('geoflow.ai_visibility.default_search_count', 10)))),
            'search_type' => (string) ($metadata['search_type'] ?? 'web'),
            'need_summary' => (bool) ($metadata['need_summary'] ?? true),
            'need_content' => (bool) ($metadata['need_content'] ?? true),
            'need_url' => (bool) ($metadata['need_url'] ?? true),
            'content_formats' => (string) ($metadata['content_formats'] ?? 'Markdown'),
            'auth_info_level' => (string) ($metadata['auth_info_level'] ?? ''),
            'sites' => $this->listOption($metadata['sites'] ?? []),
            'block_hosts' => $this->listOption($metadata['block_hosts'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function listOption(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
