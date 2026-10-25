<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\CurrentSite;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class HostedAssetController extends Controller
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteThemeCatalog $themes,
        private readonly InstalledSiteThemeRepository $installed,
    ) {}

    public function __invoke(Request $request, string $assetPath = 'favicon.ico'): BinaryFileResponse
    {
        abort_if($assetPath === '' || str_contains($assetPath, "\0") || str_contains($assetPath, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $assetPath), 404);

        if ($this->currentSite->isHosted() && str_starts_with($assetPath, 'themes/')) {
            $themeId = explode('/', $assetPath, 3)[1] ?? '';
            $selectedTheme = (string) $this->currentSite->profile()?->channel?->template_key;
            abort_unless($themeId === $selectedTheme, 404);
            abort_unless(in_array($themeId, $this->themes->hostedCompatibleIds(), true), 404);
        }

        $storageAsset = str_starts_with($assetPath, 'storage/');
        if (! $this->currentSite->isHosted() && str_starts_with($assetPath, 'themes/')) {
            $parts = explode('/', $assetPath, 3);
            if (count($parts) === 3 && ! is_dir(public_path('themes/'.$parts[1])) && ! is_dir(resource_path('views/theme/'.$parts[1]))) {
                $asset = $this->installed->asset($parts[1], $parts[2]);
                abort_unless($asset !== null, 404);

                return response()->file($asset['path'], [
                    'Content-Type' => $asset['mime'],
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'public, max-age=300',
                    'ETag' => '"'.$asset['etag'].'"',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        $root = $storageAsset
            ? realpath(storage_path('app/public'))
            : realpath(public_path());
        $relativePath = $storageAsset ? substr($assetPath, strlen('storage/')) : $assetPath;
        $candidatePath = $storageAsset
            ? storage_path('app/public/'.$relativePath)
            : public_path($relativePath);
        $path = realpath($candidatePath);
        abort_unless(is_string($root) && is_string($path), 404);
        abort_unless(str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);
        abort_unless(is_file($path), 404);

        $headers = $storageAsset ? [] : ['Access-Control-Allow-Origin' => '*'];

        return response()->file($path, $headers + [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
