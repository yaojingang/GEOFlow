<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\KnowledgeFact;
use App\Models\KnowledgeFactValue;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KnowledgeFactValuePolicy
{
    /** @param array<string,mixed> $scope */
    public function scopeHash(array $scope): string
    {
        return hash('sha256', json_encode($this->canonicalize($scope), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $data */
    public function normalizeAndValidate(KnowledgeFact $fact, array $data, ?KnowledgeFactValue $existing = null): array
    {
        $scope = array_key_exists('scope_json', $data) ? (array) $data['scope_json'] : (array) ($existing?->scope_json ?? []);
        $data['scope_json'] = $scope;
        $data['scope_hash'] = $this->scopeHash($scope);

        foreach (['valid_from', 'valid_to'] as $field) {
            $value = array_key_exists($field, $data) ? $data[$field] : $existing?->{$field};
            $data[$field] = $value === null || $value === '' ? null : CarbonImmutable::parse($value)->toDateString();
        }

        if ($data['valid_from'] !== null && $data['valid_to'] !== null && $data['valid_from'] > $data['valid_to']) {
            throw new ConflictHttpException('knowledge_fact_invalid_interval');
        }

        $canonical = array_key_exists('canonical_value_json', $data)
            ? (array) $data['canonical_value_json']
            : (array) ($existing?->canonical_value_json ?? []);
        if (in_array($fact->value_type, ['integer', 'decimal', 'number'], true)) {
            $number = $canonical['value'] ?? null;
            if (! is_string($number) || preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', $number) !== 1) {
                throw ValidationException::withMessages(['canonical_value_json.value' => 'knowledge_fact_numeric_value_must_be_decimal_string']);
            }
        }

        $query = $fact->values()->where('scope_hash', $data['scope_hash'])->where('review_status', '!=', 'rejected');
        if ($existing !== null) {
            $query->whereKeyNot($existing->id);
        }
        $overlap = $query
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $data['valid_to'] ?? '9999-12-31'))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from'] ?? '0001-01-01'))
            ->exists();
        if ($overlap) {
            throw new ConflictHttpException('knowledge_fact_interval_conflict');
        }

        return $data;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
