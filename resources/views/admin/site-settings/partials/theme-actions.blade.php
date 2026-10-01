<div class="flex flex-wrap items-center gap-2">
    @if($canManageProtectedWorkflows)
        <a href="{{ route('admin.site-settings.themes.preview', ['themeId' => $item['preview_id']]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 active:scale-[.98]">
            <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>{{ __('theme_library.preview') }}
        </a>
    @endif
    @if(!$item['active'] && ($item['source'] !== 'installed' || $canManageProtectedWorkflows))
        <details class="group" data-theme-activation>
            <summary class="inline-flex min-h-9 cursor-pointer list-none items-center rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 active:scale-[.98] [&::-webkit-details-marker]:hidden">{{ __('theme_library.activate') }}</summary>
            <form method="POST" action="{{ route('admin.site-settings.theme') }}" class="mt-2 space-y-3 rounded-md border border-blue-100 bg-blue-50 p-3">
                @csrf
                <input type="hidden" name="active_theme" value="{{ $item['id'] }}">
                <input type="hidden" name="appearance_revision" value="{{ $appearanceRevision }}">
                <p class="text-sm text-gray-800">{{ __('theme_library.activate_confirm', ['current' => $themeLibrary['current']['display_name'], 'name' => $item['display_name']]) }}</p>
                <button type="submit" class="min-h-9 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 active:scale-[.98]">{{ __('theme_library.activate_submit') }}</button>
                <button type="button" data-theme-cancel-activation class="min-h-9 rounded-md px-3 py-1.5 text-sm text-gray-700 hover:bg-blue-100 active:scale-[.98]">{{ __('theme_library.cancel') }}</button>
            </form>
        </details>
    @endif
    @if($showHomepageAction ?? false)
        <a href="{{ route('admin.site-settings.homepage-modules.edit') }}" class="inline-flex min-h-9 items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 active:scale-[.98]">{{ __('theme_library.homepage') }}</a>
    @endif
    <details class="relative ml-auto">
        <summary class="inline-flex min-h-9 cursor-pointer list-none items-center rounded-md px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100 active:scale-[.98] [&::-webkit-details-marker]:hidden" aria-label="{{ __('theme_library.more') }} · {{ $item['display_name'] }}"><i data-lucide="ellipsis" class="h-5 w-5" aria-hidden="true"></i></summary>
        <div class="absolute right-0 z-20 mt-1 w-64 max-w-[75vw] space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
            <p class="text-xs font-medium text-gray-500">{{ __('theme_library.id') }}</p>
            <p class="break-all text-xs text-gray-700">{{ $item['id'] ?: __('theme_library.selections.general.name') }}</p>
            @if($canManageProtectedWorkflows && $item['can_export'])
                <form method="POST" action="{{ route('admin.site-settings.theme-packages.exports.store') }}">
                    @csrf
                    <input type="hidden" name="theme_id" value="{{ $item['id'] }}">
                    <button type="submit" class="min-h-9 w-full rounded-md px-2 py-1.5 text-left text-sm font-medium text-blue-700 hover:bg-blue-50 active:scale-[.98]">{{ __('theme_library.export') }}</button>
                </form>
            @endif
            @if($item['id'] === '')<p class="text-xs text-gray-500">{{ __('theme_library.always_available') }}</p>@endif
        </div>
    </details>
</div>
