<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Services\Admin\SystemUpdaterBootstrapService;
use App\Services\SystemUpdater\SystemUpdaterPlatform;
use App\Services\SystemUpdater\TufBootstrapVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SystemUpdaterBootstrapServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_it_downloads_and_stages_only_the_verified_platform_asset(): void
    {
        Storage::fake('local');
        $archive = 'verified updater archive';
        $digest = hash('sha256', $archive);
        $url = 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        $manifestUrl = 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json';
        config(['geoflow.updater_bootstrap_manifest_url' => $manifestUrl]);

        Http::preventStrayRequests();
        Http::fake([
            $manifestUrl => Http::response('{"signed":{},"signatures":[]}'),
            $url => Http::response($archive, 200, ['Content-Length' => (string) strlen($archive)]),
        ]);
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock) use ($digest, $url, $archive): void {
            $mock->shouldReceive('verify')->twice()->andReturn([
                'schema_version' => 1,
                'release_sequence' => 17,
                'updater_version' => '0.1.0',
                'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'assets' => [
                    'linux-amd64' => ['url' => $url, 'sha256' => $digest, 'size' => strlen($archive)],
                ],
            ]);
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->twice()->andReturn('linux-amd64');
        });

        $prepared = app(SystemUpdaterBootstrapService::class)->prepare();

        $this->assertSame('0.1.0', $prepared['version']);
        $this->assertSame('geoflow-updater_0.1.0_linux_amd64.tar.gz', $prepared['filename']);
        Storage::disk('local')->assertExists($prepared['path']);
        Storage::disk('local')->assertExists('system-updater/bootstrap/current.json');
        $this->assertSame($archive, Storage::disk('local')->get($prepared['path']));
        $this->assertSame($prepared['path'], app(SystemUpdaterBootstrapService::class)->download()['path']);
    }

    public function test_it_removes_a_download_when_the_archive_digest_is_wrong(): void
    {
        Storage::fake('local');
        $url = 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        Http::fake([
            '*' => Http::response('tampered archive'),
        ]);
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock) use ($url): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'schema_version' => 1,
                'release_sequence' => 17,
                'updater_version' => '0.1.0',
                'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'assets' => [
                    'linux-amd64' => ['url' => $url, 'sha256' => str_repeat('a', 64), 'size' => 16],
                ],
            ]);
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->once()->andReturn('linux-amd64');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity');

        try {
            app(SystemUpdaterBootstrapService::class)->prepare();
        } finally {
            Storage::disk('local')->assertMissing('system-updater/bootstrap/current.json');
            $this->assertSame([], Storage::disk('local')->allFiles('system-updater/bootstrap'));
        }
    }

    public function test_download_revalidates_the_prepared_archive(): void
    {
        Storage::fake('local');
        $path = 'system-updater/bootstrap/0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        $envelopePath = 'system-updater/bootstrap/0.1.0/bootstrap-manifest.json';
        Storage::disk('local')->put($path, 'changed');
        Storage::disk('local')->put($envelopePath, '{"signed":{},"signatures":[]}');
        Storage::disk('local')->put('system-updater/bootstrap/current.json', json_encode([
            'release_sequence' => 17,
            'version' => '0.1.0',
            'filename' => basename($path),
            'path' => $path,
            'envelope_path' => $envelopePath,
            'sha256' => str_repeat('0', 64),
            'size' => 7,
            'platform' => 'linux-amd64',
            'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'prepared_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'schema_version' => 1,
                'release_sequence' => 17,
                'updater_version' => '0.1.0',
                'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'assets' => [
                    'linux-amd64' => [
                        'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                        'sha256' => str_repeat('0', 64),
                        'size' => 7,
                    ],
                ],
            ]);
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->once()->andReturn('linux-amd64');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer valid');

        app(SystemUpdaterBootstrapService::class)->download();
    }

    public function test_download_rejects_a_symlinked_prepared_archive(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $relativePath = 'system-updater/bootstrap/0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        $envelopePath = 'system-updater/bootstrap/0.1.0/bootstrap-manifest.json';
        $outside = $disk->path('outside.tar.gz');
        $disk->put('outside.tar.gz', 'verified archive');
        $disk->put($envelopePath, '{"signed":{},"signatures":[]}');

        $directory = dirname($disk->path($relativePath));
        symlink($outside, $disk->path($relativePath));
        $disk->put('system-updater/bootstrap/current.json', json_encode([
            'release_sequence' => 17,
            'version' => '0.1.0',
            'filename' => basename($relativePath),
            'path' => $relativePath,
            'envelope_path' => $envelopePath,
            'sha256' => hash('sha256', 'verified archive'),
            'size' => strlen('verified archive'),
            'platform' => 'linux-amd64',
            'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'prepared_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'schema_version' => 1,
                'release_sequence' => 17,
                'updater_version' => '0.1.0',
                'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'assets' => [
                    'linux-amd64' => [
                        'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                        'sha256' => hash('sha256', 'verified archive'),
                        'size' => strlen('verified archive'),
                    ],
                ],
            ]);
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->once()->andReturn('linux-amd64');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer valid');

        app(SystemUpdaterBootstrapService::class)->download();
    }

    public function test_it_rejects_a_manifest_redirect_outside_the_official_github_release_service(): void
    {
        Storage::fake('local');
        $manifestUrl = 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json';
        Http::fake([
            $manifestUrl => Http::response('', 302, ['Location' => 'https://example.test/bootstrap-manifest.json']),
        ]);
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('verify');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('official GitHub release service');

        app(SystemUpdaterBootstrapService::class)->prepare();
    }

    public function test_download_reverifies_the_signed_envelope_even_when_state_and_archive_are_forged_together(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $path = 'system-updater/bootstrap/0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        $envelopePath = 'system-updater/bootstrap/0.1.0/bootstrap-manifest.json';
        $forged = 'forged archive';
        $disk->put($path, $forged);
        $disk->put($envelopePath, '{"signed":{"forged":true},"signatures":[]}');
        $disk->put('system-updater/bootstrap/current.json', json_encode([
            'release_sequence' => 17,
            'version' => '0.1.0',
            'filename' => basename($path),
            'path' => $path,
            'envelope_path' => $envelopePath,
            'sha256' => hash('sha256', $forged),
            'size' => strlen($forged),
            'platform' => 'linux-amd64',
            'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'prepared_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'schema_version' => 1,
                'release_sequence' => 17,
                'updater_version' => '0.1.0',
                'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'assets' => [
                    'linux-amd64' => [
                        'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                        'sha256' => hash('sha256', 'official archive'),
                        'size' => strlen('official archive'),
                    ],
                ],
            ]);
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->once()->andReturn('linux-amd64');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer valid');

        app(SystemUpdaterBootstrapService::class)->download();
    }

    public function test_prepare_rejects_a_signed_release_below_the_highest_accepted_sequence(): void
    {
        Storage::fake('local');
        $manifestUrl = 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json';
        $url = 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.2.0/geoflow-updater_0.2.0_linux_amd64.tar.gz';
        $archive = 'sequence seventeen archive';
        Http::fake([
            $manifestUrl => Http::response('{"signed":{},"signatures":[]}'),
            $url => Http::response($archive, 200, ['Content-Length' => (string) strlen($archive)]),
        ]);
        $this->mock(TufBootstrapVerifier::class, function (MockInterface $mock) use ($archive, $url): void {
            $mock->shouldReceive('verify')->twice()->andReturn(
                [
                    'schema_version' => 1,
                    'release_sequence' => 17,
                    'updater_version' => '0.2.0',
                    'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'assets' => [
                        'linux-amd64' => ['url' => $url, 'sha256' => hash('sha256', $archive), 'size' => strlen($archive)],
                    ],
                ],
                [
                    'schema_version' => 1,
                    'release_sequence' => 16,
                    'updater_version' => '0.1.0',
                    'expires' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'assets' => [
                        'linux-amd64' => [
                            'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                            'sha256' => str_repeat('a', 64),
                            'size' => 1,
                        ],
                    ],
                ],
            );
        });
        $this->mock(SystemUpdaterPlatform::class, function (MockInterface $mock): void {
            $mock->shouldReceive('current')->once()->andReturn('linux-amd64');
        });

        app(SystemUpdaterBootstrapService::class)->prepare();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rollback');

        app(SystemUpdaterBootstrapService::class)->prepare();
    }
}
