<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiWorkspaceRuntimeArchitectureTest extends TestCase
{
    #[Test]
    public function runtime_configuration_is_read_only_through_the_runtime_status_boundary(): void
    {
        $runtimeStatusPath = realpath(app_path('Services/AiWorkspace/AiWorkspaceRuntimeStatus.php'));
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $runtimeStatusPath) {
                continue;
            }

            if (preg_match('/config\s*\(\s*[\'\"]ai-workspace\.(?:runtime_enabled|force_disabled)[\'\"]/', $file->getContents()) === 1) {
                $violations[] = $file->getRealPath();
            }
        }

        $this->assertSame([], $violations, 'AI Workspace runtime checks must use AiWorkspaceRuntimeStatus.');
    }
}
