@extends('admin.layouts.app')

@php
    $friendErrors = $errors->getBag('friend_links');
    $friendReloadRequired = $friendErrors->any() && session('friend_links_reload_required') === true;
    $friendDraft = $friendErrors->any() ? old('friend_links', []) : $friendLinkSnapshot['config'];
    $friendDraft = is_array($friendDraft) ? $friendDraft : [];
    $friendRows = is_array($friendDraft['links'] ?? null) ? $friendDraft['links'] : [];
    $friendValue = static fn ($value, $default = '') => is_scalar($value) ? (string) $value : $default;
    $friendRevision = $friendErrors->any()
        ? $friendValue($friendDraft['expected_revision'] ?? '') : $friendLinkSnapshot['revision'];
    $friendEnabledCount = collect($friendRows)
        ->filter(static fn ($link): bool => is_array($link) && filter_var($link['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))
        ->count();
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('friend_links.title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('friend_links.description') }}</p>
            </div>
            <a href="{{ route('admin.site-settings.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                {{ __('admin.site_settings.homepage.back_to_settings') }}
            </a>
        </div>

        <div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-3">
            <div class="overflow-hidden rounded-lg bg-white shadow">
                <div class="p-5">
                    <div class="flex items-center gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                            <i data-lucide="link-2" class="h-5 w-5" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-500">{{ __('friend_links.title') }}</p>
                            <p class="mt-1 text-2xl font-bold text-gray-900">{{ count($friendRows) }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ __('friend_links.count', ['total' => count($friendRows), 'enabled' => $friendEnabledCount]) }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg border border-blue-100 bg-blue-50 p-5 md:col-span-2">
                <div class="flex gap-3">
                    <i data-lucide="info" class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" aria-hidden="true"></i>
                    <div>
                        <h2 class="text-sm font-semibold text-blue-900">{{ __('friend_links.show') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('friend_links.limit') }} {{ __('friend_links.custom_theme') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <section class="overflow-hidden rounded-lg bg-white shadow" aria-labelledby="friend-links-editor-title">
            <div class="border-b border-gray-200 px-4 py-5 sm:px-6">
                <h2 id="friend-links-editor-title" class="text-lg font-semibold text-gray-900">{{ __('friend_links.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('friend_links.description') }}</p>
            </div>
            <div class="p-4 sm:p-6">
                <form method="POST" action="{{ route('admin.site-settings.friend-links.update') }}" id="friend-links-form" data-no-unsaved data-reload-required="{{ $friendReloadRequired ? '1' : '0' }}" class="mx-auto max-w-6xl space-y-6"
                      data-has-errors="{{ $friendErrors->any() ? '1' : '0' }}"
                      data-leave-message="{{ __('friend_links.leave') }}" data-reload-message="{{ __('friend_links.reload_confirm') }}"
                      data-saving-label="{{ __('friend_links.saving') }}">
            @csrf
            <input type="hidden" name="friend_links[expected_revision]" value="{{ $friendRevision }}">
            <input type="hidden" name="friend_links[link_count]" value="{{ $friendReloadRequired ? $friendValue($friendDraft['link_count'] ?? '') : count($friendRows) }}" data-link-count>
            @if($friendErrors->any())
                <div id="friend-links-errors" role="alert" tabindex="-1" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    <p class="font-semibold">{{ __('friend_links.errors') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach($friendErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                    @if($friendReloadRequired)
                        <p class="mt-3">{{ __('friend_links.incomplete_reload') }}</p>
                    @endif
                    @if($friendReloadRequired || $friendErrors->has('friend_links.expected_revision'))
                        <a href="{{ route('admin.site-settings.friend-links.edit') }}" data-friend-reload class="mt-3 inline-block font-semibold underline">{{ __('friend_links.reload') }}</a>
                    @endif
                </div>
            @endif
            @if(session('friend_links_saved'))
                <p role="status" class="rounded-md bg-green-50 p-3 text-sm text-green-800">{{ __('friend_links.saved') }}</p>
            @endif
            @if($friendLinkSnapshot['state'] === 'invalid')
                <div class="space-y-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p>{{ __('friend_links.invalid') }}</p>
                    <label class="block">{{ __('friend_links.raw') }}
                        <textarea readonly rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-xs">{{ $friendLinkSnapshot['raw'] ?? __('friend_links.null_value') }}</textarea>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="friend_links[replace_invalid]" value="1" @checked($friendValue($friendDraft['replace_invalid'] ?? '') === '1') class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        {{ __('friend_links.replace_invalid') }}
                    </label>
                </div>
            @endif
            <label class="flex min-h-10 items-center gap-2 text-sm font-medium text-gray-900">
                <input type="hidden" name="friend_links[enabled]" value="0">
                <input type="checkbox" name="friend_links[enabled]" value="1" @checked($friendValue($friendDraft['enabled'] ?? '1') === '1') class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                {{ __('friend_links.show') }}
            </label>
            <p class="text-sm leading-6 text-gray-500">{{ __('friend_links.limit') }} {{ __('friend_links.custom_theme') }}</p>
            @if($friendLinkInstalledTheme)
                <p class="text-sm text-amber-700">{{ __('friend_links.installed_theme') }}</p>
            @endif
            <div data-friend-rows class="space-y-5">
                @foreach($friendRows as $rowIndex => $friendRow)
                    @include('admin.site-settings.friend-link-row')
                @endforeach
            </div>
            <p data-friend-empty @if(count($friendRows)) hidden @endif class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">{{ __('friend_links.empty') }}</p>
                    <div class="flex flex-col-reverse gap-4 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" data-friend-add class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                                <i data-lucide="plus" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                                {{ __('friend_links.add') }}
                            </button>
                            <button type="button" data-friend-undo hidden class="min-h-10 rounded-md px-3 py-2 text-sm font-medium text-blue-700 underline focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">{{ __('friend_links.undo') }}</button>
                            <span data-friend-dirty hidden role="status" class="text-sm text-amber-700">{{ __('friend_links.dirty') }}</span>
                        </div>
                        <button type="submit" @disabled($friendReloadRequired) class="inline-flex min-h-10 items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                            <i data-lucide="save" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                            {{ __('friend_links.save') }}
                        </button>
                    </div>
                </form>
                <template id="friend-link-row-template">
                    @include('admin.site-settings.friend-link-row', ['rowIndex' => '__INDEX__', 'friendRow' => ['name' => '', 'url' => '', 'sort_order' => 0, 'enabled' => true, 'target' => '_blank', 'relationship' => 'regular']])
                </template>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-friend-links.js') }}" defer></script>
@endpush
