@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6" data-theme-packages-page>
        <a class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-600 hover:text-gray-900" href="{{ route('admin.site-settings.index') }}#site-settings-theme"><i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>{{ __('admin.theme_packages.back') }}</a>
        <header class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">GEOFlow / {{ __('admin.theme_packages.workspace') }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-950 sm:text-3xl">{{ $pageTitle }}</h1>
            <p class="max-w-3xl text-sm leading-6 text-gray-600">@yield('intro')</p>
        </header>
        @if($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('package-content')
    </div>
@endsection
