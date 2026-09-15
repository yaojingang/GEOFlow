<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Api\ThemeRevisionStorage;
use App\Support\Site\CurrentSite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ThemeRevisionAssetController extends Controller
{
    public function __invoke(Request $request, string $revision, string $path, ThemeRevisionStorage $storage): BinaryFileResponse
    {
        abort_if(app(CurrentSite::class)->isHosted(), 404);
        abort_unless(ThemeRelease::query()->where('revision_id', $revision)->exists(), 404);
        $model = ThemeRevision::query()->whereKey($revision)->where('state', 'ready')->firstOrFail();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(isset(SiteThemePackageGuard::ASSET_MIMES[$extension]), 404);
        $logical = 'public/themes/'.$model->theme_id.'/'.$path;
        $absolute = $storage->path($model, $logical);
        $bytes = $storage->read($absolute, 5 * 1024 * 1024);
        abort_unless(hash_equals($model->files[$logical]['sha256'], hash('sha256', $bytes)), 503);

        return response()->file($absolute, [
            'Content-Type' => SiteThemePackageGuard::ASSET_MIMES[$extension], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable', 'ETag' => '"'.$model->files[$logical]['sha256'].'"',
        ]);
    }
}
