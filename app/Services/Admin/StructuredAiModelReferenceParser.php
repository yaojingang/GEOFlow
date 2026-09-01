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
            [$present, $rawValue] = $this->pathValue($payload, $pathDefinition['path']);
            if (! $present || $rawValue === null) {
                continue;
            }

            $path = $jsonColumn.'.'.$pathDefinition['path'];
            if ($pathDefinition['many']) {
                if (! is_array($rawValue) || ! array_is_list($rawValue)) {
                    $findings[] = ['path' => $path, 'reason' => 'invalid_model_id'];

                    continue;
                }
                $values = $rawValue;
            } else {
                $values = [$rawValue];
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

    /** @param array<string, mixed> $payload
     * @return array{bool,mixed}
     */
    private function pathValue(array $payload, string $path): array
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
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
