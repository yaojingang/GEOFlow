@extends('admin.layouts.app')

@php
    $metrics = $overview['metrics'] ?? [];
    $cards = $overview['cards'] ?? [];
    $alert = $overview['alert'] ?? null;
    $metricCards = [
        ['key' => 'today_visits', 'icon' => 'mouse-pointer-click', 'tone' => 'text-blue-600', 'period' => 'today'],
        ['key' => 'published_7d', 'icon' => 'send', 'tone' => 'text-emerald-600', 'period' => '7d'],
        ['key' => 'brand_visibility_60d', 'icon' => 'sparkles', 'tone' => 'text-violet-600', 'period' => '60d', 'suffix' => '%'],
        ['key' => 'new_leads', 'icon' => 'inbox', 'tone' => 'text-amber-600', 'period' => 'current'],
        ['key' => 'pending_followups', 'icon' => 'phone-call', 'tone' => 'text-rose-600', 'period' => 'current'],
    ];
    $modules = [
        [
            'key' => 'content',
            'route' => 'admin.analytics.content',
            'span' => 'lg:col-span-6',
            'tone' => 'bg-blue-600',
            'icon' => 'files',
            'main' => (int) ($cards['content']['published'] ?? 0),
            'main_label' => __('admin.analytics.overview.cards.content.main'),
            'secondary' => [
                [__('admin.analytics.overview.cards.content.created'), (int) ($cards['content']['created'] ?? 0)],
                [__('admin.analytics.overview.cards.content.failed'), (int) ($cards['content']['failed_tasks'] ?? 0)],
            ],
            'period' => __('admin.analytics.overview.periods.7d'),
        ],
        [
            'key' => 'traffic',
            'route' => 'admin.analytics.traffic',
            'span' => 'lg:col-span-6',
            'tone' => 'bg-cyan-600',
            'icon' => 'waypoints',
            'main' => (int) ($cards['traffic']['pv'] ?? 0),
            'main_label' => __('admin.analytics.overview.cards.traffic.main'),
            'secondary' => [
                [__('admin.analytics.overview.cards.traffic.unique_ip'), (int) ($cards['traffic']['unique_ip'] ?? 0)],
                [__('admin.analytics.overview.cards.traffic.ai'), (int) ($cards['traffic']['ai'] ?? 0)],
            ],
            'period' => __('admin.analytics.overview.periods.today'),
        ],
        [
            'key' => 'ai_visibility',
            'route' => 'admin.analytics.ai-visibility',
            'span' => 'lg:col-span-6',
            'tone' => 'bg-violet-600',
            'icon' => 'scan-search',
            'main' => number_format((float) ($cards['ai_visibility']['visibility'] ?? 0), 1).'%',
            'main_label' => __('admin.analytics.overview.cards.ai_visibility.main'),
            'secondary' => [
                [__('admin.analytics.overview.cards.ai_visibility.top3'), number_format((float) ($cards['ai_visibility']['top3'] ?? 0), 1).'%'],
                [__('admin.analytics.overview.cards.ai_visibility.samples'), (int) ($cards['ai_visibility']['samples'] ?? 0)],
            ],
            'period' => __('admin.analytics.overview.periods.60d'),
        ],
        [
            'key' => 'leads',
            'route' => 'admin.analytics.leads',
            'span' => 'lg:col-span-6',
            'tone' => 'bg-amber-500',
            'icon' => 'contact-round',
            'main' => (int) ($cards['leads']['submissions_30d'] ?? 0),
            'main_label' => __('admin.analytics.overview.cards.leads.main'),
            'secondary' => [
                [__('admin.analytics.overview.cards.leads.new'), (int) ($cards['leads']['new_30d'] ?? 0)],
                [__('admin.analytics.overview.cards.leads.converted'), (int) ($cards['leads']['converted_30d'] ?? 0)],
            ],
            'period' => __('admin.analytics.overview.periods.30d'),
        ],
    ];
    if ($canManageProtectedWorkflows) {
        $modules[] = [
            'key' => 'distribution',
            'route' => 'admin.analytics.distribution',
            'span' => 'lg:col-span-12',
            'tone' => 'bg-slate-700',
            'icon' => 'radio-tower',
            'main' => (int) ($cards['distribution']['total'] ?? 0),
            'main_label' => __('admin.analytics.overview.cards.distribution.main'),
            'secondary' => [
                [__('admin.analytics.overview.cards.distribution.synced'), (int) ($cards['distribution']['synced'] ?? 0)],
                [__('admin.analytics.overview.cards.distribution.failed'), (int) ($cards['distribution']['failed'] ?? 0)],
            ],
            'period' => __('admin.analytics.overview.periods.current'),
        ];
    }
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.overview.title'),
            'subtitle' => __('admin.analytics.overview.subtitle'),
        ])

        <section class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="{{ __('admin.analytics.overview.metrics_label') }}">
            @foreach ($metricCards as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium leading-5 text-gray-500">{{ __('admin.analytics.overview.metrics.'.$card['key']) }}</p>
                        <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 shrink-0 {{ $card['tone'] }}"></i>
                    </div>
                    <p class="mt-4 text-right font-mono text-3xl font-semibold tabular-nums text-gray-950">
                        {{ is_float($metrics[$card['key']] ?? null) ? number_format((float) $metrics[$card['key']], 1) : number_format((int) ($metrics[$card['key']] ?? 0)) }}{{ $card['suffix'] ?? '' }}
                    </p>
                    <p class="mt-1 text-right text-xs text-gray-400">{{ __('admin.analytics.overview.periods.'.$card['period']) }}</p>
                </article>
            @endforeach
        </section>

        @if ($alert)
            <aside class="mb-6 flex flex-col gap-4 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between" data-analytics-priority-alert>
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <i data-lucide="bell-ring" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-amber-950">{{ __('admin.analytics.overview.alerts.'.$alert['type'].'.title', ['count' => $alert['count']]) }}</p>
                        <p class="mt-1 text-sm leading-6 text-amber-800">{{ __('admin.analytics.overview.alerts.'.$alert['type'].'.description') }}</p>
                    </div>
                </div>
                <a href="{{ $alert['href'] }}" class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-md bg-amber-600 px-4 text-sm font-semibold text-white transition duration-[120ms] motion-reduce:transition-none hover:bg-amber-700 active:scale-[.98] motion-reduce:active:scale-100">
                    {{ __('admin.analytics.overview.alerts.view') }}
                    <i data-lucide="arrow-right" class="ml-2 h-4 w-4"></i>
                </a>
            </aside>
        @endif

        <section class="grid grid-cols-1 gap-4 lg:grid-cols-12" aria-label="{{ __('admin.analytics.overview.modules_label') }}">
            @foreach ($modules as $module)
                <article class="{{ $module['span'] }} rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex h-full flex-col">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full text-white {{ $module['tone'] }}">
                                    <i data-lucide="{{ $module['icon'] }}" class="h-5 w-5"></i>
                                </span>
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.pages.'.$module['key'].'.title') }}</h2>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $module['period'] }}</p>
                                </div>
                            </div>
                            <p class="text-right font-mono text-3xl font-semibold tabular-nums text-gray-950">{{ $module['main'] }}</p>
                        </div>
                        <p class="mt-2 text-right text-xs font-medium text-gray-500">{{ $module['main_label'] }}</p>
                        <dl class="mt-6 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4">
                            @foreach ($module['secondary'] as [$label, $value])
                                <div>
                                    <dt class="text-xs text-gray-500">{{ $label }}</dt>
                                    <dd class="mt-1 text-right font-mono text-lg font-semibold tabular-nums text-gray-900">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <a href="{{ route($module['route']) }}" class="mt-5 inline-flex min-h-10 items-center justify-center self-end rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] motion-reduce:transition-none hover:border-blue-300 hover:text-blue-700 active:scale-[.98] motion-reduce:active:scale-100">
                            {{ __('admin.analytics.overview.view_analysis') }}
                            <i data-lucide="arrow-right" class="ml-2 h-4 w-4"></i>
                        </a>
                    </div>
                </article>
            @endforeach
        </section>

        <div class="mt-6 flex justify-end">
            <a href="{{ route('admin.dashboard') }}" class="inline-flex min-h-10 items-center text-sm font-semibold text-gray-500 hover:text-blue-700">
                <i data-lucide="activity" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.overview.health_link') }}
            </a>
        </div>
    </div>
@endsection
