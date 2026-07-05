@extends('admin.layouts.app')

@php
    $selectedStatus = (string) ($filters['status'] ?? '');
    $selectedFormId = (int) ($filters['form_id'] ?? 0);
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.leads.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.leads.page_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.analytics') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.back') }}
                </a>
                <a href="{{ route('admin.leads.export', request()->query()) }}" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                    <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.leads.export_button') }}
                </a>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach ([
                ['icon' => 'inbox', 'label' => __('admin.leads.stats.total'), 'value' => (int) ($stats['total'] ?? 0), 'class' => 'text-blue-600'],
                ['icon' => 'sparkles', 'label' => __('admin.leads.stats.new'), 'value' => (int) ($stats['new'] ?? 0), 'class' => 'text-amber-600'],
                ['icon' => 'phone-call', 'label' => __('admin.leads.stats.pending'), 'value' => (int) ($stats['pending'] ?? 0), 'class' => 'text-rose-600'],
                ['icon' => 'badge-check', 'label' => __('admin.leads.stats.converted'), 'value' => (int) ($stats['converted'] ?? 0), 'class' => 'text-emerald-600'],
            ] as $card)
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center">
                        <i data-lucide="{{ $card['icon'] }}" class="h-6 w-6 {{ $card['class'] }}"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ $card['value'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.leads.index') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-6">
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.leads.filter.status') }}</label>
                    <select name="status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.leads.filter.all') }}</option>
                        @foreach (\App\Models\LeadSubmission::STATUSES as $status)
                            <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ __('admin.leads.status.'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.leads.filter.form') }}</label>
                    <select name="form_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.leads.filter.all') }}</option>
                        @foreach ($forms as $form)
                            <option value="{{ $form->id }}" @selected($selectedFormId === (int) $form->id)>{{ $form->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.leads.filter.date_from') }}</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.leads.filter.date_to') }}</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.leads.filter.search') }}</label>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.leads.filter.search_placeholder') }}">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        <i data-lucide="filter" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.leads.filter.apply') }}
                    </button>
                    <a href="{{ route('admin.leads.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.leads.filter.reset') }}</a>
                </div>
            </form>
        </section>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.leads.column.submission') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.leads.column.form') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.leads.column.status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.leads.column.source') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.leads.column.created_at') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($submissions as $submission)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="max-w-sm text-sm text-gray-900">
                                    @foreach (collect($submission->payload ?? [])->take(3) as $key => $value)
                                        <div class="truncate"><span class="font-medium">{{ $key }}:</span> {{ is_bool($value) ? ($value ? __('admin.common.yes') : __('admin.common.no')) : $value }}</div>
                                    @endforeach
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ $submission->form?->name ?? __('admin.leads.deleted_form') }}</td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <span class="inline-flex rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.leads.status.'.$submission->status) }}</span>
                            </td>
                            <td class="max-w-xs truncate px-6 py-4 text-sm text-gray-500">{{ $submission->source_url ?: __('admin.growth_center.direct_source') }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $submission->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                <a href="{{ route('admin.leads.show', ['submissionId' => $submission->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.leads.view_detail') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.leads.empty') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($submissions->hasPages())
                <div class="border-t border-gray-200 px-6 py-4">{{ $submissions->links() }}</div>
            @endif
        </div>
    </div>
@endsection
