@extends('admin.layouts.app')

@php
    $growthStats = $growthOverview['stats'] ?? [];
    $recentSubmissions = $growthOverview['recent_submissions'] ?? collect();
    $sourceSummary = $growthOverview['source_summary'] ?? [];
    $aiVisibility = $aiVisibilityOverview ?? [];
    $aiVisibilityKpis = $aiVisibility['kpis'] ?? [];
    $aiVisibilityPolling = $aiVisibility['polling'] ?? [];
    $aiVisibilityTrend = $aiVisibility['trend'] ?? [];
    $aiVisibilityKeywords = $aiVisibility['keywords'] ?? [];
    $aiVisibilityTerms = $aiVisibility['terms'] ?? [];
    $aiVisibilitySources = $aiVisibility['sources'] ?? [];
    $aiVisibilityAttentionSources = $aiVisibility['attention_sources'] ?? [];
    $aiVisibilitySampleTarget = (int) ($aiVisibility['daily_sample_target'] ?? 5);
    $aiVisibilityConfigured = (bool) ($aiVisibility['configured'] ?? false);
    $aiVisibilityChartWidth = 960;
    $aiVisibilityChartHeight = 280;
    $aiVisibilityChartPadding = ['top' => 18, 'right' => 28, 'bottom' => 34, 'left' => 46];
    $aiVisibilityPlotWidth = $aiVisibilityChartWidth - $aiVisibilityChartPadding['left'] - $aiVisibilityChartPadding['right'];
    $aiVisibilityPlotHeight = $aiVisibilityChartHeight - $aiVisibilityChartPadding['top'] - $aiVisibilityChartPadding['bottom'];
    $aiVisibilityTrendCount = max(1, count($aiVisibilityTrend));
    $aiVisibilityTrendStep = $aiVisibilityTrendCount > 1 ? $aiVisibilityPlotWidth / ($aiVisibilityTrendCount - 1) : $aiVisibilityPlotWidth;
    $aiVisibilityChartMetrics = [
        [
            'key' => 'visibility',
            'label' => __('admin.growth_center.ai_visibility.kpi.visibility'),
            'color' => '#4f46e5',
            'class' => 'text-indigo-700 border-indigo-200 bg-indigo-50',
        ],
        [
            'key' => 'top1',
            'label' => __('admin.growth_center.ai_visibility.kpi.top1'),
            'color' => '#d97706',
            'class' => 'text-amber-700 border-amber-200 bg-amber-50',
        ],
        [
            'key' => 'top3',
            'label' => __('admin.growth_center.ai_visibility.kpi.top3'),
            'color' => '#059669',
            'class' => 'text-emerald-700 border-emerald-200 bg-emerald-50',
        ],
    ];
    $aiVisibilityDefinitionItems = [
        [
            'title' => __('admin.growth_center.ai_visibility.definition.sampling_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.sampling_body', ['count' => $aiVisibilitySampleTarget]),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.visibility_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.visibility_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.top1_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.top1_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.top3_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.top3_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.trend_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.trend_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.sentiment_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.sentiment_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.term_cloud_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.term_cloud_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.source_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.source_body'),
        ],
        [
            'title' => __('admin.growth_center.ai_visibility.definition.attention_title'),
            'body' => __('admin.growth_center.ai_visibility.definition.attention_body'),
        ],
    ];
    $aiVisibilityBuildPoints = function (string $key) use ($aiVisibilityTrend, $aiVisibilityChartPadding, $aiVisibilityPlotHeight, $aiVisibilityTrendStep): string {
        $points = [];
        foreach ($aiVisibilityTrend as $index => $point) {
            $value = max(0, min(100, (float) ($point[$key] ?? 0)));
            $x = $aiVisibilityChartPadding['left'] + ($index * $aiVisibilityTrendStep);
            $y = $aiVisibilityChartPadding['top'] + ((100 - $value) / 100 * $aiVisibilityPlotHeight);
            $points[] = round($x, 2).','.round($y, 2);
        }

        return implode(' ', $points);
    };
    $aiVisibilityAxisDates = count($aiVisibilityTrend) > 0
        ? [
            $aiVisibilityTrend[0],
            $aiVisibilityTrend[(int) floor((count($aiVisibilityTrend) - 1) / 2)],
            $aiVisibilityTrend[count($aiVisibilityTrend) - 1],
        ]
        : [];
    $growthStages = [
        [
            'icon' => 'eye',
            'title' => __('admin.growth_center.stage.visit_title'),
            'desc' => __('admin.growth_center.stage.visit_desc'),
            'count' => (int) ($growthStats['today_visits'] ?? 0),
            'href' => '#growth-observation-details',
            'iconClass' => 'bg-blue-50 text-blue-600 ring-blue-100',
            'countClass' => 'text-blue-700',
            'linkClass' => 'text-blue-700 group-hover:text-blue-800',
        ],
        [
            'icon' => 'mouse-pointer-click',
            'title' => __('admin.growth_center.stage.touch_title'),
            'desc' => __('admin.growth_center.stage.touch_desc'),
            'count' => (int) ($growthStats['active_forms'] ?? 0),
            'href' => route('admin.lead-forms.index'),
            'iconClass' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
            'countClass' => 'text-emerald-700',
            'linkClass' => 'text-emerald-700 group-hover:text-emerald-800',
        ],
        [
            'icon' => 'inbox',
            'title' => __('admin.growth_center.stage.lead_title'),
            'desc' => __('admin.growth_center.stage.lead_desc'),
            'count' => (int) ($growthStats['submissions_total'] ?? 0),
            'href' => route('admin.leads.index'),
            'iconClass' => 'bg-amber-50 text-amber-600 ring-amber-100',
            'countClass' => 'text-amber-700',
            'linkClass' => 'text-amber-700 group-hover:text-amber-800',
        ],
        [
            'icon' => 'user-check',
            'title' => __('admin.growth_center.stage.follow_title'),
            'desc' => __('admin.growth_center.stage.follow_desc'),
            'count' => (int) ($growthStats['handled_leads'] ?? 0),
            'href' => route('admin.leads.index', ['status' => 'contacted']),
            'iconClass' => 'bg-purple-50 text-purple-600 ring-purple-100',
            'countClass' => 'text-purple-700',
            'linkClass' => 'text-purple-700 group-hover:text-purple-800',
        ],
    ];
    $growthMetrics = [
        ['icon' => 'calendar-days', 'label' => __('admin.growth_center.metric.today_visits'), 'value' => (int) ($growthStats['today_visits'] ?? 0), 'class' => 'text-blue-600'],
        ['icon' => 'bot', 'label' => __('admin.growth_center.metric.ai_visits'), 'value' => (int) ($growthStats['today_ai_visits'] ?? 0), 'class' => 'text-indigo-600'],
        ['icon' => 'clipboard-list', 'label' => __('admin.growth_center.metric.submissions'), 'value' => (int) ($growthStats['submissions_total'] ?? 0), 'class' => 'text-emerald-600'],
        ['icon' => 'sparkles', 'label' => __('admin.growth_center.metric.new_leads'), 'value' => (int) ($growthStats['new_leads'] ?? 0), 'class' => 'text-amber-600'],
        ['icon' => 'phone-call', 'label' => __('admin.growth_center.metric.pending_followups'), 'value' => (int) ($growthStats['pending_followups'] ?? 0), 'class' => 'text-rose-600'],
    ];

    if ((int) ($growthStats['new_leads'] ?? 0) > 0) {
        $priority = [
            'icon' => 'inbox',
            'title' => __('admin.growth_center.priority.new_leads_title', ['count' => (int) $growthStats['new_leads']]),
            'desc' => __('admin.growth_center.priority.new_leads_desc'),
            'href' => route('admin.leads.index', ['status' => 'new']),
            'button' => __('admin.growth_center.priority.new_leads_button'),
            'iconClass' => 'bg-amber-50 text-amber-600 ring-amber-100',
        ];
    } elseif ((int) ($growthStats['active_forms'] ?? 0) === 0) {
        $priority = [
            'icon' => 'clipboard-plus',
            'title' => __('admin.growth_center.priority.no_form_title'),
            'desc' => __('admin.growth_center.priority.no_form_desc'),
            'href' => route('admin.lead-forms.create'),
            'button' => __('admin.growth_center.priority.no_form_button'),
            'iconClass' => 'bg-blue-50 text-blue-600 ring-blue-100',
        ];
    } else {
        $priority = [
            'icon' => 'line-chart',
            'title' => __('admin.growth_center.priority.observation_title'),
            'desc' => __('admin.growth_center.priority.observation_desc'),
            'href' => '#growth-observation-details',
            'button' => __('admin.growth_center.priority.observation_button'),
            'iconClass' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        ];
    }
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.analytics.heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.lead-forms.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.action.create_form') }}
                </a>
                <a href="{{ route('admin.lead-forms.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="clipboard-list" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.action.manage_forms') }}
                </a>
                <a href="{{ route('admin.leads.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="inbox" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.action.lead_inbox') }}
                </a>
                <button type="button" onclick="location.reload()" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-700 shadow-sm hover:bg-gray-50">
                    <i data-lucide="refresh-cw" class="mr-1 h-4 w-4"></i>
                    {{ __('admin.analytics.refresh') }}
                </button>
            </div>
        </div>

        <section class="mb-8">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">{{ __('admin.growth_center.workbench.eyebrow') }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ __('admin.growth_center.workbench.title') }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ __('admin.growth_center.workbench.desc') }}</p>
                </div>
                <a href="{{ route('admin.leads.export') }}" class="inline-flex w-fit items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                    <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.action.export_leads') }}
                </a>
            </div>

            <div class="mb-4 rounded-lg border border-blue-100 bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md ring-1 {{ $priority['iconClass'] }}">
                            <i data-lucide="{{ $priority['icon'] }}" class="h-5 w-5"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">{{ __('admin.growth_center.priority.label') }}</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $priority['title'] }}</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">{{ $priority['desc'] }}</p>
                        </div>
                    </div>
                    <a href="{{ $priority['href'] }}" class="inline-flex h-9 w-fit shrink-0 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700">
                        {{ $priority['button'] }}
                        <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4"></i>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($growthStages as $stage)
                    <a href="{{ $stage['href'] }}" class="group flex min-h-44 flex-col justify-between rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md">
                        <div>
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-md ring-1 {{ $stage['iconClass'] }}">
                                    <i data-lucide="{{ $stage['icon'] }}" class="h-5 w-5"></i>
                                </div>
                                <div class="text-right text-2xl font-semibold {{ $stage['countClass'] }}">{{ $stage['count'] }}</div>
                            </div>
                            <h3 class="mt-5 text-base font-semibold text-gray-900">{{ $stage['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ $stage['desc'] }}</p>
                        </div>
                        <div class="mt-5 inline-flex items-center text-sm font-semibold {{ $stage['linkClass'] }}">
                            {{ __('admin.growth_center.workbench.open') }}
                            <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4 transition group-hover:translate-x-0.5"></i>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-5">
            @foreach ($growthMetrics as $metric)
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center">
                        <i data-lucide="{{ $metric['icon'] }}" class="h-6 w-6 {{ $metric['class'] }}"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ $metric['label'] }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ $metric['value'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <section class="mb-8">
            @if (! $aiVisibilityConfigured)
                <div class="rounded-lg border border-dashed border-indigo-200 bg-indigo-50/60 p-4 shadow-sm" data-ai-visibility-setup-entry>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-indigo-600 ring-1 ring-indigo-100">
                                <i data-lucide="radar" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ __('admin.growth_center.ai_visibility.eyebrow') }}</p>
                                <h2 class="mt-1 text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.setup_entry_title') }}</h2>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.setup_entry_desc') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('admin.ai-source-providers.index') }}" class="inline-flex w-fit shrink-0 items-center rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            <i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.growth_center.ai_visibility.setup_entry_action') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="mb-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ __('admin.growth_center.ai_visibility.eyebrow') }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.title') }}</h2>
                        <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ __('admin.growth_center.ai_visibility.desc', ['count' => $aiVisibilitySampleTarget]) }}</p>
                    </div>
                    <a href="{{ route('admin.ai-source-providers.index') }}" class="inline-flex w-fit items-center rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100">
                        <i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.growth_center.ai_visibility.configure') }}
                    </a>
                </div>

                @if (! ($aiVisibility['ready'] ?? false))
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center shadow-sm">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                            <i data-lucide="database" class="h-6 w-6"></i>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.not_ready_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.not_ready_desc') }}</p>
                    </div>
                @elseif ((int) ($aiVisibilityPolling['sampled_runs'] ?? 0) === 0)
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center shadow-sm">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100">
                            <i data-lucide="radar" class="h-6 w-6"></i>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.empty_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.empty_desc') }}</p>
                    </div>
                @else
                <div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.visibility') }}</div>
                            <i data-lucide="scan-eye" class="h-5 w-5 text-indigo-600"></i>
                        </div>
                        <div class="mt-3 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format((float) ($aiVisibilityKpis['brand_visibility'] ?? 0), 1) }}%</div>
                        <div class="mt-2 h-1.5 rounded-full bg-gray-100">
                            <div class="h-1.5 rounded-full bg-indigo-600" style="width: {{ min(100, max(0, (float) ($aiVisibilityKpis['brand_visibility'] ?? 0))) }}%"></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.top1') }}</div>
                            <i data-lucide="trophy" class="h-5 w-5 text-amber-600"></i>
                        </div>
                        <div class="mt-3 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format((float) ($aiVisibilityKpis['top1_rate'] ?? 0), 1) }}%</div>
                        <div class="mt-2 text-xs text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.top1_desc') }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.top3') }}</div>
                            <i data-lucide="podium" class="h-5 w-5 text-emerald-600"></i>
                        </div>
                        <div class="mt-3 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format((float) ($aiVisibilityKpis['top3_rate'] ?? 0), 1) }}%</div>
                        <div class="mt-2 text-xs text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.top3_desc') }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-gray-500">{{ __('admin.growth_center.ai_visibility.kpi.sentiment') }}</div>
                            <i data-lucide="message-circle-heart" class="h-5 w-5 text-rose-600"></i>
                        </div>
                        <div class="mt-3 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format((float) ($aiVisibilityKpis['sentiment_score'] ?? 0), 1) }}</div>
                        <div class="mt-2 text-xs text-gray-500">
                            {{ __('admin.growth_center.ai_visibility.kpi.sentiment_mix', [
                                'positive' => number_format((float) ($aiVisibilityKpis['positive_rate'] ?? 0), 1),
                                'negative' => number_format((float) ($aiVisibilityKpis['negative_rate'] ?? 0), 1),
                            ]) }}
                        </div>
                    </div>
                </div>

                <details class="mb-5 rounded-lg border border-indigo-100 bg-indigo-50/60 p-4 shadow-sm" data-ai-visibility-metric-definitions>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-indigo-800">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="info" class="h-4 w-4"></i>
                            {{ __('admin.growth_center.ai_visibility.definition_toggle') }}
                        </span>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-indigo-500"></i>
                    </summary>
                    <div class="mt-4 border-t border-indigo-100 pt-4">
                        <p class="text-sm leading-6 text-indigo-900">{{ __('admin.growth_center.ai_visibility.definition_intro') }}</p>
                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($aiVisibilityDefinitionItems as $definitionItem)
                                <div class="rounded-md border border-white/70 bg-white/90 p-3" data-ai-visibility-definition-item>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ $definitionItem['title'] }}</div>
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ $definitionItem['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </details>

                <section class="mb-5 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.trend_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.polling_summary', [
                                'sampled' => (int) ($aiVisibilityPolling['sampled_runs'] ?? 0),
                                'runs' => (int) ($aiVisibilityPolling['runs'] ?? 0),
                                'rate' => number_format((float) ($aiVisibilityPolling['success_rate'] ?? 0), 1),
                            ]) }}</p>
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="rounded-md bg-gray-50 px-3 py-2 text-right">
                                <div class="text-xs text-gray-500">{{ __('admin.growth_center.ai_visibility.today_samples') }}</div>
                                <div class="text-sm font-semibold tabular-nums text-gray-900">{{ (int) ($aiVisibilityPolling['today_completed_runs'] ?? 0) }} / {{ (int) ($aiVisibilityPolling['today_target_samples'] ?? 0) }}</div>
                            </div>
                            <div class="flex flex-wrap gap-2" data-ai-visibility-metric-controls>
                                @foreach ($aiVisibilityChartMetrics as $metric)
                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold transition {{ $metric['class'] }}" data-ai-visibility-metric-label="{{ $metric['key'] }}">
                                        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 focus:ring-indigo-500" value="{{ $metric['key'] }}" style="accent-color: {{ $metric['color'] }}" data-ai-visibility-metric-toggle checked>
                                        <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $metric['color'] }}"></span>
                                        <span>{{ $metric['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="px-5 py-5">
                        <div class="overflow-x-auto">
                            <svg viewBox="0 0 {{ $aiVisibilityChartWidth }} {{ $aiVisibilityChartHeight }}" class="h-80 min-w-[52rem] w-full" role="img" aria-label="{{ __('admin.growth_center.ai_visibility.trend_title') }}">
                                @foreach ([0, 25, 50, 75, 100] as $tick)
                                    @php
                                        $tickY = $aiVisibilityChartPadding['top'] + ((100 - $tick) / 100 * $aiVisibilityPlotHeight);
                                    @endphp
                                    <line x1="{{ $aiVisibilityChartPadding['left'] }}" y1="{{ $tickY }}" x2="{{ $aiVisibilityChartWidth - $aiVisibilityChartPadding['right'] }}" y2="{{ $tickY }}" stroke="#eef2f7" stroke-width="1" />
                                    <text x="8" y="{{ $tickY + 4 }}" fill="#9ca3af" font-size="12">{{ $tick }}%</text>
                                @endforeach

                                @foreach ($aiVisibilityChartMetrics as $metric)
                                    <g data-ai-visibility-series="{{ $metric['key'] }}">
                                        <polyline points="{{ $aiVisibilityBuildPoints($metric['key']) }}" fill="none" stroke="{{ $metric['color'] }}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                                        @foreach ($aiVisibilityTrend as $pointIndex => $point)
                                            @if ($pointIndex % 6 === 0 || $pointIndex === count($aiVisibilityTrend) - 1)
                                                @php
                                                    $pointValue = max(0, min(100, (float) ($point[$metric['key']] ?? 0)));
                                                    $pointX = $aiVisibilityChartPadding['left'] + ($pointIndex * $aiVisibilityTrendStep);
                                                    $pointY = $aiVisibilityChartPadding['top'] + ((100 - $pointValue) / 100 * $aiVisibilityPlotHeight);
                                                @endphp
                                                <circle cx="{{ $pointX }}" cy="{{ $pointY }}" r="3.5" fill="#ffffff" stroke="{{ $metric['color'] }}" stroke-width="2">
                                                    <title>{{ $point['label'] }} · {{ $metric['label'] }} {{ number_format($pointValue, 1) }}%</title>
                                                </circle>
                                            @endif
                                        @endforeach
                                    </g>
                                @endforeach

                                <line x1="{{ $aiVisibilityChartPadding['left'] }}" y1="{{ $aiVisibilityChartHeight - $aiVisibilityChartPadding['bottom'] }}" x2="{{ $aiVisibilityChartWidth - $aiVisibilityChartPadding['right'] }}" y2="{{ $aiVisibilityChartHeight - $aiVisibilityChartPadding['bottom'] }}" stroke="#d1d5db" stroke-width="1.5" />
                                @foreach ($aiVisibilityAxisDates as $axisIndex => $axisPoint)
                                    @php
                                        $axisX = $axisIndex === 0
                                            ? $aiVisibilityChartPadding['left']
                                            : ($axisIndex === 1
                                                ? $aiVisibilityChartPadding['left'] + ($aiVisibilityPlotWidth / 2)
                                                : $aiVisibilityChartWidth - $aiVisibilityChartPadding['right']);
                                        $anchor = $axisIndex === 0 ? 'start' : ($axisIndex === 1 ? 'middle' : 'end');
                                    @endphp
                                    <text x="{{ $axisX }}" y="{{ $aiVisibilityChartHeight - 8 }}" fill="#6b7280" font-size="13" text-anchor="{{ $anchor }}">{{ $axisPoint['label'] ?? '' }}</text>
                                @endforeach
                            </svg>
                        </div>
                    </div>
                </section>

                <section class="mb-5 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.term_cloud_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.term_cloud_desc') }}</p>
                        </div>
                        <div class="text-sm font-semibold tabular-nums text-indigo-700">{{ count($aiVisibilityTerms) }}</div>
                    </div>
                    <div class="mt-4 flex min-h-32 flex-wrap content-start gap-2">
                        @forelse ($aiVisibilityTerms as $term)
                            <span class="inline-flex max-w-full items-center rounded-md border border-indigo-100 bg-indigo-50 px-2.5 py-1.5 font-semibold leading-none text-indigo-700" style="font-size: {{ (int) ($term['size'] ?? 12) }}px">
                                {{ $term['term'] }}
                            </span>
                        @empty
                            <div class="text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_terms') }}</div>
                        @endforelse
                    </div>
                </section>

                <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="mb-4">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.keyword_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.keyword_desc') }}</p>
                        </div>
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.ai_visibility.table.keyword') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.ai_visibility.table.samples') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.ai_visibility.table.visibility') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.ai_visibility.table.top3') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse ($aiVisibilityKeywords as $keyword)
                                        <tr>
                                            <td class="px-3 py-3 text-sm font-medium text-gray-900">{{ $keyword['keyword'] }}</td>
                                            <td class="px-3 py-3 text-right text-sm tabular-nums text-gray-600">{{ (int) ($keyword['samples'] ?? 0) }}</td>
                                            <td class="px-3 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ number_format((float) ($keyword['brand_visibility'] ?? 0), 1) }}%</td>
                                            <td class="px-3 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ number_format((float) ($keyword['top3_rate'] ?? 0), 1) }}%</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-3 py-8 text-center text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_keywords') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="mb-4">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.source_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.source_desc') }}</p>
                        </div>
                        <div class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                            @forelse ($aiVisibilitySources as $source)
                                <div class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-900">{{ $source['domain'] }}</div>
                                            <div class="mt-1 truncate text-xs text-gray-500">{{ $source['latest_title'] ?: implode(' · ', $source['keywords'] ?? []) }}</div>
                                        </div>
                                        <span class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">{{ __('admin.growth_center.ai_visibility.action.'.$source['action']) }}</span>
                                    </div>
                                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-500">
                                        <span class="tabular-nums">{{ __('admin.growth_center.ai_visibility.source_mentions', ['count' => (int) ($source['mentions'] ?? 0)]) }}</span>
                                        <span class="tabular-nums">{{ __('admin.growth_center.ai_visibility.source_avg_rank', ['rank' => number_format((float) ($source['avg_rank'] ?? 0), 1)]) }}</span>
                                        <span class="tabular-nums">{{ __('admin.growth_center.ai_visibility.source_brand_coverage', ['rate' => number_format((float) ($source['brand_coverage'] ?? 0), 1)]) }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="px-4 py-8 text-center text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.no_sources') }}</div>
                            @endforelse
                        </div>
                    </section>
                </div>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.growth_center.ai_visibility.attention_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.ai_visibility.attention_desc') }}</p>
                    </div>
                    <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                        @forelse ($aiVisibilityAttentionSources as $source)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-amber-950">{{ $source['domain'] }}</div>
                                        <div class="mt-1 text-xs font-medium text-amber-700">{{ __('admin.growth_center.ai_visibility.action.'.$source['action']) }}</div>
                                    </div>
                                    <i data-lucide="flag" class="h-5 w-5 shrink-0 text-amber-600"></i>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-amber-900">{{ __('admin.growth_center.ai_visibility.recommendation.'.$source['action']) }}</p>
                            </div>
                        @empty
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500 lg:col-span-3">{{ __('admin.growth_center.ai_visibility.no_attention_sources') }}</div>
                        @endforelse
                    </div>
                </section>
            @endif
            @endif
        </section>

        <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.growth_center.inbox.title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.inbox.desc') }}</p>
                    </div>
                    <a href="{{ route('admin.leads.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">{{ __('admin.growth_center.inbox.view_all') }}</a>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($recentSubmissions as $submission)
                        <a href="{{ route('admin.leads.show', ['submissionId' => $submission->id]) }}" class="block px-5 py-4 hover:bg-gray-50">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $submission->form?->name ?? __('admin.leads.deleted_form') }}</div>
                                    <div class="mt-1 truncate text-xs text-gray-500">{{ $submission->source_url ?: __('admin.growth_center.direct_source') }}</div>
                                </div>
                                <span class="shrink-0 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.leads.status.'.$submission->status) }}</span>
                            </div>
                            <div class="mt-2 text-xs text-gray-400">{{ $submission->created_at?->format('Y-m-d H:i') }}</div>
                        </a>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-gray-500">{{ __('admin.growth_center.inbox.empty') }}</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.growth_center.source.title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.growth_center.source.desc') }}</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($sourceSummary as $source)
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0 truncate text-sm font-medium text-gray-900">{{ $source['source'] }}</div>
                                <div class="shrink-0 text-sm font-semibold text-gray-900">{{ $source['count'] }}</div>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                                <span>{{ __('admin.growth_center.source.converted', ['count' => (int) ($source['converted'] ?? 0)]) }}</span>
                                <span>{{ __('admin.growth_center.source.submissions') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-gray-500">{{ __('admin.growth_center.source.empty') }}</div>
                    @endforelse
                </div>
            </section>
        </div>

        <section id="growth-observation-details" class="mb-6 border-t border-gray-200 pt-8">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.growth_center.observation.eyebrow') }}</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ __('admin.growth_center.observation.title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.growth_center.observation.desc') }}</p>
            </div>
        </section>

        @include('admin.analytics._filters', ['filters' => $filters, 'filterOptions' => $filterOptions])
        @include('admin.analytics._global-overview', ['globalOverview' => $globalOverview])
        @include('admin.analytics._single-site-section')
        @if ($canManageProtectedWorkflows)
            @include('admin.analytics._distribution-section')
        @endif
        @include('admin.analytics._log-section', ['logSummary' => $logSummary])
    </div>
@endsection

@if ($aiVisibilityConfigured)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-ai-visibility-metric-toggle]').forEach(function (toggle) {
                    const refreshMetric = function () {
                        const metric = toggle.value;
                        const isChecked = toggle.checked;

                        document.querySelectorAll('[data-ai-visibility-series="' + metric + '"]').forEach(function (series) {
                            series.classList.toggle('hidden', !isChecked);
                        });

                        document.querySelectorAll('[data-ai-visibility-metric-label="' + metric + '"]').forEach(function (label) {
                            label.classList.toggle('opacity-50', !isChecked);
                        });
                    };

                    toggle.addEventListener('change', refreshMetric);
                    refreshMetric();
                });
            });
        </script>
    @endpush
@endif
