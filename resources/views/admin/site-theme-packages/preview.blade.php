@extends('admin.site-theme-packages.base')
@section('intro', __('admin.theme_packages.preview.intro'))
@section('package-content')
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white" data-theme-preview-shell data-frame-base="{{ $frameBase }}">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 bg-gray-50 p-4"><h2 class="break-words text-base font-semibold text-gray-900">{{ $theme['name'] }}</h2><span class="text-xs font-medium text-blue-700">{{ __('admin.theme_packages.preview.badge') }}</span></div>
        <nav class="flex flex-wrap gap-1 border-b border-gray-200 p-3" aria-label="{{ __('admin.theme_packages.preview.pages') }}">@foreach($pages as $page => $path)<a href="{{ route('admin.site-settings.theme-packages.preview', ['themeId' => $theme['id'], 'page' => $page]) }}" class="inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm {{ $selectedPage === $page ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100' }}" @if($selectedPage === $page) aria-current="page" @endif>{{ __('admin.theme_packages.preview.page.'.$page) }}</a>@endforeach</nav>
        @if($frameUrl)
            <iframe data-theme-preview-frame src="{{ $frameUrl }}" title="{{ __('admin.theme_packages.preview.title') }}" sandbox="allow-scripts allow-popups" referrerpolicy="no-referrer" class="block h-[75vh] min-h-[480px] w-full border-0"></iframe>
        @else
            <div class="p-8 text-sm leading-6 text-gray-600">{{ __('admin.theme_packages.preview.no_content') }}</div>
        @endif
    </section>
    <script src="{{ asset('js/site-theme-preview.js') }}" defer></script>
@endsection
