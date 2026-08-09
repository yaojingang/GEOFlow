<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OpenSourceReleaseScriptsTest extends TestCase
{
    public function test_secret_gate_requires_a_token_boundary_before_openai_style_keys(): void
    {
        $script = (string) file_get_contents(base_path('bin/git/check-open-source-release.sh'));

        $this->assertStringContainsString('(^|[^A-Za-z0-9])sk-', $script);
        $this->assertStringNotContainsString('"*backup*"', $script);
        $this->assertStringNotContainsString("':!*.md'", $script);
        $this->assertStringContainsString("'database/seeders/data/**'", $script);
    }

    public function test_release_sync_preserves_git_and_project_skills_while_excluding_runtime_artifacts(): void
    {
        $script = (string) file_get_contents(base_path('bin/git/prepare-open-source-release.sh'));

        foreach (['.git', 'vendor/***', 'node_modules/***', 'public/build/***', '.env*'] as $excludedPath) {
            $this->assertStringContainsString("--exclude='{$excludedPath}'", $script);
        }

        $this->assertStringNotContainsString("--exclude='.agents", $script);
        $this->assertStringNotContainsString("--exclude='.claude", $script);
        $this->assertStringContainsString('git -C "$PROJECT_ROOT" diff --cached --quiet', $script);
        $this->assertStringContainsString('git -C "$TARGET_ROOT" diff --cached --quiet', $script);
        $this->assertStringContainsString('Refusing to sync while the release source has local changes.', $script);
        $this->assertStringContainsString('Refusing to sync into a target with local changes.', $script);
    }

    public function test_release_sync_refuses_a_staged_only_target_without_changing_it(): void
    {
        [$root, $source, $target] = $this->releaseRepositories();

        File::put($target.'/protected.txt', "staged target content\n");
        $this->runProcess(['git', '-C', $target, 'add', 'protected.txt']);

        $process = $this->runProcess(
            ['sh', $source.'/bin/git/prepare-open-source-release.sh', $target],
            $root,
            false,
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('target with local changes', $process->getOutput().$process->getErrorOutput());
        $this->assertSame("staged target content\n", File::get($target.'/protected.txt'));
        $this->assertStringContainsString('staged target content', $this->runProcess([
            'git', '-C', $target, 'diff', '--cached', '--', 'protected.txt',
        ])->getOutput());
    }

    public function test_release_sync_checks_the_source_repository_independently_of_the_calling_directory(): void
    {
        [$root, $source, $target] = $this->releaseRepositories();

        File::put($source.'/source.txt', "staged source content\n");
        $this->runProcess(['git', '-C', $source, 'add', 'source.txt']);

        $process = $this->runProcess(
            ['sh', $source.'/bin/git/prepare-open-source-release.sh', $target],
            $root,
            false,
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('release source has local changes', $process->getOutput().$process->getErrorOutput());
        $this->assertFalse(File::exists($target.'/source.txt'));
    }

    public function test_release_sync_refuses_an_unrelated_target_repository_without_changing_it(): void
    {
        [$root, $source, $target] = $this->releaseRepositories();
        $this->runProcess([
            'git', '-C', $target, 'remote', 'set-url', 'origin', 'https://github.com/example/unrelated.git',
        ]);

        $process = $this->runProcess(
            ['sh', $source.'/bin/git/prepare-open-source-release.sh', $target],
            $root,
            false,
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('not the official GEOFlow public remote', $process->getOutput().$process->getErrorOutput());
        $this->assertSame("original target content\n", File::get($target.'/protected.txt'));
    }

    public function test_release_sync_refreshes_a_clean_official_target_and_preserves_git_state(): void
    {
        [$root, $source, $target] = $this->releaseRepositories();

        $process = $this->runProcess(
            ['sh', $source.'/bin/git/prepare-open-source-release.sh', $target],
            $root,
        );

        $this->assertTrue($process->isSuccessful());
        $this->assertSame("original source content\n", File::get($target.'/source.txt'));
        $this->assertFalse(File::exists($target.'/protected.txt'));
        $this->assertTrue(File::exists($target.'/.git'));
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow.git',
            trim($this->runProcess(['git', '-C', $target, 'remote', 'get-url', 'origin'])->getOutput()),
        );
    }

    /** @return array{string, string, string} */
    private function releaseRepositories(): array
    {
        $root = sys_get_temp_dir().'/geoflow-release-script-'.str()->uuid();
        $source = $root.'/source';
        $target = $root.'/target';
        File::ensureDirectoryExists($source.'/bin/git');
        File::ensureDirectoryExists($target);
        File::copy(
            base_path('bin/git/prepare-open-source-release.sh'),
            $source.'/bin/git/prepare-open-source-release.sh',
        );
        File::put($source.'/source.txt', "original source content\n");
        File::put($target.'/protected.txt', "original target content\n");

        foreach ([$source, $target] as $repository) {
            $this->runProcess(['git', '-C', $repository, 'init', '--quiet']);
            $this->runProcess(['git', '-C', $repository, 'config', 'user.email', 'release-test@example.com']);
            $this->runProcess(['git', '-C', $repository, 'config', 'user.name', 'Release Test']);
            $this->runProcess(['git', '-C', $repository, 'add', '-A']);
            $this->runProcess(['git', '-C', $repository, 'commit', '--quiet', '-m', 'fixture']);
        }
        $this->runProcess([
            'git', '-C', $target, 'remote', 'add', 'origin', 'https://github.com/yaojingang/GEOFlow.git',
        ]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        return [$root, $source, $target];
    }

    /** @param list<string> $command */
    private function runProcess(array $command, ?string $workingDirectory = null, bool $mustSucceed = true): Process
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(20);
        $process->run();

        if ($mustSucceed) {
            $this->assertTrue(
                $process->isSuccessful(),
                $process->getOutput().$process->getErrorOutput(),
            );
        }

        return $process;
    }
}
