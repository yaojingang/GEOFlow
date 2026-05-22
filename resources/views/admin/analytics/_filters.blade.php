@php
    $filterData = $filters->toArray();
    $presetOptions = ['today', 'yesterday', '7d', '30d', '90d', 'custom'];
    $trafficOptions = ['all', 'human', 'search_bot', 'ai_bot', 'other_bot', 'unknown'];
    $logSourceOptions = ['all', 'local', 'server', 'channel'];
    $today = now()->startOfDay();
    $presetRanges = [
        'today' => [$today->toDateString(), $today->toDateString()],
        'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
        '7d' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
        '30d' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
        '90d' => [$today->copy()->subDays(89)->toDateString(), $today->toDateString()],
        'custom' => [$filterData['date_from'], $filterData['date_to']],
    ];
@endphp

<section class="mb-8 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
    <div class="mb-5 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.analytics.filters.title') }}</h2>
        <a href="{{ route('admin.analytics') }}" class="text-sm font-medium text-gray-500 hover:text-blue-600">{{ __('admin.analytics.filters.reset') }}</a>
    </div>
    <form id="analytics-filter-form" method="GET" action="{{ route('admin.analytics') }}" class="space-y-5">
        <input type="hidden" name="preset" value="{{ $filterData['preset'] }}">
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.preset') }}</label>
            <div class="flex flex-wrap gap-2">
                @foreach ($presetOptions as $preset)
                    @php
                        $presetClass = $filterData['preset'] === $preset
                            ? 'border-blue-600 bg-blue-50 text-blue-700'
                            : 'border-gray-200 text-gray-600 hover:border-blue-200 hover:bg-blue-50';
                        [$presetFrom, $presetTo] = $presetRanges[$preset];
                    @endphp
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-2 text-sm font-medium transition {{ $presetClass }}"
                        data-preset="{{ $preset }}"
                        data-date-from="{{ $presetFrom }}"
                        data-date-to="{{ $presetTo }}"
                        data-analytics-preset-button
                        aria-pressed="{{ $filterData['preset'] === $preset ? 'true' : 'false' }}"
                    >
                        {{ __('admin.analytics.filters.'.$preset) }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_from') }}</label>
                <input type="date" name="date_from" value="{{ $filterData['date_from'] }}" data-analytics-custom-date class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_to') }}</label>
                <input type="date" name="date_to" value="{{ $filterData['date_to'] }}" data-analytics-custom-date class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.channel') }}</label>
                <select name="channel_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.analytics.filters.all') }}</option>
                    @foreach ($filterOptions['channels'] as $channel)
                        <option value="{{ $channel->id }}" @selected((int) $filterData['channel_id'] === (int) $channel->id)>{{ $channel->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.task') }}</label>
                <select name="task_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.analytics.filters.all') }}</option>
                    @foreach ($filterOptions['tasks'] as $task)
                        <option value="{{ $task->id }}" @selected((int) $filterData['task_id'] === (int) $task->id)>{{ $task->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.category') }}</label>
                <select name="category_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.analytics.filters.all') }}</option>
                    @foreach ($filterOptions['categories'] as $category)
                        <option value="{{ $category->id }}" @selected((int) $filterData['category_id'] === (int) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.article') }}</label>
                <select name="article_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.analytics.filters.all') }}</option>
                    @foreach ($filterOptions['articles'] as $article)
                        <option value="{{ $article->id }}" @selected((int) $filterData['article_id'] === (int) $article->id)>{{ $article->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.traffic_type') }}</label>
                <select name="traffic_type" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ($trafficOptions as $option)
                        <option value="{{ $option }}" @selected($filterData['traffic_type'] === $option)>{{ __('admin.analytics.filters.'.$option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.log_source') }}</label>
                <select name="log_source" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ($logSourceOptions as $option)
                        @php
                            $sourceDisabled = in_array($option, ['server', 'channel'], true);
                            $sourceLabel = __('admin.analytics.filters.'.($option === 'channel' ? 'channel_source' : $option));
                        @endphp
                        <option value="{{ $option }}" @selected($filterData['log_source'] === $option) @disabled($sourceDisabled)>
                            {{ $sourceDisabled ? __('admin.analytics.filters.source_pending', ['source' => $sourceLabel]) : $sourceLabel }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <i data-lucide="filter" class="mr-1.5 h-4 w-4"></i>
                {{ __('admin.analytics.filters.apply') }}
            </button>
        </div>
    </form>
</section>

@push('scripts')
    <script>
        (() => {
            const analyticsFilterForm = document.getElementById('analytics-filter-form');
            const analyticsPresetInput = analyticsFilterForm?.querySelector('input[name="preset"]');
            const analyticsDateFromInput = analyticsFilterForm?.querySelector('input[name="date_from"]');
            const analyticsDateToInput = analyticsFilterForm?.querySelector('input[name="date_to"]');
            const analyticsPresetButtons = document.querySelectorAll('[data-analytics-preset-button]');
            const activePresetClasses = ['border-blue-600', 'bg-blue-50', 'text-blue-700'];
            const inactivePresetClasses = ['border-gray-200', 'text-gray-600', 'hover:border-blue-200', 'hover:bg-blue-50'];

            const setPresetButtonState = (selectedPreset) => {
                analyticsPresetButtons.forEach((button) => {
                    const isActive = button.dataset.preset === selectedPreset;

                    button.classList.remove(...activePresetClasses, ...inactivePresetClasses);
                    button.classList.add(...(isActive ? activePresetClasses : inactivePresetClasses));
                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            };

            analyticsPresetButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    if (!analyticsPresetInput || !analyticsDateFromInput || !analyticsDateToInput) {
                        return;
                    }

                    const selectedPreset = button.dataset.preset || '7d';

                    analyticsPresetInput.value = selectedPreset;
                    analyticsDateFromInput.value = button.dataset.dateFrom || analyticsDateFromInput.value;
                    analyticsDateToInput.value = button.dataset.dateTo || analyticsDateToInput.value;
                    setPresetButtonState(selectedPreset);

                    if (selectedPreset === 'custom') {
                        analyticsDateFromInput.focus();
                    }
                });
            });

            document.querySelectorAll('[data-analytics-custom-date]').forEach((input) => {
                input.addEventListener('change', () => {
                    if (analyticsPresetInput) {
                        analyticsPresetInput.value = 'custom';
                    }
                    setPresetButtonState('custom');
                });
            });
        })();
    </script>
@endpush
