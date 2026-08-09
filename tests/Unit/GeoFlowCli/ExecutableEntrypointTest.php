<?php

namespace Tests\Unit\GeoFlowCli;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExecutableEntrypointTest extends TestCase
{
    #[Test]
    public function executable_version_smoke_works_when_curl_functions_are_unavailable(): void
    {
        $path = base_path('bin/geoflow');
        $this->assertTrue(is_executable($path));
        $process = new Process([
            PHP_BINARY,
            '-d',
            'disable_functions=curl_init,curl_exec,curl_setopt,curl_setopt_array,curl_close',
            $path,
            '--version',
        ]);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([
            'name' => 'geoflow',
            'version' => '0.2.0',
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('', $process->getErrorOutput());
    }

    #[Test]
    public function invalid_boolean_values_fail_visibly_before_quiet_is_applied(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('bin/geoflow'),
            '--quiet=false',
            '--version',
        ]);

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('--quiet', $process->getErrorOutput());
    }
}
