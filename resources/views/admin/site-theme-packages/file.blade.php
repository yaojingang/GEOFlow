@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.file_view_intro'))
@section('package-content')
    <a class="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-blue-700 underline hover:text-blue-900" href="{{ route('admin.site-settings.theme-packages.imports.show', ['token' => $token]) }}">{{ __('admin.theme_packages.back_to_report') }}</a>
    <section class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="space-y-2 border-b border-gray-200 bg-gray-50 p-5">
            <h2 class="break-all font-mono text-sm font-semibold text-gray-900">{{ $file['path'] }}</h2>
            <p class="text-xs text-gray-500">{{ number_format($file['bytes']) }} {{ __('admin.theme_packages.bytes') }}</p>
            <p class="break-all font-mono text-xs text-gray-500">SHA-256: {{ $file['sha256'] }}</p>
        </div>
        @if($source !== null)
            <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-all p-5 font-mono text-xs leading-6 text-gray-800" tabindex="0" aria-label="{{ __('admin.theme_packages.file_view_title') }}"><code>{{ $source }}</code></pre>
        @elseif($image !== null)
            <div class="p-5"><img src="{{ $image }}" alt="{{ $file['path'] }}" class="mx-auto block h-auto max-h-[70vh] max-w-full object-contain"></div>
        @else
            <p class="p-5 text-sm leading-6 text-gray-600">{{ __('admin.theme_packages.file_no_preview') }}</p>
        @endif
    </section>
@endsection
