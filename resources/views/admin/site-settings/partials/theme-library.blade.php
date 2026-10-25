<div class="space-y-5" data-theme-library data-selected-label="{{ __('theme_library.selected', ['count' => ':count']) }}" data-archive-confirm="{{ __('theme_library.archive_confirm', ['count' => ':count']) }}" data-restore-confirm="{{ __('theme_library.restore_confirm', ['count' => ':count']) }}">
    <style>[data-theme-library] [hidden]{display:none!important}[data-theme-activation][open]{width:100%;order:3}</style>
    @php($currentTheme = $themeLibrary['current'])
    <section class="flex flex-col gap-4 rounded-lg border border-blue-200 bg-blue-50/40 p-4 sm:flex-row sm:items-center" data-theme-current>
        @if($currentTheme['thumbnail'])
            <img src="{{ $currentTheme['thumbnail'] }}" alt="" class="h-20 w-36 shrink-0 rounded-md border border-gray-200 object-cover object-top">
        @else
            <div class="hidden h-20 w-28 shrink-0 items-center justify-center rounded-md border border-blue-100 bg-white text-blue-600 sm:flex"><i data-lucide="layout-template" class="h-8 w-8" aria-hidden="true"></i></div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-xs font-medium text-gray-500">{{ __('theme_library.current') }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-2"><h4 class="text-base font-semibold text-gray-900">{{ $currentTheme['display_name'] }}</h4><span class="rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('theme_library.active') }}</span></div>
            <p class="mt-1 text-xs text-gray-500">{{ __('theme_library.'.$currentTheme['source']) }}@if($currentTheme['version']) · v{{ $currentTheme['version'] }}@endif</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('admin.site-settings.partials.theme-actions', ['item' => $currentTheme, 'showHomepageAction' => true])
        </div>
    </section>
    @foreach(session('theme_library_retained', []) as $retained)
        <p role="status" class="rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800">{{ __('theme_library.kept_active', ['name' => $retained]) }}</p>
    @endforeach
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ __('theme_library.intro') }}</p>
        @if($canManageProtectedWorkflows)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.site-settings.theme-packages.imports.create') }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 active:scale-[.98]"><i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>{{ __('admin.theme_packages.import_title') }}</a>
                <a href="{{ route('admin.site-settings.theme-replications.create') }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 active:scale-[.98]"><i data-lucide="copy-plus" class="h-4 w-4" aria-hidden="true"></i>{{ __('admin.theme_replication.button.start') }}</a>
            </div>
        @endif
    </div>
    <nav class="flex flex-wrap gap-1 border-b border-gray-200" aria-label="{{ __('admin.site_settings.theme.section_title') }}">
        @foreach(['featured', 'personal', 'archived'] as $tab)
            <a href="{{ route('admin.site-settings.index', ['theme_tab' => $tab]).'#site-settings-theme' }}" class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium {{ $themeLibrary['tab'] === $tab ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}" @if($themeLibrary['tab'] === $tab) aria-current="page" @endif>{{ __('theme_library.'.$tab) }}<span class="text-xs text-gray-500">{{ $themeLibrary['counts'][$tab] }}</span></a>
        @endforeach
    </nav>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" action="{{ route('admin.site-settings.index') }}#site-settings-theme" class="flex w-full flex-wrap gap-2 sm:w-auto sm:flex-1">
            <input type="hidden" name="theme_tab" value="{{ $themeLibrary['tab'] }}">
            <label class="relative min-w-0 flex-1 sm:max-w-sm"><span class="sr-only">{{ __('theme_library.search') }}</span><input type="search" name="theme_search" value="{{ $themeLibrary['search'] }}" placeholder="{{ __('theme_library.search_placeholder') }}" maxlength="200" class="min-h-10 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></label>
            @if($themeLibrary['tab'] !== 'featured')
                <select name="theme_source" aria-label="{{ __('theme_library.all_sources') }}" class="min-h-10 max-w-full rounded-md border border-gray-300 px-2 py-2 text-sm">
                    <option value="">{{ __('theme_library.all_sources') }}</option>
                    @foreach(['builtin', 'installed', 'private'] as $source)<option value="{{ $source }}" @selected($themeLibrary['source'] === $source)>{{ __('theme_library.'.$source) }}</option>@endforeach
                </select>
            @endif
            <button type="submit" class="min-h-10 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 active:scale-[.98]">{{ __('theme_library.search') }}</button>
            @if($themeLibrary['search'] !== '' || $themeLibrary['source'] !== '')<a href="{{ route('admin.site-settings.index', ['theme_tab' => $themeLibrary['tab']]).'#site-settings-theme' }}" class="inline-flex min-h-10 items-center px-2 text-sm text-blue-700">{{ __('theme_library.clear') }}</a>@endif
        </form>
        @if($canManageProtectedWorkflows)
            <button type="button" data-theme-batch-toggle aria-pressed="false" data-start-label="{{ __('theme_library.batch') }}" data-end-label="{{ __('theme_library.done') }}" class="min-h-10 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 active:scale-[.98]">{{ __('theme_library.batch') }}</button>
        @endif
    </div>
    @if($themeLibrary['tab'] === 'archived')<p class="text-xs text-gray-500">{{ __('theme_library.archived_hint') }}</p>@endif
    @if($canManageProtectedWorkflows)
        <form method="POST" action="{{ route('admin.site-settings.themes.library') }}" id="theme-library-batch" class="space-y-3 rounded-md border border-blue-100 bg-blue-50 p-3" data-theme-batch-panel hidden>
            @csrf
            <input type="hidden" name="library_action" value="{{ $themeLibrary['tab'] === 'archived' ? 'restore' : 'archive' }}">
            <input type="hidden" name="theme_tab" value="{{ $themeLibrary['tab'] }}"><input type="hidden" name="theme_search" value="{{ $themeLibrary['search'] }}"><input type="hidden" name="theme_source" value="{{ $themeLibrary['source'] }}">
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" data-theme-select-page class="rounded border-gray-300 text-blue-600">{{ __('theme_library.select_page') }}</label>
                <span data-theme-selected-count class="text-sm text-gray-600" aria-live="polite">{{ __('theme_library.selected', ['count' => 0]) }}</span>
                <button type="button" data-theme-batch-review disabled class="min-h-9 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-40 hover:bg-blue-700 active:scale-[.98]">{{ __('theme_library.'.($themeLibrary['tab'] === 'archived' ? 'restore' : 'archive')) }}</button>
            </div>
            <div data-theme-batch-confirm hidden><p data-theme-batch-confirm-text class="mb-2 text-sm text-gray-800"></p><button type="submit" class="min-h-9 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 active:scale-[.98]">{{ __('theme_library.confirm') }}</button><button type="button" data-theme-batch-cancel class="ml-2 min-h-9 rounded-md px-3 py-1.5 text-sm text-gray-700 hover:bg-blue-100 active:scale-[.98]">{{ __('theme_library.cancel') }}</button></div>
        </form>
    @endif
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-theme-library-grid>
        @forelse($themeLibrary['items'] as $theme)
            <article class="flex min-w-0 flex-col rounded-lg border {{ $theme['active'] ? 'border-blue-300' : 'border-gray-200' }} bg-white" data-theme-card="{{ $theme['id'] }}">
                <div class="relative h-32 overflow-hidden rounded-t-lg border-b border-gray-100 bg-gray-50">
                    @if($theme['thumbnail'])<img src="{{ $theme['thumbnail'] }}" alt="{{ $theme['display_name'] }}" loading="lazy" class="h-full w-full object-cover object-top">@else<div class="flex h-full items-center justify-center gap-3 text-gray-500"><i data-lucide="layout-template" class="h-7 w-7" aria-hidden="true"></i><span class="text-sm font-medium">{{ $theme['display_name'] }}</span></div>@endif
                    @if($canManageProtectedWorkflows)<label data-theme-batch-choice hidden class="absolute left-3 top-3 rounded bg-white p-1.5"><input type="checkbox" name="theme_ids[]" value="{{ $theme['id'] }}" form="theme-library-batch" data-theme-select aria-label="{{ __('theme_library.select', ['name' => $theme['display_name']]) }}" @disabled($theme['active'] || $theme['id'] === '') class="block rounded border-gray-300 text-blue-600"></label>@endif
                </div>
                <div class="flex flex-1 flex-col gap-2 p-3">
                    <div class="flex flex-wrap items-center gap-2"><h4 class="min-w-0 text-sm font-semibold text-gray-900">{{ $theme['display_name'] }}</h4>@if($theme['active'])<span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700">{{ __('theme_library.active') }}</span>@endif</div>
                    <p class="text-sm leading-5 text-gray-500">{{ $theme['display_description'] }}</p>
                    <p class="text-xs text-gray-500">{{ __('theme_library.'.($theme['private'] ? 'private' : $theme['source'])) }}@if($theme['version']) · v{{ $theme['version'] }}@endif</p>
                    <div class="mt-auto pt-1">@include('admin.site-settings.partials.theme-actions', ['item' => $theme])</div>
                    @if(count($theme['versions']) > 1)
                        <details class="border-t border-gray-100 pt-2"><summary class="cursor-pointer text-xs font-medium text-blue-700">{{ __('theme_library.versions', ['count' => count($theme['versions'])]) }}</summary>
                            <div class="mt-3 space-y-3">
                                @foreach($theme['versions'] as $version)
                                    @if($version['id'] !== $theme['id'])
                                        <div class="space-y-2 border-t border-gray-100 pt-2">
                                            <div class="flex items-start gap-2">
                                                @if($canManageProtectedWorkflows)
                                                    <span data-theme-batch-choice hidden>
                                                        <input type="checkbox" name="theme_ids[]" value="{{ $version['id'] }}" form="theme-library-batch" data-theme-select aria-label="{{ __('theme_library.select', ['name' => $version['name']]) }}" @disabled($version['active']) class="rounded border-gray-300 text-blue-600">
                                                    </span>
                                                @endif
                                                <p class="break-words text-xs text-gray-600">{{ $version['name'] }} · v{{ $version['version'] }}</p>
                                            </div>
                                            @include('admin.site-settings.partials.theme-actions', ['item' => $version])
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center"><h4 class="font-medium text-gray-800">{{ __('theme_library.empty') }}</h4><p class="mt-2 text-sm text-gray-500">{{ __('theme_library.empty_hint') }}</p><a href="{{ route('admin.site-settings.index', ['theme_tab' => $themeLibrary['tab']]).'#site-settings-theme' }}" class="mt-3 inline-block text-sm font-medium text-blue-700">{{ __('theme_library.clear') }}</a></div>
        @endforelse
    </div>
    @if($themeLibrary['items']->hasPages())<div>{{ $themeLibrary['items']->links() }}</div>@endif
    @if($canManageProtectedWorkflows && ($recentThemeReplications ?? collect())->isNotEmpty())
        <details class="border-t border-gray-200 pt-4"><summary class="cursor-pointer text-sm text-gray-600">{{ __('theme_library.recent_tasks') }} · {{ $recentThemeReplications->count() }}</summary><p class="mt-2 text-xs text-gray-500">{{ __('theme_library.replication_hint') }}</p><div class="mt-3 divide-y divide-gray-100">@foreach($recentThemeReplications as $replication)<a href="{{ route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]) }}" class="flex min-h-11 items-center justify-between gap-3 py-2 text-sm text-gray-700"><span>{{ $replication->name }}</span><span class="text-xs text-gray-500">{{ __('admin.theme_replication.status.'.$replication->status) }}</span></a>@endforeach</div></details>
    @endif
    <script src="{{ asset('js/site-theme-library.js') }}" defer></script>
</div>
