<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UpgradePlanGeneratorTest extends TestCase
{
    public function test_repository_check_preserves_the_reviewed_plan_bytes(): void
    {
        $path = base_path('deployment/upgrade-plan.json');
        $before = file_get_contents($path);
        $process = new Process(['python3', base_path('deployment/generate-upgrade-plan.py'), '--check']);
        $process->mustRun();
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_generator_rejects_invalid_wire_contracts_before_checking_the_inventory(): void
    {
        $temporary = sys_get_temp_dir().'/geoflow-plan-contract-'.bin2hex(random_bytes(8));
        File::makeDirectory($temporary.'/deployment', 0700, true);
        File::makeDirectory($temporary.'/database/migrations', 0700, true);
        copy(base_path('deployment/generate-upgrade-plan.py'), $temporary.'/deployment/generate-upgrade-plan.py');
        $migration = '<?php // Review fixture migration.';
        file_put_contents($temporary.'/database/migrations/2026_09_08_000001_example.php', $migration);
        $baseline = [
            'schema_version' => 1,
            'strategy' => 'maintenance',
            'allowed_sources' => [],
            'migrations' => [['name' => '2026_09_08_000001_example', 'sha256' => hash('sha256', $migration), 'online' => false]],
            'compatibility' => ['schema' => false, 'queue' => false, 'cache' => false, 'storage' => false],
            'steps' => [['id' => 'migrate', 'kind' => 'migrate', 'phase' => 'apply', 'timeout_seconds' => 600, 'online' => false]],
        ];
        $encode = static fn (array $plan): string => json_encode($plan, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach (['plan' => [], 'compatibility' => ['compatibility'], 'migration' => ['migrations', 0], 'step' => ['steps', 0]] as $scope => $path) {
            $fields = $baseline;
            foreach ($path as $segment) {
                $fields = $fields[$segment];
            }
            foreach (array_keys($fields) as $field) {
                foreach (['missing', 'null', 'case-alias'] as $change) {
                    $plan = $baseline;
                    $target = &$plan;
                    foreach ($path as $segment) {
                        $target = &$target[$segment];
                    }
                    if ($change === 'null') {
                        $target[$field] = null;
                    } else {
                        if ($change === 'case-alias') {
                            $target[strtoupper($field)] = $target[$field];
                        }
                        unset($target[$field]);
                    }
                    unset($target);
                    $cases[$scope.'/'.$field.'/'.$change] = $encode($plan);
                }
            }
        }
        $raw = $encode($baseline);
        foreach ([
            'duplicate-root' => ['"strategy":"maintenance"', '"strategy":"online","strategy":"maintenance"'],
            'duplicate-compatibility' => ['"schema":false', '"schema":true,"schema":false'],
            'duplicate-migration' => ['"name":"2026_09_08_000001_example"', '"name":"ignored","name":"2026_09_08_000001_example"'],
            'duplicate-step' => ['"timeout_seconds":600', '"timeout_seconds":1,"timeout_seconds":600'],
            'numeric-boolean' => ['"online":false', '"online":0'],
            'string-boolean' => ['"online":false', '"online":"false"'],
            'fractional-timeout' => ['"timeout_seconds":600', '"timeout_seconds":600.0'],
            'null-source' => ['"allowed_sources":[]', '"allowed_sources":[null]'],
            'overflow-source' => ['"allowed_sources":[]', '"allowed_sources":[9223372036854775808]'],
        ] as $name => [$search, $replacement]) {
            $cases[$name] = str_replace($search, $replacement, $raw);
        }
        try {
            foreach ($cases as $name => $contents) {
                file_put_contents($temporary.'/deployment/upgrade-plan.json', $contents);
                $process = new Process(['python3', $temporary.'/deployment/generate-upgrade-plan.py', '--check']);
                $this->assertSame(1, $process->run(), $name.': '.$process->getOutput().$process->getErrorOutput());
                $this->assertStringContainsString('Upgrade plan validation failed:', $process->getErrorOutput(), $name);
                $this->assertSame($contents, file_get_contents($temporary.'/deployment/upgrade-plan.json'), $name);
            }
            foreach (['maintenance', 'online'] as $strategy) {
                $plan = $baseline;
                if ($strategy === 'online') {
                    $plan['strategy'] = 'online';
                    $plan['allowed_sources'] = [17];
                    $plan['compatibility'] = array_fill_keys(array_keys($plan['compatibility']), true);
                    $plan['steps'][0]['online'] = true;
                    $plan['migrations'][0]['online'] = true;
                }
                file_put_contents($temporary.'/deployment/upgrade-plan.json', $encode($plan));
                $process = new Process(['python3', $temporary.'/deployment/generate-upgrade-plan.py', '--check']);
                $this->assertSame(0, $process->run(), $strategy.': '.$process->getErrorOutput());
            }
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}
