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
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $channel->name }}</td>
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
            @if ($logs->isEmpty())
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
            @endif
        </div>
    </div>
@endsection
