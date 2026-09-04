<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PhpExceptionLoggingConfigurationTest extends TestCase
{
    public function test_container_php_configuration_omits_exception_arguments_and_preserves_diagnostics(): void
    {
        $probe = <<<'PHP'
        function failWithCredential(string $credential): never {
            throw new RuntimeException('protected operation failed');
        }
        $credential = 'fixture-credential-604827';
        try {
            failWithCredential($credential);
        } catch (RuntimeException $exception) {
            $log = (string) $exception;
            echo json_encode([
                'credential_present' => str_contains($log, $credential),
                'reason_present' => str_contains($log, 'protected operation failed'),
                'stack_present' => str_contains($log, 'failWithCredential('),
            ], JSON_THROW_ON_ERROR);
        }
        PHP;

        $unprotected = $this->runProbe(['-n', '-d', 'zend.exception_ignore_args=0'], $probe);
        $this->assertSame([
            'credential_present' => true,
            'reason_present' => true,
            'stack_present' => true,
        ], $unprotected);

        $protected = $this->runProbe(['-c', dirname(__DIR__, 2).'/docker/php/php-docker-overrides.ini'], $probe);
        $this->assertSame([
            'credential_present' => false,
            'reason_present' => true,
            'stack_present' => true,
        ], $protected);
    }

    private function runProbe(array $settings, string $probe): array
    {
        $process = new Process(
            [PHP_BINARY, ...$settings, '-d', 'zend.exception_string_param_max_len=128', '-r', $probe],
            env: ['PHP_INI_SCAN_DIR' => ''],
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
