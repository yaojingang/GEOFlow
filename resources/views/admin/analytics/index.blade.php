@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.analytics.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-500">{{ __('admin.analytics.last_updated', ['time' => now()->format('Y-m-d H:i:s')]) }}</span>
                    <button type="button" onclick="location.reload()" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-700 shadow-sm hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="mr-1 h-4 w-4"></i>
                        {{ __('admin.analytics.refresh') }}
                    </button>
                </div>
            </div>
        </div>

        @include('admin.analytics._filters', ['filters' => $filters, 'filterOptions' => $filterOptions])
        @include('admin.analytics._global-overview', ['globalOverview' => $globalOverview])
        @include('admin.analytics._single-site-section')
        @include('admin.analytics._distribution-section')
        @include('admin.analytics._log-section', ['logSummary' => $logSummary])
    </div>
@endsection
