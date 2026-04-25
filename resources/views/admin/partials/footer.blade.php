@php
    $projectGithubUrl = 'https://github.com/yaojingang/GEOFlow';
    $xProfileUrl = 'https://x.com/yaojingang';
    $appVersion = (string) config('geoflow.app_version', '1.2.0');
    $changelogUrl = app()->getLocale() === 'en'
        ? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG_en.md'
        : 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md';
@endphp
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:flex-wrap justify-center items-center gap-3 md:gap-4 text-sm text-gray-500 text-center">
            <span>{{ __('admin.footer.copyright') }}</span>
            <span>|</span>
            <span>{{ __('admin.footer.version', ['version' => $appVersion]) }}</span>
            <span>|</span>
            <span class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
                {{ __('admin.footer.author') }}
                <a href="{{ $xProfileUrl }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">{{ __('admin.footer.author_x_profile') }}</a>
                <a href="{{ $projectGithubUrl }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">{{ __('admin.footer.project_github_link') }}</a>
                <a href="{{ $changelogUrl }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">{{ __('admin.footer.changelog_link') }}</a>
                <span>|</span>
                <button type="button" data-open-admin-welcome class="text-blue-600 hover:text-blue-700 underline-offset-2 hover:underline">
                    {{ __('admin.footer.project_intro_link') }}
                </button>
            </span>
        </div>
    </div>
</footer>
<script>
    window.ADMIN_BASE_PATH = @json('/'.\App\Support\AdminWeb::basePath());
    window.adminUrl = function (path) {
        const base = window.ADMIN_BASE_PATH || '';
        if (!path) return base + '/';
        return base + '/' + String(path).replace(/^\/+/, '');
    };
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
