@extends('admin.layouts.app')

@section('content')
    @php($syncableChannels = $channels->filter(fn ($channel) => $channel->status === 'active' && $channel->channelType() === 'geoflow_agent')->values())
    @php($channelSyncSummaries = $channelSyncSummaries ?? [])

    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.page_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                <button type="button" data-selected-sync-open class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.distribution.button.sync_settings_selected') }}
                </button>
                <a href="{{ route('admin.distribution.sync-settings-all.preview') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="scan-search" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.distribution.button.sync_settings_all') }}
                </a>
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

        <div data-selected-sync-modal class="fixed inset-0 z-50 hidden overflow-y-auto px-4 py-6 sm:px-6 lg:px-8" aria-labelledby="selected-sync-title" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-gray-900/40" data-selected-sync-close></div>
            <div class="relative mx-auto max-w-4xl rounded-xl bg-white shadow-xl">
                <form method="POST" action="{{ route('admin.distribution.sync-settings-selected.preview') }}">
                    @csrf
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-5">
                        <div>
                            <h2 id="selected-sync-title" class="text-lg font-semibold text-gray-900">{{ __('admin.distribution.selected_sync.title') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.selected_sync.desc') }}</p>
                        </div>
                        <button type="button" data-selected-sync-close class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="{{ __('admin.button.close') }}">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>

                    <div class="px-6 py-5">
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div data-selected-sync-count data-count-template="{{ __('admin.distribution.selected_sync.selected_count', ['count' => '__COUNT__']) }}" class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
                                {{ __('admin.distribution.selected_sync.selected_count', ['count' => 0]) }}
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" data-selected-sync-select-all class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.distribution.selected_sync.select_all') }}</button>
                                <button type="button" data-selected-sync-clear class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.distribution.selected_sync.clear') }}</button>
                            </div>
                        </div>

                        @if ($syncableChannels->isEmpty())
                            <div class="rounded-lg border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500">
                                {{ __('admin.distribution.selected_sync.empty') }}
                            </div>
                        @else
                            <div class="max-h-[56vh] overflow-y-auto pr-1">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    @foreach ($syncableChannels as $channel)
                                        @php($syncSummary = $channelSyncSummaries[(int) $channel->id] ?? [])
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-300 hover:bg-blue-50/40">
                                            <input type="checkbox" name="channel_ids[]" value="{{ (int) $channel->id }}" data-selected-sync-checkbox class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-semibold text-gray-900">{{ $channel->name }}</span>
                                                <span class="mt-1 block truncate text-sm text-gray-500">{{ $channel->domain }}</span>
                                                <span class="mt-2 block text-xs leading-5 text-gray-600">
                                                    {{ $syncSummary['frontend_experience_mode'] ?? $channel->frontendExperienceMode() }} · {{ ($syncSummary['active_theme'] ?? '') !== '' ? $syncSummary['active_theme'] : '默认主题' }} · {{ $syncSummary['front_mode'] ?? $channel->frontMode() }}
                                                </span>
                                                <span class="mt-1 block text-xs leading-5 text-gray-500">
                                                    模块 {{ (int) ($syncSummary['homepage_modules_count'] ?? 0) }} · 轮播 {{ (int) ($syncSummary['home_carousel_slides_count'] ?? 0) }} · 文字广告 {{ (int) ($syncSummary['article_text_ads_count'] ?? 0) }}
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4">
                        <button type="button" data-selected-sync-close class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                        <button type="submit" data-selected-sync-submit @disabled($syncableChannels->isEmpty()) class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.distribution.button.sync_settings_selected_submit') }}
                        </button>
                    </div>
                </form>
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

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.querySelector('[data-selected-sync-modal]');
            const openButton = document.querySelector('[data-selected-sync-open]');
            const closeButtons = document.querySelectorAll('[data-selected-sync-close]');
            const checkboxes = document.querySelectorAll('[data-selected-sync-checkbox]');
            const submitButton = document.querySelector('[data-selected-sync-submit]');
            const countBadge = document.querySelector('[data-selected-sync-count]');
            const selectAllButton = document.querySelector('[data-selected-sync-select-all]');
            const clearButton = document.querySelector('[data-selected-sync-clear]');

            if (!modal || !openButton) {
                return;
            }

            const refreshSelectedCount = () => {
                const selectedCount = Array.from(checkboxes).filter((checkbox) => checkbox.checked).length;

                if (countBadge) {
                    const template = countBadge.dataset.countTemplate || '__COUNT__';
                    countBadge.textContent = template.replace('__COUNT__', selectedCount);
                }

                if (submitButton) {
                    submitButton.disabled = selectedCount === 0;
                }
            };

            openButton.addEventListener('click', () => {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                refreshSelectedCount();
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                });
            });

            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refreshSelectedCount));

            if (selectAllButton) {
                selectAllButton.addEventListener('click', () => {
                    checkboxes.forEach((checkbox) => {
                        checkbox.checked = true;
                    });
                    refreshSelectedCount();
                });
            }

            if (clearButton) {
                clearButton.addEventListener('click', () => {
                    checkboxes.forEach((checkbox) => {
                        checkbox.checked = false;
                    });
                    refreshSelectedCount();
                });
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    modal.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                }
            });

            refreshSelectedCount();
        });
    </script>
@endsection
