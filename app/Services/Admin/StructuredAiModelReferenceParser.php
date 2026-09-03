<?php

namespace App\Services\Admin;

use JsonException;

final class StructuredAiModelReferenceParser
{
    /**
     * @param  list<array{path:string,many:bool}>  $paths
     * @return array{
     *   references:list<array{path:string,model_ids:list<int>}>,
     *   findings:list<array{path:string,reason:string}>
     * }
     */
    public function parse(mixed $payload, string $jsonColumn, array $paths): array
    {
        if ($payload === null) {
            return ['references' => [], 'findings' => []];
        }
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [
                    'references' => [],
                    'findings' => [['path' => $jsonColumn, 'reason' => 'invalid_json']],
                ];
            }
        }
        if (! is_array($payload)) {
            return [
                'references' => [],
                'findings' => [['path' => $jsonColumn, 'reason' => 'invalid_json']],
            ];
        }

        $references = [];
        $findings = [];
        foreach ($paths as $pathDefinition) {
            [$present, $rawValues] = $this->pathValues($payload, $pathDefinition['path']);
            if (! $present || $rawValues === []) {
                continue;
            }

            $path = $jsonColumn.'.'.$pathDefinition['path'];
            if (str_contains($pathDefinition['path'], '*')) {
                $values = $rawValues;
            } elseif ($pathDefinition['many']) {
                $rawValue = $rawValues[0] ?? null;
                if (! is_array($rawValue) || ! array_is_list($rawValue)) {
                    $findings[] = ['path' => $path, 'reason' => 'invalid_model_id'];

                    continue;
                }
                $values = $rawValue;
            } else {
                $values = [$rawValues[0] ?? null];
            }

            $modelIds = [];
            $hasInvalidValue = false;
            foreach ($values as $value) {
                $modelId = $this->positiveModelId($value);
                if ($modelId === null) {
                    $hasInvalidValue = true;

                    continue;
                }
                $modelIds[] = $modelId;
            }
            if ($hasInvalidValue) {
                $findings[] = ['path' => $path, 'reason' => 'invalid_model_id'];
            }
            $modelIds = array_values(array_unique($modelIds));
            if ($modelIds !== []) {
                $references[] = ['path' => $path, 'model_ids' => $modelIds];
            }
        }

        return ['references' => $references, 'findings' => $findings];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{bool,list<mixed>}
     */
    private function pathValues(array $payload, string $path): array
    {
        return $this->collectPathValues($payload, explode('.', $path));
    }

    /**
     * @param  list<string>  $segments
     * @return array{bool,list<mixed>}
     */
    private function collectPathValues(mixed $value, array $segments): array
    {
        if ($segments === []) {
            return [true, [$value]];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            if (! is_array($value)) {
                return [false, []];
            }

            $present = false;
            $values = [];
            foreach ($value as $child) {
                [$childPresent, $childValues] = $this->collectPathValues($child, $segments);
                $present = $present || $childPresent;
                array_push($values, ...$childValues);
            }

            return [$present, $values];
        }

        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return [false, []];
        }

        return $this->collectPathValues($value[$segment], $segments);
    }

    private function positiveModelId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);

        return $validated !== false && (string) $validated === $value ? $validated : null;
    }
}
