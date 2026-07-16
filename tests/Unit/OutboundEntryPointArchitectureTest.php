<?php

namespace Tests\Unit;

use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SecurePendingRequest;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundEntryPointArchitectureTest extends TestCase
{
    #[Test]
    public function the_application_and_http_facade_share_the_secure_factory_singleton(): void
    {
        $factory = app(HttpFactory::class);

        $this->assertInstanceOf(SecureHttpFactory::class, $factory);
        $this->assertSame($factory, app(HttpFactory::class));
        $this->assertSame($factory, Http::getFacadeRoot());
        $this->assertInstanceOf(SecurePendingRequest::class, $factory->createPendingRequest());
        $this->assertInstanceOf(SecurePendingRequest::class, Http::timeout(1));
        $this->assertInstanceOf(LaravelPinnedOutboundTransport::class, app(OutboundTransport::class));
    }

    #[Test]
    public function production_code_cannot_construct_or_install_an_unsecured_http_factory(): void
    {
        $provider = app_path('Providers/AppServiceProvider.php');

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();
            $source = $file->getContents();

            $this->assertDoesNotMatchRegularExpression(
                '/new\s+(?:PendingRequest|\\\\?Illuminate\\\\Http\\\\Client\\\\PendingRequest)\b/',
                $source,
                $path,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/new\s+(?:HttpFactory|\\\\?Illuminate\\\\Http\\\\Client\\\\Factory)\s*\(/',
                $source,
                $path,
            );
            $this->assertStringNotContainsString('Http::swap(', $source, $path);

            if ($path !== $provider) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?:bind|singleton|instance)\s*\(\s*HttpFactory::class/',
                    $source,
                    $path,
                );
            }
        }

        $providerSource = (string) file_get_contents($provider);
        $this->assertStringContainsString('singleton(HttpFactory::class', $providerSource);
        $this->assertStringContainsString('return new SecureHttpFactory(', $providerSource);
    }

    #[Test]
    public function every_production_http_facade_request_is_handed_to_the_safe_gateway(): void
    {
        $entryPoints = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getContents(), 'Http::')) {
                continue;
            }

            $entryPoints[] = $file->getRealPath();
            $this->assertStringContainsString('SafeOutbound', $file->getContents(), $file->getRealPath());
        }

        $this->assertNotEmpty($entryPoints);
    }

    #[Test]
    public function application_and_http_sdk_code_do_not_register_generic_http_middleware(): void
    {
        foreach ([app_path(), base_path('vendor/prism-php/prism/src')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/(?:->|::)\s*(?:withMiddleware|globalMiddleware)\s*\(/',
                    $file->getContents(),
                    $file->getRealPath(),
                );
            }
        }
    }

    #[Test]
    public function production_network_entry_points_do_not_send_outside_the_safe_gateway(): void
    {
        $files = [
            app_path('Services/GeoFlow/DistributionHttpClient.php'),
            app_path('Services/GeoFlow/UrlImportProcessingService.php'),
            app_path('Services/Admin/SiteThemeReplication/ThemeReferenceFetcher.php'),
            app_path('Http/Controllers/Admin/AiModelController.php'),
            app_path('Services/GeoFlow/KnowledgeChunkSyncService.php'),
            app_path('Services/Admin/AdminUpdateMetadataService.php'),
            app_path('Services/Admin/SystemUpdatePlanService.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Http::', $source, $file);
        }
    }

    #[Test]
    public function generated_target_package_cannot_download_arbitrary_remote_image_urls(): void
    {
        $source = (string) file_get_contents(app_path('Services/GeoFlow/DistributionTargetSitePackageBuilder.php'));

        $this->assertStringNotContainsString('file_get_contents($sourceUrl', $source);
        $this->assertStringNotContainsString("'follow_location' => 1", $source);
    }

    #[Test]
    public function production_environment_example_uses_the_unified_outbound_security_configuration(): void
    {
        $productionSource = (string) file_get_contents(base_path('.env.prod.example'));
        $developmentSource = (string) file_get_contents(base_path('.env.example'));
        $configSource = (string) file_get_contents(config_path('geoflow.php'));

        foreach ([
            'GEOFLOW_OUTBOUND_PRIVATE_TARGETS' => '',
            'GEOFLOW_OUTBOUND_JSON_MAX_BYTES' => '4194304',
            'GEOFLOW_OUTBOUND_AI_MAX_BYTES' => '8388608',
            'GEOFLOW_OUTBOUND_IMPORT_MAX_BYTES' => '5242880',
            'GEOFLOW_OUTBOUND_METADATA_MAX_BYTES' => '1048576',
        ] as $name => $value) {
            $setting = $name.'='.$value;
            $this->assertStringContainsString($setting, $productionSource);
            $this->assertStringContainsString($setting, $developmentSource);
            $this->assertStringContainsString("env('".$name."'", $configSource);
        }

        foreach ([
            'URL_IMPORT_ALLOW_MIXED_DNS',
            'GEOFLOW_HTTP_PROXY',
            'GEOFLOW_HTTPS_PROXY',
            'GEOFLOW_PROXY_HOSTS',
            'GEOFLOW_NO_PROXY',
        ] as $legacySetting) {
            $this->assertStringNotContainsString($legacySetting, $productionSource);
            $this->assertStringNotContainsString($legacySetting, $developmentSource);
            $this->assertStringNotContainsString($legacySetting, $configSource);
        }
    }
}
