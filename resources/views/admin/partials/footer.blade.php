@php
    $projectGithubUrl = 'https://github.com/yaojingang/GEOFlow';
    $xProfileUrl = 'https://x.com/yaojingang';
    $appVersion = (string) config('geoflow.app_version', '0.0.0-dev');
    $releaseUrl = $projectGithubUrl.'/releases';
    $changelogUrl = app()->getLocale() === 'en'
        ? $projectGithubUrl.'/blob/main/docs/CHANGELOG_en.md'
        : $projectGithubUrl.'/blob/main/docs/CHANGELOG.md';
    $licenseUrl = $projectGithubUrl.'/blob/main/LICENSE';
    $helpDocsUrl = app()->getLocale() === 'en'
        ? 'https://github.com/yaojingang/GEOFlow/wiki/Home-English'
        : 'https://github.com/yaojingang/GEOFlow/wiki';
    $footerLinkClass = 'inline-flex min-h-10 touch-manipulation items-center rounded-sm text-gray-600 underline-offset-4 transition-[color,transform] duration-150 ease-out [@media(hover:hover)]:hover:text-blue-600 [@media(hover:hover)]:hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/30 active:scale-[0.96]';
@endphp
<footer class="mt-auto shrink-0 border-t border-gray-200 bg-gray-50 text-xs leading-5 text-gray-500" data-admin-product-footer>
    <div class="mx-auto flex min-h-[52px] max-w-7xl flex-col items-start justify-center gap-x-8 px-4 py-2 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-12">
        <div class="flex flex-wrap items-center gap-x-2" aria-label="GEOFlow release information">
            <a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">GEOFlow v{{ $appVersion }}</a>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <span>© 2026 Yao Jingang</span>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <a href="{{ $licenseUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">AGPL-3.0</a>
        </div>
        <nav class="flex flex-wrap items-center gap-x-2" aria-label="GEOFlow project resources">
            <a href="{{ $changelogUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">{{ __('admin.footer.changelog_link') }}</a>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <a href="{{ $projectGithubUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">{{ __('admin.footer.project_github_link') }}</a>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <a href="{{ $xProfileUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">{{ __('admin.footer.author_x_profile') }}</a>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <a href="{{ $helpDocsUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLinkClass }}">{{ __('admin.footer.help_docs_link') }}</a>
            <span class="text-gray-300" aria-hidden="true">·</span>
            <button type="button" data-open-admin-welcome class="border-0 bg-transparent p-0 {{ $footerLinkClass }}">
                {{ __('admin.footer.project_intro_link') }}
            </button>
        </div>
    </div>
</footer>
