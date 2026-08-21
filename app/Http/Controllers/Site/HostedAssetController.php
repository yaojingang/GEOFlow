<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class HostedAssetController extends Controller
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteThemeCatalog $themes,
    ) {}

    public function __invoke(Request $request, string $assetPath = 'favicon.ico'): BinaryFileResponse
    {
        $assetPath = rawurldecode($assetPath);
        abort_if($assetPath === '' || str_contains($assetPath, "\0") || str_contains($assetPath, '..'), 404);

        if ($this->currentSite->isHosted() && str_starts_with($assetPath, 'themes/')) {
            $themeId = explode('/', $assetPath, 3)[1] ?? '';
            $selectedTheme = (string) $this->currentSite->profile()?->channel?->template_key;
            abort_unless($themeId === $selectedTheme, 404);
            abort_unless(in_array($themeId, $this->themes->hostedCompatibleIds(), true), 404);
        }

        $storageAsset = str_starts_with($assetPath, 'storage/');
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

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
