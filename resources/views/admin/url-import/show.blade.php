@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0 space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.url-import') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.url_import.section.progress') }}</h1>
                    <p class="mt-1 text-sm text-gray-600 break-all">{{ $job->url }}</p>
                </div>
            </div>
            <a href="{{ route('admin.url-import.history') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                {{ __('admin.url_import.button.view_history') }}
            </a>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('admin.url_import.section.stage_status') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.url_import.progress.waiting') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
                        {{ __('admin.url_import_history.status.' . $job->status) }}
                    </span>
                </div>
            </div>
            <div class="p-6">
                <div class="flex items-center justify-between text-sm text-gray-500">
                    <span>{{ __('admin.url_import.progress.waiting_short') }}</span>
                    <span>{{ (int) $job->progress_percent }}%</span>
                </div>
                <div class="mt-2 h-2 rounded-full bg-gray-200">
                    <div class="h-2 rounded-full bg-blue-600" style="width: {{ max(0, min(100, (int) $job->progress_percent)) }}%"></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.url_import.section.logs') }}</h3>
                </div>
                <div class="p-6 space-y-3">
                    @forelse ($logs as $log)
                        <div class="rounded-md border border-gray-200 p-3">
                            <div class="text-xs text-gray-500">{{ optional($log->created_at)->format('Y-m-d H:i:s') }} · {{ $log->level }}</div>
                            <div class="mt-1 text-sm text-gray-700">{{ $log->message }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('admin.materials.url_import_waiting_logs') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.url_import.section.result_preview') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.url_import.section.result_preview_desc') }}</p>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <div class="text-sm font-medium text-gray-700">{{ __('admin.url_import.preview.summary') }}</div>
                        <p class="mt-2 text-sm text-gray-500">{{ $result['summary'] ?? __('admin.url_import.preview.empty_summary') }}</p>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-700">{{ __('admin.url_import.preview.knowledge') }}</div>
                        <p class="mt-2 text-sm text-gray-500">{{ $result['knowledge'] ?? __('admin.url_import.preview.empty_knowledge') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
