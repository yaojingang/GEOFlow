@php
    $singleSiteKpis = [
        ['key' => 'articles', 'icon' => 'file-text', 'tone' => 'text-blue-600'],
        ['key' => 'published', 'icon' => 'globe', 'tone' => 'text-emerald-600'],
        ['key' => 'running_tasks', 'icon' => 'activity', 'tone' => 'text-amber-600'],
        ['key' => 'failed_tasks', 'icon' => 'triangle-alert', 'tone' => 'text-red-600'],
        ['key' => 'ai_calls', 'icon' => 'cpu', 'tone' => 'text-indigo-600'],
        ['key' => 'total_views', 'icon' => 'eye', 'tone' => 'text-slate-700'],
    ];
@endphp

<section class="mb-8" data-analytics-single-site-section>
    <div class="mb-5">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.analytics.single_site_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.single_site_desc') }}</p>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($singleSiteKpis as $card)
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center gap-4">
                    <i data-lucide="{{ $card['icon'] }}" class="h-7 w-7 {{ $card['tone'] }}"></i>
                    <div class="min-w-0">
                        <div class="whitespace-nowrap text-sm font-medium text-gray-500">{{ __('admin.analytics.kpi.'.$card['key']) }}</div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format((int) ($kpis[$card['key']] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('admin.analytics._content-section')
</section>
