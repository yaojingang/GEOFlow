@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.installed_intro'))
@section('package-content')
    <section class="space-y-5 rounded-lg border border-gray-200 bg-white p-6">
        <div class="flex items-start gap-4"><i data-lucide="circle-check" class="mt-1 h-6 w-6 shrink-0 text-emerald-700" aria-hidden="true"></i><div><h2 class="break-words text-xl font-semibold text-gray-900">{{ $theme['name'] }}</h2><p class="mt-2 text-sm text-gray-600">{{ $isActive ? __('admin.theme_packages.currently_active') : __('admin.theme_packages.not_active') }}</p></div></div>
        <div class="flex flex-wrap items-center gap-3"><a class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 active:scale-[.98]" href="{{ route('admin.site-settings.theme-packages.preview', ['themeId' => $theme['id']]) }}">{{ __('admin.theme_packages.preview.title') }}<i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i></a><a class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50" href="{{ route('admin.site-settings.index') }}#site-settings-theme">{{ __('admin.theme_packages.back') }}</a></div>
    </section>
@endsection
