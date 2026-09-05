@php
    $aiVisibilityScheduleRow = $aiVisibilitySchedule ?? null;
    $aiVisibilityScheduleEnabled = (bool) ($aiVisibilityScheduleRow->enabled ?? true);
    $aiVisibilityScheduleTimes = collect(json_decode($aiVisibilityScheduleRow->times_json ?? '["08:00","20:00"]', true) ?: ['08:00', '20:00']);
    $aiVisibilityScheduleFreq = $aiVisibilityScheduleTimes->count();
    if (! in_array($aiVisibilityScheduleFreq, [1, 2, 3, 4], true)) { $aiVisibilityScheduleFreq = 2; }
    $aiVisibilityScheduleKeywordIds = collect(json_decode($aiVisibilityScheduleRow->keyword_ids_json ?? '[]', true) ?: [])->map(fn ($v) => (int) $v)->all();
@endphp

<section class="mt-6 rounded-lg border border-violet-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.schedule.panel_title') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.schedule.panel_desc') }}</p>
        </div>
        <span class="inline-flex min-h-8 shrink-0 items-center rounded-full px-3 text-xs font-semibold {{ $aiVisibilityScheduleEnabled ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
            {{ $aiVisibilityScheduleEnabled ? __('admin.analytics.ai_visibility.schedule.status_enabled') : __('admin.analytics.ai_visibility.schedule.status_disabled') }}
        </span>
    </div>

    @if (($aiVisibilityScheduleRow->last_run_date ?? null) !== null)
        <p class="mt-2 text-xs text-gray-500">
            {{ __('admin.analytics.ai_visibility.schedule.last_run', [
                'time' => $aiVisibilityScheduleRow->last_run_slot,
                'date' => $aiVisibilityScheduleRow->last_run_date,
            ]) }}
        </p>
    @endif

    <form method="POST" action="{{ route('admin.analytics.ai-visibility.schedule') }}" class="mt-4 space-y-4">
        @csrf
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" name="enabled" value="1" @checked($aiVisibilityScheduleEnabled) class="h-4 w-4 rounded border-gray-300 text-violet-600">
                    {{ __('admin.analytics.ai_visibility.schedule.enabled_label') }}
                </label>
                <label for="ai-schedule-frequency" class="mb-1 mt-3 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.schedule.freq_label') }}</label>
                <select id="ai-schedule-frequency" name="frequency" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                    @foreach ([1, 2, 3, 4] as $freq)
                        <option value="{{ $freq }}" @selected($aiVisibilityScheduleFreq === $freq)>{{ __('admin.analytics.ai_visibility.schedule.freq'.$freq) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <p class="mb-1 text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.schedule.keywords_label') }}</p>
                @if (($keywordLibraries ?? collect())->isEmpty())
                    <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.collect.empty_library') }}</p>
                @else
                    <div class="max-h-40 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-3">
                        @foreach ($keywordLibraries as $library)
                            <details open>
                                <summary class="cursor-pointer text-sm font-semibold text-gray-800">{{ $library['name'] }}（{{ count($library['keywords']) }}）</summary>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($library['keywords'] as $item)
                                        <label class="inline-flex min-h-8 cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-2 text-sm text-gray-700 has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 has-[:checked]:text-violet-700">
                                            <input type="checkbox" name="keyword_ids[]" value="{{ $item['id'] }}" class="h-4 w-4 rounded border-gray-300 text-violet-600" @checked(in_array($item['id'], $aiVisibilityScheduleKeywordIds))>
                                            {{ $item['keyword'] }}
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ __('admin.analytics.ai_visibility.schedule.keywords_hint') }}</p>
                @endif
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700">{{ __('admin.analytics.ai_visibility.schedule.save') }}</button>
        </div>
        <p class="text-xs text-gray-400">{{ __('admin.analytics.ai_visibility.schedule.hourly_note') }}</p>
    </form>
</section>
