@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0 space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.url-import') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.url_import_history.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.url_import_history.page_subtitle') }}</p>
                </div>
            </div>
            <a href="{{ route('admin.url-import') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.url_import_history.button.new_job') }}
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            @foreach (['total', 'completed', 'running', 'failed'] as $statKey)
                <div class="bg-white shadow rounded-lg p-5">
                    <div class="text-sm text-gray-500">{{ __('admin.url_import_history.stats.' . $statKey) }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $stats[$statKey] }}</div>
                </div>
            @endforeach
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.url_import_history.section.records') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.status.label') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.url_import.section.progress') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_created') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($jobs as $job)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <a href="{{ route('admin.url-import.show', ['jobId' => $job->id]) }}" class="font-medium text-blue-600 hover:text-blue-800 break-all">{{ $job->url }}</a>
                                    @if ($job->source_domain)
                                        <div class="mt-1 text-xs text-gray-500">{{ $job->source_domain }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ __('admin.url_import_history.status.' . $job->status) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ (int) $job->progress_percent }}%</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ optional($job->created_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.url_import.progress.waiting') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $jobs->links() }}
            </div>
        </div>
    </div>
@endsection
