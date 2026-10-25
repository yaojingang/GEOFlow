@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.export_intro'))
@section('package-content')
    @include('admin.site-theme-packages.summary')
    <div class="space-y-4 rounded-lg border border-gray-200 bg-white p-5">
        <p class="text-sm leading-6 text-gray-600">{{ __('admin.theme_packages.export_boundary') }}</p>
        <div class="flex flex-wrap items-center gap-4"><a class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 active:scale-[.98]" href="{{ route('admin.site-settings.theme-packages.exports.download', ['token' => $export['token']]) }}"><i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>{{ __('admin.theme_packages.download') }}</a><span class="text-sm text-gray-500">{{ number_format($export['bytes'] / 1024 / 1024, 2) }} MiB · {{ __('admin.theme_packages.expires', ['time' => \Carbon\Carbon::parse($export['expires_at'])->format('Y-m-d H:i')]) }}</span></div>
    </div>
@endsection
