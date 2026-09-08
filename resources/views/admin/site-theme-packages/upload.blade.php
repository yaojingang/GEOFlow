@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.upload_intro'))
@section('package-content')
    <ol class="flex flex-wrap gap-x-8 gap-y-3 text-sm" aria-label="{{ __('admin.theme_packages.steps') }}"><li aria-current="step" class="font-semibold text-blue-700">1 · {{ __('admin.theme_packages.step_upload') }}</li><li class="text-gray-500">2 · {{ __('admin.theme_packages.step_inspect') }}</li><li class="text-gray-500">3 · {{ __('admin.theme_packages.step_install') }}</li></ol>
    <form method="POST" action="{{ route('admin.site-settings.theme-packages.imports.store') }}" enctype="multipart/form-data" class="space-y-6 rounded-lg border border-gray-200 bg-white p-5 sm:p-7">
        @csrf
        <div><label for="theme-package-file" class="mb-3 block text-base font-semibold text-gray-900">{{ __('admin.theme_packages.choose_file') }}</label><input id="theme-package-file" name="package_file" type="file" accept=".zip,application/zip" required aria-describedby="package-file-help" class="block min-h-11 w-full max-w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-3 file:font-medium file:text-gray-800"><p id="package-file-help" class="mt-3 text-sm leading-6 text-gray-500">{{ __('admin.theme_packages.upload_limit', ['size' => $maxMegabytes]) }}</p></div>
        <p class="border-t border-gray-200 pt-5 text-sm leading-6 text-gray-600">{{ __('admin.theme_packages.upload_boundary') }}</p>
        <button class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 active:scale-[.98]" type="submit"><i data-lucide="scan-line" class="h-4 w-4" aria-hidden="true"></i>{{ __('admin.theme_packages.inspect_button') }}</button>
    </form>
@endsection
