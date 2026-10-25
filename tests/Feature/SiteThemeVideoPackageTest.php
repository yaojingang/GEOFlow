<?php

namespace Tests\Feature;

use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\InstalledSiteThemeRepository;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class SiteThemeVideoPackageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_video_has_its_own_file_limit_and_invalid_video_is_rejected(): void
    {
        $guard = app(SiteThemePackageGuard::class);
        $this->assertSame(25 * 1024 * 1024, $guard->fileLimit('public/themes/fixture-theme/clip.mp4'));
        $this->assertSame(5 * 1024 * 1024, $guard->fileLimit('public/themes/fixture-theme/theme.js'));

        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/clip.mp4'] = 'not a video';
        $this->expectExceptionMessage(__('admin.theme_packages.error.invalid_package'));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 1);
    }

    public function test_valid_video_imports_and_is_served_with_video_mime(): void
    {
        $probe = new Process(['ffprobe', '-version']);
        $encoder = new Process(['ffmpeg', '-version']);
        try {
            $probe->run();
            $encoder->run();
        } catch (\Throwable) {
            $this->markTestSkipped('ffmpeg and ffprobe are required for video package integration.');
        }
        if (! $probe->isSuccessful() || ! $encoder->isSuccessful()) {
            $this->markTestSkipped('ffmpeg and ffprobe are required for video package integration.');
        }

        $path = Storage::disk('local')->path('clip.mp4');
        $process = new Process(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=16x16:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-an', '-y', $path]);
        $process->mustRun();
        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/clip.mp4'] = file_get_contents($path);

        $service = app(SiteThemePackageService::class);
        $inspection = $service->inspect(ThemePackageFixture::archive($files), 1);
        $service->install(1, $inspection['token'], true);
        $asset = app(InstalledSiteThemeRepository::class)->asset('fixture-theme', 'clip.mp4');
        $this->assertSame('video/mp4', $asset['mime']);
        $this->assertSame($files['public/themes/fixture-theme/clip.mp4'], file_get_contents($asset['path']));
        $this->get('/themes/fixture-theme/clip.mp4', ['Range' => 'bytes=0-1'])
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_video_component_has_no_video_source_before_click(): void
    {
        $html = Blade::render('<x-site.video src="/themes/fixture-theme/clip.mp4" poster="/poster.webp" />');
        $this->assertStringContainsString('data-site-video-src="/themes/fixture-theme/clip.mp4"', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*\ssrc=/', $html);
    }
}
