<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiVisibilityCompetitor extends Model
{
    protected $fillable = [
        'name',
        'aliases',
        'is_active',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> 竞品名 + 别名（去重、去空）。 */
    public function matchTerms(): array
    {
        return collect([$this->name])
            ->merge(collect($this->aliases ?? [])
                ->map(static fn (mixed $term): string => is_array($term) ? (string) ($term['alias'] ?? '') : (string) $term))
            ->map(static fn (string $term): string => trim($term))
            ->filter(static fn (string $term): bool => $term !== '')
            ->unique()
            ->values()
            ->all();
    }
}
