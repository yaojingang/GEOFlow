<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ValidateAtomicComparatorContractCommand extends Command
{
    protected $signature = 'geoflow:validate-atomic-comparator-contract {--dataset=tests/Fixtures/ai-quality/atomic-comparator-contract-v1.json}';

    protected $description = 'Validate the deterministic atomic fact comparison contract fixture';

    public function handle(): int
    {
        $path = (string) $this->option('dataset');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }
        if (! File::isFile($path)) {
            $this->components->error('Comparator contract not found.');

            return self::FAILURE;
        }
        $data = json_decode((string) File::get($path), true);
        $cases = is_array($data['cases'] ?? null) ? $data['cases'] : [];
        if (($data['schema_version'] ?? null) !== 1 || ($data['case_count'] ?? null) !== 250 || count($cases) !== 250) {
            $this->components->error('Comparator contract must contain 250 schema-v1 cases.');

            return self::FAILURE;
        }
        foreach ($cases as $case) {
            if (! isset($case['id'], $case['kind'], $case['claim'], $case['standard'], $case['expected']['result'], $case['expected']['decision'])) {
                $this->components->error('Comparator contract contains an invalid case.');

                return self::FAILURE;
            }
        }
        $this->components->info('Atomic comparator contract is valid: 250 cases.');

        return self::SUCCESS;
    }
}
