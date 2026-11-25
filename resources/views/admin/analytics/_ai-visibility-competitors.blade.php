@php
    $aiVisibilityCompetitorReport = $competitorReport ?? null;
    $aiVisibilityCompetitors = $competitors ?? collect();
@endphp

<section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.competitors.panel_title') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.competitors.panel_desc') }}</p>
        </div>
        @if (auth('admin')->user()?->isSuperAdmin())
        <form method="POST" action="{{ route('admin.analytics.ai-visibility.competitors.detect') }}" class="shrink-0">
            @csrf
            <button type="submit" class="inline-flex min-h-9 items-center rounded-md border border-violet-300 bg-white px-3 text-xs font-semibold text-violet-700 hover:bg-violet-50">
                <i data-lucide="sparkles" class="mr-1.5 h-3.5 w-3.5"></i>{{ __('admin.analytics.ai_visibility.detect_button') }}
            </button>
        </form>
        @endif
    </div>

    @if ($aiVisibilityCompetitorReport === null)
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_data') }}</p>
    @elseif (($aiVisibilityCompetitorReport['total_samples'] ?? 0) === 0)
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_data') }}</p>
    @elseif (($aiVisibilityCompetitorReport['competitors'] ?? []) === [])
        <p class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.no_competitors') }}</p>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.table_name') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.table_mentions') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.table_rate') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.competitors.table_keywords') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($aiVisibilityCompetitorReport['competitors'] as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5">
                                <span class="font-medium text-gray-900">{{ $row['name'] }}</span>
                                @if (! empty($row['aliases']))
                                    <span class="ml-1 text-xs text-gray-400">{{ implode(' / ', $row['aliases']) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums {{ $row['mentions'] > 0 ? 'font-semibold text-violet-700' : 'text-gray-400' }}">{{ $row['mentions'] }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-gray-100 sm:block" role="presentation">
                                        <div class="h-full rounded-full {{ $row['mention_rate'] >= 66 ? 'bg-rose-500' : ($row['mention_rate'] >= 33 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min(100, $row['mention_rate']) }}%"></div>
                                    </div>
                                    <span class="font-mono text-xs tabular-nums text-gray-600">{{ $row['samples_mentioned'] }}/{{ $aiVisibilityCompetitorReport['total_samples'] }}（{{ $row['mention_rate'] }}%）</span>
                                </div>
                            </td>
                            <td class="max-w-md px-4 py-2.5 text-xs text-gray-600">
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
        <p class="mt-2 text-xs text-gray-400">{{ __('admin.analytics.ai_visibility.competitors.sample_note', ['samples' => $aiVisibilityCompetitorReport['total_samples']]) }}</p>
    @endif

    @if (auth('admin')->user()?->isSuperAdmin())
    <details class="mt-4 rounded-md border border-gray-200">
        <summary class="flex min-h-10 cursor-pointer items-center px-4 text-sm font-semibold text-gray-700">{{ __('admin.analytics.ai_visibility.competitors.manage_toggle') }}</summary>
        <div class="border-t border-gray-100 p-4">
            <form method="POST" action="{{ route('admin.analytics.ai-visibility.competitors.store') }}" class="grid grid-cols-1 items-end gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]">
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

            @if ($aiVisibilityCompetitors->isEmpty())
                <p class="mt-4 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.competitors.no_competitors') }}</p>
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
        </div>
    </details>
    @endif
</section>
