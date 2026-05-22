@php
    $distributionKpis = [
        [
            'key' => 'distribution_synced',
            'value' => $distributionSummary['synced'] ?? 0,
            'icon' => 'check-circle-2',
            'tone' => 'text-emerald-600',
        ],
        [
            'key' => 'distribution_failed',
            'value' => $kpis['distribution_failed'] ?? ($distributionSummary['failed'] ?? 0),
            'icon' => 'radio-tower',
            'tone' => 'text-rose-600',
        ],
        [
            'key' => 'distribution_pending',
            'value' => $kpis['distribution_pending'] ?? ($distributionSummary['pending'] ?? 0),
            'icon' => 'clock',
            'tone' => 'text-orange-600',
        ],
    ];
@endphp

<section class="mb-8" data-analytics-multi-site-section>
    <div class="mb-5">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.analytics.multi_site_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.analytics.multi_site_desc') }}</p>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-5 md:grid-cols-3">
        @foreach ($distributionKpis as $card)
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center gap-4">
                    <i data-lucide="{{ $card['icon'] }}" class="h-7 w-7 {{ $card['tone'] }}"></i>
                    <div class="min-w-0">
                        <div class="whitespace-nowrap text-sm font-medium text-gray-500">{{ __('admin.analytics.kpi.'.$card['key']) }}</div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format((int) ($card['value'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b border-gray-100 px-6 py-4">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.analytics.distribution_status') }}</h3>
        </div>
        <div class="p-6">
            <div class="mb-5 grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-emerald-50 px-3 py-4">
                    <div class="text-2xl font-bold text-emerald-700">{{ number_format((int) $distributionSummary['synced']) }}</div>
                    <div class="mt-1 text-xs text-emerald-700">{{ __('admin.analytics.synced') }}</div>
                </div>
                <div class="rounded-lg bg-red-50 px-3 py-4">
                    <div class="text-2xl font-bold text-red-700">{{ number_format((int) $distributionSummary['failed']) }}</div>
                    <div class="mt-1 text-xs text-red-700">{{ __('admin.analytics.failed') }}</div>
                </div>
                <div class="rounded-lg bg-amber-50 px-3 py-4">
                    <div class="text-2xl font-bold text-amber-700">{{ number_format((int) $distributionSummary['pending']) }}</div>
                    <div class="mt-1 text-xs text-amber-700">{{ __('admin.analytics.pending') }}</div>
                </div>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($distributionSummary['rows'] as $row)
                    <div class="grid grid-cols-5 gap-3 py-3 text-sm">
                        <div class="col-span-2 min-w-0 truncate font-medium text-gray-900">{{ $row['name'] }}</div>
                        <div class="whitespace-nowrap text-emerald-700">{{ __('admin.analytics.synced') }} {{ $row['synced'] }}</div>
                        <div class="whitespace-nowrap text-red-700">{{ __('admin.analytics.failed') }} {{ $row['failed'] }}</div>
                        <div class="whitespace-nowrap text-gray-500">{{ __('admin.analytics.pending') }} {{ $row['pending'] }}</div>
                    </div>
                @empty
                    <div class="rounded-lg bg-gray-50 px-4 py-5 text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</section>
