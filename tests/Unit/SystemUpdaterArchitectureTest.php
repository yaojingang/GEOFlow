<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemUpdaterArchitectureTest extends TestCase
{
    public function test_legacy_update_executor_services_are_absent(): void
    {
        foreach ([
            'SystemUpdateApplyService.php',
            'SystemUpdateArchiveValidator.php',
            'SystemUpdateBackupInspectionService.php',
            'SystemUpdateBackupService.php',
            'SystemUpdateDeploymentDetector.php',
            'SystemUpdateDeploymentDiagnosticsService.php',
            'SystemUpdatePathGuard.php',
            'SystemUpdatePlanService.php',
            'SystemUpdatePreflightService.php',
            'SystemUpdateRollbackService.php',
            'SystemUpdateRunHealthService.php',
            'SystemUpdateRunProgressService.php',
            'SystemUpdateVerificationService.php',
        ] as $file) {
            $this->assertFileDoesNotExist(app_path('Services/Admin/'.$file));
        }
    }

    public function test_agent_client_is_the_only_application_update_mutation_boundary(): void
    {
        $callers = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = $file->getContents();
            if (preg_match('/->start(?:Update|Backup|Rollback)\s*\(/', $source) === 1) {
                $callers[] = $file->getRealPath();
            }
        }

        $this->assertSame([
            realpath(app_path('Http/Controllers/Admin/SystemUpdaterOperationController.php')),
        ], $callers);
    }

    public function test_runtime_configuration_contains_no_legacy_update_worker(): void
    {
        foreach ([
            base_path('docker-compose.yml'),
            base_path('docker-compose.prod.yml'),
            base_path('docker-compose.prebuilt.yml'),
            config_path('horizon.php'),
        ] as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('geoflow-system-update-queue-prod', $contents, $file);
            $this->assertStringNotContainsString('supervisor-system-updates', $contents, $file);
        }
    }
}
