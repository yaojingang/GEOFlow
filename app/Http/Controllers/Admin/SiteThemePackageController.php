<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportSiteThemePackageRequest;
use App\Http\Requests\Admin\InstallSiteThemePackageRequest;
use App\Http\Requests\Admin\UploadSiteThemePackageRequest;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteThemePackageController extends Controller
{
    public function __construct(private readonly SiteThemePackageService $packages) {}

    public function create(): View
    {
        $limits = [(int) config('geoflow.theme_packages.max_archive_bytes')];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $limit = ini_parse_quantity((string) ini_get($key));
            if ($limit > 0) {
                $limits[] = $limit;
            }
        }

        return view('admin.site-theme-packages.upload', [
            'pageTitle' => __('admin.theme_packages.import_title'),
            'activeMenu' => 'site_settings',
            'maxMegabytes' => round(min($limits) / 1024 / 1024, 1),
        ]);
    }

    public function export(ExportSiteThemePackageRequest $request): View
    {
        try {
            $export = $this->packages->export($request->validated('theme_id'), (int) $request->user('admin')->getAuthIdentifier());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['theme_id' => $exception->getMessage()]);
        }
        $this->audit($request, $export['package'], 'export');

        return view('admin.site-theme-packages.export', [
            'pageTitle' => __('admin.theme_packages.export_title'),
            'activeMenu' => 'site_settings',
            'export' => $export,
            'package' => $export['package'],
        ]);
    }

    public function download(Request $request, string $token): StreamedResponse
    {
        try {
            $export = $this->packages->download((int) $request->user('admin')->getAuthIdentifier(), $token);
        } catch (RuntimeException) {
            abort(404, __('admin.theme_packages.error.invalid_token'));
        }

        return response()->streamDownload(function () use ($request, $token): void {
            $this->packages->streamDownload((int) $request->user('admin')->getAuthIdentifier(), $token, function ($stream): void {
                fpassthru($stream);
            });
        }, $export['name'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(UploadSiteThemePackageRequest $request): RedirectResponse
    {
        try {
            $inspection = $this->packages->inspect($request->file('package_file'), (int) $request->user('admin')->getAuthIdentifier());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['package_file' => $exception->getMessage()]);
        }
        $this->audit($request, $inspection['package'], 'inspect');

        return redirect()->route('admin.site-settings.theme-packages.imports.show', ['token' => $inspection['token']]);
    }

    public function inspection(Request $request, string $token): View
    {
        try {
            $inspection = $this->packages->inspection((int) $request->user('admin')->getAuthIdentifier(), $token);
        } catch (RuntimeException) {
            abort(404, __('admin.theme_packages.error.invalid_token'));
        }

        return view('admin.site-theme-packages.inspection', [
            'pageTitle' => __('admin.theme_packages.inspect_title'),
            'activeMenu' => 'site_settings',
            'inspection' => $inspection,
            'package' => $inspection['package'],
        ]);
    }

    public function install(InstallSiteThemePackageRequest $request, string $token): View
    {
        try {
            $theme = $this->packages->install((int) $request->user('admin')->getAuthIdentifier(), $token, $request->boolean('trusted_source'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['package_file' => $exception->getMessage()]);
        }
        $this->audit($request, $theme['package'], 'install');

        return view('admin.site-theme-packages.installed', [
            'pageTitle' => __('admin.theme_packages.installed_title'),
            'activeMenu' => 'site_settings',
            'theme' => $theme,
            'isActive' => SiteSettingsBag::get('active_theme', '') === $theme['id'],
        ]);
    }

    public function file(Request $request, string $token, int $fileIndex): Response
    {
        try {
            $file = $this->packages->inspectionFile((int) $request->user('admin')->getAuthIdentifier(), $token, $fileIndex);
        } catch (RuntimeException) {
            abort(404, __('admin.theme_packages.error.invalid_token'));
        }
        $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
        $isText = in_array($extension, ['php', 'json', 'md', 'txt', 'css', 'js', 'svg'], true);
        $image = null;
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico'], true)) {
            $info = @getimagesizefromstring($file['contents']);
            if (is_array($info) && in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/vnd.microsoft.icon', 'image/x-icon'], true)) {
                $image = 'data:'.$info['mime'].';base64,'.base64_encode($file['contents']);
            }
        }

        return response()->view('admin.site-theme-packages.file', [
            'pageTitle' => __('admin.theme_packages.file_view_title'),
            'activeMenu' => 'site_settings',
            'file' => $file,
            'token' => $token,
            'source' => $isText ? $file['contents'] : null,
            'image' => $image,
        ], 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @param array<string,mixed> $package */
    private function audit(Request $request, array $package, string $action): void
    {
        $request = request();
        $request->attributes->set('admin_activity_action', $action);
        $request->attributes->set('admin_activity_details', [
            'theme_id' => (string) ($package['theme']['id'] ?? ''),
            'theme_version' => (string) ($package['theme']['version'] ?? ''),
            'package_sha256' => (string) ($package['content_sha256'] ?? ''),
            'file_count' => count($package['files'] ?? []),
        ]);
    }
}
