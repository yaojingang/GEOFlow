@extends('admin.layouts.app')

@section('content')
    <section class="px-4 sm:px-0">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-2xl p-8 sm:p-10 text-center">
                <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-500">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z" />
                    </svg>
                </div>
                <p class="text-sm font-medium tracking-wide text-red-500 mb-2">404</p>
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 mb-3">
                    {{ __('admin.common.not_found_title') }}
                </h1>
                <p class="text-sm sm:text-base text-gray-600 leading-7 mb-8">
                    {{ __('admin.common.not_found_desc') }}
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="{{ route('admin.entry') }}"
                       class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg text-white bg-gray-900 hover:bg-black transition-colors">
                        {{ __('admin.nav.dashboard') }}
                    </a>
                    <button type="button"
                            onclick="window.history.back()"
                            class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        {{ __('admin.common.back') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
@endsection
