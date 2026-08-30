<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Facades\File;
use RuntimeException;

class AiQualityEvaluationDataset
{
    /** @return array<string,mixed> */
    public function load(string $path): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("Dataset not found: {$path}");
        }
        $dataset = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($dataset) || ! is_array($dataset['cases'] ?? null) || $dataset['cases'] === []) {
            throw new RuntimeException('The dataset contains no evaluation cases.');
        }
        foreach ($dataset['cases'] as $index => $case) {
            if (! is_array($case) || trim((string) ($case['id'] ?? '')) === '' || ! in_array($case['split'] ?? null, ['calibration', 'regression', 'blind'], true)) {
                throw new RuntimeException("Invalid evaluation case at index {$index}.");
            }
            if (isset($case['atomic_fact'])) {
                $this->validateAtomicFact((array) $case['atomic_fact'], (string) $case['id']);
            }
        }

        return $dataset;
    }

    /** @param array<string,mixed> $fact */
    private function validateAtomicFact(array $fact, string $caseId): void
    {
        foreach (['claim_role', 'definition', 'canonical', 'evidence', 'expected_applicability', 'expected_result', 'expected_fallback', 'expected_final_decision'] as $key) {
            if (! array_key_exists($key, $fact)) {
                throw new RuntimeException("Atomic case {$caseId} misses {$key}.");
            }
        }
    }
}
