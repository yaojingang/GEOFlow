@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.page_subtitle') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.distribution.jobs') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.distribution.button.jobs') }}
                </a>
                <a href="{{ route('admin.distribution.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.distribution.button.create') }}
                </a>
            </div>
        </div>

        @if (session('distribution_secret'))
            @php($secret = session('distribution_secret'))
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4">
                <div class="text-sm font-semibold text-amber-900">{{ __('admin.distribution.secret_notice_title') }}</div>
                <p class="mt-1 text-sm text-amber-800">{{ __('admin.distribution.secret_notice_desc') }}</p>
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.key_id') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['key_id'] ?? '' }}</code>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.secret') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['secret'] ?? '' }}</code>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-amber-700">{{ __('admin.distribution.field.endpoint_url') }}</div>
                        <code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-900">{{ $secret['endpoint_url'] ?? '' }}</code>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.distribution.stats.total') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['total'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.distribution.stats.active') }}</div>
                <div class="mt-2 text-2xl font-semibold text-green-700">{{ (int) ($stats['active'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.distribution.stats.pending') }}</div>
                <div class="mt-2 text-2xl font-semibold text-blue-700">{{ (int) ($stats['pending'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.distribution.stats.failed') }}</div>
                <div class="mt-2 text-2xl font-semibold text-red-700">{{ (int) ($stats['failed'] ?? 0) }}</div>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.channels_title') }}</h2>
            </div>
            @if ($channels->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">
                    <i data-lucide="radio-tower" class="mx-auto mb-3 h-10 w-10 text-gray-400"></i>
                    <div class="font-medium text-gray-900">{{ __('admin.distribution.empty_channels_title') }}</div>
                    <div class="mt-1">{{ __('admin.distribution.empty_channels_desc') }}</div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.domain') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.status') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.distribution.field.queue') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($channels as $channel)
                                @php($channelStatusKey = 'admin.distribution.status.'.(string) $channel->status)
                                @php($channelStatusLabel = trans()->has($channelStatusKey) ? __($channelStatusKey) : (string) $channel->status)
                                @php($channelTypeKey = 'admin.distribution.channel_type.'.$channel->channelType())
                                @php($channelTypeLabel = trans()->has($channelTypeKey) ? __($channelTypeKey) : $channel->channelType())
                                <tr>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="font-medium text-gray-900">{{ $channel->name }}</div>
                                        <div class="mt-1 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $channelTypeLabel }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $channel->domain }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $channel->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $channelStatusLabel }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        {{ __('admin.distribution.queue_summary', ['pending' => (int) $channel->pending_count, 'failed' => (int) $channel->failed_count]) }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex items-center gap-3">
                                            <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.button.view') }}</a>
                                            <a href="{{ route('admin.distribution.edit', ['channelId' => (int) $channel->id]) }}" class="text-gray-600 hover:text-gray-800">{{ __('admin.button.edit') }}</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.distribution.recent_logs_title') }}</h2>
            </div>
            @if ($logs->count() === 0)
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.distribution.empty_logs') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($logs as $log)
                        @php($logLevelKey = 'admin.distribution.log_level.'.(string) $log->level)
                        @php($logLevelLabel = trans()->has($logLevelKey) ? __($logLevelKey) : (string) $log->level)
                        <div class="px-6 py-4 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div class="font-medium text-gray-900">{{ $log->message }}</div>
                                <div class="shrink-0 text-xs text-gray-500">{{ $log->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-gray-500">
                                <span class="whitespace-nowrap">{{ $log->channel?->name ?? __('admin.common.none') }}</span>
                                <span class="whitespace-nowrap">{{ $logLevelLabel }}</span>
                                <span class="min-w-0 break-words">{{ __('admin.distribution.field.article') }}：{{ $log->article?->title ?? __('admin.common.none') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-gray-200 px-6 py-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="text-sm text-gray-500">
                            {{ __('admin.distribution.pagination.summary', [
                                'from' => $logs->firstItem(),
                                'to' => $logs->lastItem(),
                                'total' => $logs->total(),
                            ]) }}
                            {{ __('admin.distribution.pagination.pages', [
                                'page' => $logs->currentPage(),
                                'total_pages' => $logs->lastPage(),
                            ]) }}
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                            @if ($logs->hasPages())
                                <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('admin.distribution.recent_logs_title') }}">
                                    @if ($logs->onFirstPage())
                                        <span class="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-300">{{ __('admin.distribution.pagination.prev') }}</span>
                                    @else
                                        <a href="{{ $logs->previousPageUrl() }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.distribution.pagination.prev') }}</a>
                                    @endif

                                    @foreach ($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                                        @if ($page === $logs->currentPage())
                                            <span class="rounded-md border border-blue-600 bg-blue-600 px-3 py-2 text-sm font-medium text-white">{{ $page }}</span>
                                        @else
                                            <a href="{{ $url }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ $page }}</a>
                                        @endif
                                    @endforeach

                                    @if ($logs->hasMorePages())
                                        <a href="{{ $logs->nextPageUrl() }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.distribution.pagination.next') }}</a>
                                    @else
                                        <span class="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-300">{{ __('admin.distribution.pagination.next') }}</span>
                                    @endif
                                </nav>
                            @endif
                            <form method="GET" action="{{ route('admin.distribution.index') }}" class="flex items-center gap-2">
                                @foreach (request()->except('logs_page') as $key => $value)
                                    @if (is_scalar($value))
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach
                                <label for="distribution-logs-page" class="whitespace-nowrap text-sm text-gray-500">{{ __('admin.distribution.pagination.go_to') }}</label>
                                <input
                                    id="distribution-logs-page"
                                    name="logs_page"
                                    type="number"
                                    min="1"
                                    max="{{ $logs->lastPage() }}"
                                    value="{{ $logs->currentPage() }}"
                                    class="block w-20 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    {{ __('admin.button.jump') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
