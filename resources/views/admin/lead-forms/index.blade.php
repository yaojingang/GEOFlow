@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.lead_forms.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.lead_forms.page_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.analytics') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.growth_center.back') }}
                </a>
                <a href="{{ route('admin.lead-forms.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.lead_forms.create_button') }}
                </a>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center">
                    <i data-lucide="clipboard-list" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-5">
                        <div class="text-sm text-gray-500">{{ __('admin.lead_forms.stats.total') }}</div>
                        <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['total'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center">
                    <i data-lucide="toggle-right" class="h-6 w-6 text-emerald-600"></i>
                    <div class="ml-5">
                        <div class="text-sm text-gray-500">{{ __('admin.lead_forms.stats.active') }}</div>
                        <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['active'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center">
                    <i data-lucide="inbox" class="h-6 w-6 text-amber-600"></i>
                    <div class="ml-5">
                        <div class="text-sm text-gray-500">{{ __('admin.lead_forms.stats.submissions') }}</div>
                        <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['submissions'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.lead_forms.list_title') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.lead_forms.column.name') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.lead_forms.column.slug') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.lead_forms.column.status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.lead_forms.column.submissions') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($forms as $form)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold text-gray-900">{{ $form->name }}</div>
                                @if(trim((string) $form->description) !== '')
                                    <div class="mt-1 max-w-md truncate text-xs text-gray-500">{{ $form->description }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                <a href="{{ route('site.lead-forms.show', ['slug' => $form->slug]) }}" target="_blank" rel="noopener" class="font-mono text-blue-600 hover:text-blue-700">/forms/{{ $form->slug }}</a>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $form->isActive() ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ __('admin.lead_forms.status.'.$form->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ (int) $form->submissions_count }}</td>
                            <td class="px-6 py-4 text-right text-sm font-medium">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route('admin.lead-forms.edit', ['formId' => $form->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.edit') }}</a>
                                    <form method="POST" action="{{ route('admin.lead-forms.toggle-status', ['formId' => $form->id]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                            {{ $form->isActive() ? __('admin.lead_forms.action.disable') : __('admin.lead_forms.action.enable') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.lead-forms.delete', ['formId' => $form->id]) }}" onsubmit="return confirm('{{ __('admin.lead_forms.confirm_delete') }}')">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">{{ __('admin.button.delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.lead_forms.empty') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($forms->hasPages())
                <div class="border-t border-gray-200 px-6 py-4">{{ $forms->links() }}</div>
            @endif
        </div>
    </div>
@endsection
