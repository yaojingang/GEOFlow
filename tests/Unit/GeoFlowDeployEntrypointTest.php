<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GeoFlowDeployEntrypointTest extends TestCase
{
    public function test_entrypoint_forwards_arguments_and_requires_an_explicit_data_recovery_point(): void
    {
        $temporary = sys_get_temp_dir().'/geoflow-deploy-'.bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        file_put_contents($temporary.'/geoflow-updater', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
        chmod($temporary.'/geoflow-updater', 0700);
        try {
            foreach ([
                [['install', '--root', '/opt/site with spaces', '--url', 'https://geo.example'], ['install', '--root', '/opt/site with spaces', '--url', 'https://geo.example']],
                [['status', '--instance', 'primary'], ['doctor', '--instance', 'primary']],
                [['rollback', '--application', '--instance', 'primary'], ['switch-back', '--instance', 'primary']],
                [['rollback', '--data', '--recovery-point', 'point'], ['rollback', '--recovery-point', 'point']],
            ] as [$arguments, $expected]) {
                $process = new Process(['bash', base_path('scripts/geoflow-deploy.sh'), ...$arguments], null, ['PATH' => $temporary.':'.getenv('PATH')]);
                $process->mustRun();
                $this->assertSame(implode("\n", $expected)."\n", $process->getOutput());
            }
            $process = new Process(['bash', base_path('scripts/geoflow-deploy.sh'), 'rollback', '--data'], null, ['PATH' => $temporary.':'.getenv('PATH')]);
            $this->assertSame(2, $process->run());
            $this->assertSame('', $process->getOutput());
            $this->assertStringContainsString('explicit --recovery-point', $process->getErrorOutput());
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}
