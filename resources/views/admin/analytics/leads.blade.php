@extends('admin.layouts.app')

@php
    $filterData = $filters->toArray();
    $kpis = $summary['kpis'] ?? [];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.analytics._page-header', [
            'title' => __('admin.analytics.pages.leads.title'),
            'subtitle' => __('admin.analytics.pages.leads.subtitle'),
        ])

        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.analytics.leads') }}" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    @foreach (['7d', '30d', '90d'] as $preset)
                        <button type="submit" name="lead_preset" value="{{ $preset }}" class="inline-flex min-h-10 items-center rounded-md border px-3 text-sm font-semibold transition duration-[120ms] motion-reduce:transition-none active:scale-[.98] motion-reduce:active:scale-100 {{ $filters->preset === $preset ? 'border-amber-600 bg-amber-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-amber-300 hover:text-amber-700' }}" aria-pressed="{{ $filters->preset === $preset ? 'true' : 'false' }}">{{ __('admin.analytics.filters.'.$preset) }}</button>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div><label for="lead-date-from" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_from') }}</label><input id="lead-date-from" type="date" name="lead_date_from" value="{{ $filterData['lead_date_from'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-amber-500 focus:ring-amber-500"></div>
                    <div><label for="lead-date-to" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.filters.date_to') }}</label><input id="lead-date-to" type="date" name="lead_date_to" value="{{ $filterData['lead_date_to'] }}" max="{{ now()->toDateString() }}" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-amber-500 focus:ring-amber-500"></div>
                    <div><label for="lead-form-id" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.lead_analytics.form') }}</label><select id="lead-form-id" name="lead_form_id" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-amber-500 focus:ring-amber-500"><option value="">{{ __('admin.analytics.filters.all') }}</option>@foreach ($filterOptions['forms'] as $form)<option value="{{ $form->id }}" @selected($filters->formId === (int) $form->id)>{{ $form->name }}</option>@endforeach</select></div>
                    <div><label for="lead-status" class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.analytics.status') }}</label><select id="lead-status" name="lead_status" class="block min-h-10 w-full rounded-md border-gray-300 text-sm focus:border-amber-500 focus:ring-amber-500"><option value="all">{{ __('admin.analytics.filters.all') }}</option>@foreach (\App\Models\LeadSubmission::STATUSES as $status)<option value="{{ $status }}" @selected($filters->status === $status)>{{ __('admin.leads.status.'.$status) }}</option>@endforeach</select></div>
                </div>
                <div class="flex justify-end"><button type="submit" name="lead_preset" value="custom" class="inline-flex min-h-10 items-center rounded-md bg-amber-600 px-4 text-sm font-semibold text-white hover:bg-amber-700"><i data-lucide="filter" class="mr-2 h-4 w-4"></i>{{ __('admin.analytics.filters.apply') }}</button></div>
            </form>
        </section>

        <section class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            @foreach ([
                ['key' => 'submissions', 'icon' => 'send'],
                ['key' => 'new', 'icon' => 'sparkles'],
                ['key' => 'pending', 'icon' => 'phone-call'],
                ['key' => 'converted', 'icon' => 'badge-check'],
                ['key' => 'conversion_rate', 'icon' => 'percent', 'suffix' => '%'],
            ] as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><p class="text-sm text-gray-500">{{ __('admin.analytics.lead_analytics.kpi.'.$card['key']) }}</p><i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 text-amber-600"></i></div><p class="mt-3 text-right font-mono text-3xl font-semibold tabular-nums text-gray-950">{{ $card['key'] === 'conversion_rate' ? number_format((float) ($kpis[$card['key']] ?? 0), 1) : number_format((int) ($kpis[$card['key']] ?? 0)) }}{{ $card['suffix'] ?? '' }}</p></article>
            @endforeach
        </section>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.lead_analytics.trend') }}</h2>
            <div class="mt-4">@include('admin.analytics._interactive-trend', ['series' => $summary['trend'] ?? [], 'chartLabel' => __('admin.analytics.lead_analytics.trend'), 'metrics' => [['key' => 'submissions', 'label' => __('admin.analytics.lead_analytics.kpi.submissions'), 'color' => '#d97706'], ['key' => 'converted', 'label' => __('admin.analytics.lead_analytics.kpi.converted'), 'color' => '#059669']]])</div>
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.lead_analytics.sources') }}</h2>
                <div class="mt-4 space-y-3">@forelse (($summary['sources'] ?? []) as $source)<article class="rounded-lg border border-gray-100 p-4"><div class="flex items-center justify-between gap-3"><span class="truncate text-sm font-medium text-gray-900">{{ $source['source'] !== '' ? $source['source'] : __('admin.growth_center.direct_source') }}</span><span class="font-mono text-lg font-semibold tabular-nums">{{ $source['submissions'] }}</span></div><p class="mt-1 text-right text-xs text-gray-500">{{ __('admin.analytics.lead_analytics.converted_count', ['count' => $source['converted']]) }}</p></article>@empty<p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</p>@endforelse</div>
            </section>
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between p-5"><h2 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.lead_analytics.recent') }}</h2><a href="{{ route('admin.leads.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">{{ __('admin.dashboard.view_all') }}</a></div>
                <div class="divide-y divide-gray-100">@forelse (($summary['recent'] ?? []) as $lead)@php $payload = is_array($lead->payload) ? $lead->payload : []; $identity = collect(['name', 'email', 'phone'])->map(fn ($key) => trim((string) ($payload[$key] ?? '')))->first(fn ($value) => $value !== '') ?: '#'.$lead->id; @endphp<a href="{{ route('admin.leads.show', $lead->id) }}" class="flex min-h-14 items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50"><div class="min-w-0"><p class="truncate text-sm font-medium text-gray-900">{{ $identity }}</p><p class="mt-1 truncate text-xs text-gray-500">{{ $lead->form?->name ?? __('admin.leads.deleted_form') }}</p></div><div class="text-right"><span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">{{ __('admin.leads.status.'.$lead->status) }}</span><p class="mt-1 font-mono text-xs tabular-nums text-gray-400">{{ $lead->created_at?->format('m-d H:i') }}</p></div></a>@empty<p class="p-8 text-center text-sm text-gray-500">{{ __('admin.analytics.no_data') }}</p>@endforelse</div>
            </section>
        </div>
    </div>
@endsection
