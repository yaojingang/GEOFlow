@extends('admin.layouts.app')

@php
    $filterData = $filters->toArray();
    $ai = $aiVisibilityOverview ?? [];
    $kpis = $ai['kpis'] ?? [];
    $polling = $ai['polling'] ?? [];
    $trend = $ai['trend'] ?? [];
    $definitionKeys = ['sampling', 'visibility', 'top1', 'top3', 'trend', 'sentiment', 'term_cloud', 'source', 'attention'];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.pages.ai_visibility.title'),
            'subtitle' => __('admin.analytics.pages.ai_visibility.subtitle'),
        ])

        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.analytics.ai-visibility') }}" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    @foreach (['14d', '30d', '60d', '90d'] as $preset)
                        <button type="submit" name="ai_preset" value="{{ $preset }}" class="inline-flex min-h-10 items-center rounded-md border px-3 text-sm font-semibold transition duration-[120ms] motion-reduce:transition-none active:scale-[.98] motion-reduce:active:scale-100 {{ $filters->preset === $preset ? 'border-violet-600 bg-violet-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-violet-300 hover:text-violet-700' }}" aria-pressed="{{ $filters->preset === $preset ? 'true' : 'false' }}">
                            {{ __('admin.analytics.filters.'.$preset) }}
                        </button>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label for="ai-date-from" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_from') }}</label>
                        <input id="ai-date-from" type="date" name="ai_date_from" value="{{ $filterData['ai_date_from'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="ai-date-to" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_to') }}</label>
                        <input id="ai-date-to" type="date" name="ai_date_to" value="{{ $filterData['ai_date_to'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="ai-keyword" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.keyword') }}</label>
                        <select id="ai-keyword" name="ai_keyword" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="">{{ __('admin.analytics.filters.all') }}</option>
                            @foreach ($filterOptions['keywords'] as $keyword)
                                <option value="{{ $keyword }}" @selected($filters->keyword === $keyword)>{{ $keyword }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="ai-provider" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.ai_visibility.provider') }}</label>
                        <select id="ai-provider" name="ai_provider" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="all">{{ __('admin.analytics.filters.all') }}</option>
                            @foreach ($filterOptions['providers'] as $provider)
                                <option value="{{ $provider }}" @selected($filters->provider === $provider)>{{ __('admin.analytics.ai_visibility.providers.'.$provider) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" name="ai_preset" value="custom" class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white transition duration-[120ms] motion-reduce:transition-none hover:bg-violet-700 active:scale-[.98] motion-reduce:active:scale-100">
                        <i data-lucide="filter" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.filters.apply') }}
                    </button>
                </div>
            </form>
        </section>

        @if (! ($ai['configured'] ?? false))
            <section class="rounded-lg border border-violet-200 bg-violet-50 p-6" data-ai-visibility-setup-entry>
                <h2 class="text-lg font-semibold text-violet-950">{{ __('admin.growth_center.ai_visibility.setup_entry_title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-violet-800">{{ __('admin.growth_center.ai_visibility.setup_entry_desc') }}</p>
                <a href="{{ route('admin.ai-source-providers.index') }}" class="mt-4 inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700">{{ __('admin.growth_center.ai_visibility.setup_entry_action') }}</a>
            </section>
        @elseif (! ($ai['ready'] ?? false))
            <section class="rounded-lg border border-gray-200 bg-white p-10 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.not_ready_title') }}</h2>
                <p class="mt-2 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.not_ready_desc') }}</p>
            </section>
        @else
            <section>
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600">{{ __('admin.growth_center.ai_visibility.eyebrow') }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.growth_center.ai_visibility.desc', ['count' => $ai['daily_sample_target'] ?? 5]) }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @foreach ([
                        ['key' => 'brand_visibility', 'label' => 'visibility', 'tone' => 'text-violet-700'],
                        ['key' => 'top1_rate', 'label' => 'top1', 'tone' => 'text-amber-700'],
                        ['key' => 'top3_rate', 'label' => 'top3', 'tone' => 'text-emerald-700'],
                        ['key' => 'sentiment_score', 'label' => 'sentiment', 'tone' => 'text-slate-700'],
                    ] as $card)
                        <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.'.$card['label']) }}</p>
                            <p class="mt-3 text-right font-mono text-3xl font-semibold tabular-nums {{ $card['tone'] }}">{{ number_format((float) ($kpis[$card['key']] ?? 0), 1) }}%</p>
                        </article>
                    @endforeach
                </div>

                <div class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.trend_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.polling_summary', ['sampled' => $polling['sampled_runs'] ?? 0, 'runs' => $polling['runs'] ?? 0, 'rate' => number_format((float) ($polling['success_rate'] ?? 0), 1)]) }}</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        @include('admin.analytics._interactive-trend', [
                            'series' => $trend,
                            'chartLabel' => __('admin.growth_center.ai_visibility.trend_title'),
                            'metrics' => [
                                ['key' => 'visibility', 'label' => __('admin.growth_center.ai_visibility.kpi.visibility'), 'color' => '#7c3aed', 'decimals' => 1, 'suffix' => '%'],
                                ['key' => 'top1', 'label' => __('admin.growth_center.ai_visibility.kpi.top1'), 'color' => '#d97706', 'decimals' => 1, 'suffix' => '%'],
                                ['key' => 'top3', 'label' => __('admin.growth_center.ai_visibility.kpi.top3'), 'color' => '#059669', 'decimals' => 1, 'suffix' => '%'],
                                ['key' => 'samples', 'label' => __('admin.growth_center.ai_visibility.table.samples'), 'color' => '#475569'],
                            ],
                        ])
                    </div>
                </div>

                <details class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm" data-ai-visibility-metric-definitions>
                    <summary class="flex min-h-10 cursor-pointer items-center px-5 py-3 text-sm font-semibold text-gray-800" data-ai-visibility-metric-toggle>{{ __('admin.growth_center.ai_visibility.definition_toggle') }}</summary>
                    <div class="border-t border-gray-100 p-5">
                        <p class="text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.definition_intro') }}</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ($definitionKeys as $key)
                                <article class="rounded-lg bg-gray-50 p-4" data-ai-visibility-definition-item>
                                    <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.definition.'.$key.'_title') }}</h4>
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.definition.'.$key.'_body', ['count' => $ai['daily_sample_target'] ?? 5]) }}</p>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </details>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.term_cloud_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.term_cloud_desc') }}</p>
                        <div class="mt-5 flex min-h-36 flex-wrap content-center items-center justify-center gap-x-4 gap-y-3">
                            @forelse (($ai['terms'] ?? []) as $term)
                                <span class="font-medium text-violet-700" style="font-size: {{ $term['size'] }}px">{{ $term['term'] }}</span>
                            @empty
                                <p class="text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_terms') }}</p>
                            @endforelse
                        </div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.attention_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.attention_desc') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse (($ai['attention_sources'] ?? []) as $source)
                                <article class="rounded-lg border border-amber-100 bg-amber-50 p-4">
                                    <div class="flex items-center justify-between gap-3"><strong class="truncate text-sm text-gray-900">{{ $source['domain'] }}</strong><span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-amber-700">{{ __('admin.growth_center.ai_visibility.action.'.$source['action']) }}</span></div>
                                    <p class="mt-2 text-sm leading-6 text-amber-900">{{ __('admin.growth_center.ai_visibility.recommendation.'.$source['action']) }}</p>
                                </article>
                            @empty
                                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_attention_sources') }}</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="p-5"><h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.keyword_title') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.keyword_desc') }}</p></div>
                        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50"><tr><th class="px-5 py-3 text-left">{{ __('admin.growth_center.ai_visibility.table.keyword') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.samples') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.visibility') }}</th><th class="px-5 py-3 text-right">{{ __('admin.growth_center.ai_visibility.table.top3') }}</th></tr></thead><tbody class="divide-y divide-gray-100">
                            @forelse (($ai['keywords'] ?? []) as $row)<tr><td class="px-5 py-3 font-medium text-gray-900">{{ $row['keyword'] }}</td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ $row['samples'] }}</td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ number_format($row['brand_visibility'], 1) }}%</td><td class="px-5 py-3 text-right font-mono tabular-nums">{{ number_format($row['top3_rate'], 1) }}%</td></tr>@empty<tr><td colspan="4" class="px-5 py-8 text-center text-gray-500">{{ __('admin.growth_center.ai_visibility.no_keywords') }}</td></tr>@endforelse
                        </tbody></table></div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.growth_center.ai_visibility.source_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.source_desc') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse (($ai['sources'] ?? []) as $source)
                                <article class="rounded-lg border border-gray-100 p-4"><div class="flex items-center justify-between gap-3"><strong class="truncate text-sm text-gray-900">{{ $source['domain'] }}</strong><span class="font-mono text-sm tabular-nums text-gray-600">{{ __('admin.growth_center.ai_visibility.source_mentions', ['count' => $source['mentions']]) }}</span></div><div class="mt-2 flex gap-4 text-xs text-gray-500"><span>{{ __('admin.growth_center.ai_visibility.source_avg_rank', ['rank' => $source['avg_rank']]) }}</span><span>{{ __('admin.growth_center.ai_visibility.source_brand_coverage', ['rate' => number_format($source['brand_coverage'], 1)]) }}</span></div></article>
                            @empty
                                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_sources') }}</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.recent_samples') }}</h3>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        @forelse (($ai['latest_runs'] ?? []) as $run)
                            <article class="rounded-lg border border-gray-100 p-4">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ $run['keyword'] }}</p>
                                <p class="mt-1 truncate text-xs text-gray-500">{{ __('admin.analytics.ai_visibility.providers.'.$run['provider_type']) }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2"><time class="font-mono text-xs tabular-nums text-gray-400">{{ $run['date'] }}</time><span class="rounded-full px-2 py-1 text-xs font-medium {{ $run['brand_visible'] ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $run['brand_visible'] ? __('admin.analytics.ai_visibility.visible') : __('admin.analytics.ai_visibility.not_visible') }}</span></div>
                            </article>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</p>
                        @endforelse
                    </div>
                </section>
            </section>
        @endif
    </div>
@endsection
