@php
    $aiVisibilityLibraries = $keywordLibraries ?? collect();
    $aiVisibilityCompetitorReport = $competitorReport ?? null;
    $aiVisibilityCompetitors = $competitors ?? collect();
@endphp

<section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.collect.panel_title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.collect.panel_desc') }}</p>

    @if ($aiVisibilityLibraries->isEmpty())
        <p class="mt-3 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.collect.empty_library') }}</p>
    @else
        <form method="POST" action="{{ route('admin.analytics.ai-visibility.collect') }}" class="mt-4 space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach ($aiVisibilityLibraries as $library)
                    <div class="rounded-md border border-gray-200">
                        <div class="flex items-center justify-between px-4 py-2">
                            <span class="text-sm font-semibold text-gray-800">{{ $library['name'] }}（{{ count($library['keywords']) }}）</span>
                            <button type="button" class="text-xs font-medium text-violet-600 hover:text-violet-700" data-ai-visibility-select-all="ai-visibility-library-{{ $library['id'] }}">{{ __('admin.analytics.ai_visibility.collect.select_all') }}</button>
                        </div>
                        <div id="ai-visibility-library-{{ $library['id'] }}" class="flex flex-wrap gap-2 border-t border-gray-100 px-4 py-3">
                            @foreach ($library['keywords'] as $item)
                                <label class="inline-flex min-h-8 cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-2 text-sm text-gray-700 has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 has-[:checked]:text-violet-700">
                                    <input type="checkbox" name="keyword_ids[]" value="{{ $item['id'] }}" class="h-4 w-4 rounded border-gray-300 text-violet-600">
                                    {{ $item['keyword'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-violet-700 active:scale-[.98] motion-reduce:transition-none motion-reduce:active:scale-100">
                    <i data-lucide="radar" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.ai_visibility.collect.submit') }}
                </button>
            </div>
        </form>
    @endif
</section>

<section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.competitors.panel_title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.panel_desc') }}</p>

    <form method="POST" action="{{ route('admin.analytics.ai-visibility.competitors.store') }}" class="mt-4 grid grid-cols-1 items-end gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]">
        @csrf
        <div>
            <label for="ai-competitor-name" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.competitors.name') }}</label>
            <input id="ai-competitor-name" type="text" name="name" required maxlength="120" placeholder="{{ __('admin.analytics.ai_visibility.competitors.name_ph') }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
        </div>
        <div>
            <label for="ai-competitor-aliases" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.competitors.aliases') }}</label>
            <input id="ai-competitor-aliases" type="text" name="aliases" maxlength="500" placeholder="{{ __('admin.analytics.ai_visibility.competitors.aliases_ph') }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
        </div>
        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700">{{ __('admin.analytics.ai_visibility.competitors.add') }}</button>
    </form>

    <form method="POST" action="{{ route('admin.analytics.ai-visibility.competitors.detect') }}" class="mt-3">
        @csrf
        <button type="submit" class="inline-flex min-h-9 items-center rounded-md border border-violet-300 bg-white px-3 text-xs font-semibold text-violet-700 hover:bg-violet-50">
            <i data-lucide="sparkles" class="mr-1.5 h-3.5 w-3.5"></i>{{ __('admin.analytics.ai_visibility.detect_button') }}
        </button>
    </form>

    @if ($aiVisibilityCompetitors->isEmpty())
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_competitors') }}</p>
    @else
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($aiVisibilityCompetitors as $competitor)
                <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-sm text-gray-700">
                    {{ $competitor->name }}
                    @if ($competitor->source === 'auto')
                        <span class="rounded-full bg-violet-100 px-1.5 text-[10px] font-semibold text-violet-700">AI</span>
                    @endif
                    <form method="POST" action="{{ route('admin.analytics.ai-visibility.competitors.destroy', $competitor->id) }}" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-gray-400 transition hover:text-red-600" aria-label="{{ __('admin.analytics.ai_visibility.competitors.delete') }} {{ $competitor->name }}">&times;</button>
                    </form>
                </span>
            @endforeach
        </div>
    @endif

    @if ($aiVisibilityCompetitorReport === null)
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_data') }}</p>
    @elseif (($aiVisibilityCompetitorReport['total_samples'] ?? 0) === 0)
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_data') }}</p>
    @elseif (($aiVisibilityCompetitorReport['competitors'] ?? []) === [])
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_competitors') }}</p>
    @else
        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.competitors.table_name') }}</th>
                        <th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.competitors.table_samples') }}</th>
                        <th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.competitors.table_mentions') }}</th>
                        <th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.competitors.table_rate') }}</th>
                        <th class="px-3 py-2">{{ __('admin.analytics.ai_visibility.competitors.table_keywords') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($aiVisibilityCompetitorReport['competitors'] as $row)
                        <tr class="text-gray-800">
                            <td class="px-3 py-2 font-medium">
                                {{ $row['name'] }}@if (! empty($row['aliases']))<span class="ml-1 text-xs text-gray-400">{{ implode(' / ', $row['aliases']) }}</span>@endif
                            </td>
                            <td class="px-3 py-2">{{ $aiVisibilityCompetitorReport['total_samples'] }}</td>
                            <td class="px-3 py-2 font-semibold {{ $row['mentions'] > 0 ? 'text-violet-700' : 'text-gray-400' }}">{{ $row['mentions'] }}</td>
                            <td class="px-3 py-2">{{ $row['samples_mentioned'] }}/{{ $aiVisibilityCompetitorReport['total_samples'] }}（{{ $row['mention_rate'] }}%）</td>
                            <td class="px-3 py-2">
                                @if ($row['keywords'] === [])
                                    <span class="text-gray-400">—</span>
                                @else
                                    {{ implode('、', $row['keywords']) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<script>
    document.querySelectorAll('[data-ai-visibility-select-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var container = document.getElementById(button.getAttribute('data-ai-visibility-select-all'));
            if (! container) { return; }
            var boxes = container.querySelectorAll('input[type="checkbox"]');
            var allChecked = Array.prototype.every.call(boxes, function (box) { return box.checked; });
            boxes.forEach(function (box) { box.checked = ! allChecked; });
        });
    });
</script>

@php
    $aiVisibilityTopUrls = $topCitedUrls ?? collect();
@endphp

<section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.top_urls.panel_title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.top_urls.panel_desc') }}</p>

    @if ($aiVisibilityTopUrls->isEmpty())
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.top_urls.no_data') }}</p>
    @else
        <div class="mt-4 space-y-2">
            @foreach ($aiVisibilityTopUrls as $index => $item)
                <div class="flex items-start justify-between gap-3 rounded-md border border-gray-100 px-4 py-2.5">
                    <div class="min-w-0">
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="block truncate text-sm font-medium text-violet-700 hover:text-violet-900 hover:underline" title="{{ $item['title'] }}">
                            {{ $index + 1 }}. {{ $item['title'] }}
                        </a>
                        <p class="mt-0.5 truncate text-xs text-gray-400">{{ $item['domain'] }}</p>
                    </div>
                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 shrink-0 items-center rounded-md border border-gray-200 px-2 text-xs font-medium text-gray-600 hover:border-violet-300 hover:text-violet-700">
                        {{ __('admin.analytics.ai_visibility.top_urls.visit') }}
                        <i data-lucide="external-link" class="ml-1 h-3 w-3"></i>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</section>
