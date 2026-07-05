@extends('admin.layouts.app')

@php
    $leadForm = $leadForm ?? null;
    $rows = collect($fields ?? [])->values()->all();
    while (count($rows) < 8) {
        $rows[] = ['name' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => []];
    }
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.lead_forms.form_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.lead-forms.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                {{ __('admin.lead_forms.back_to_list') }}
            </a>
        </div>

        <form method="POST" action="{{ $formAction }}" class="space-y-6">
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.lead_forms.basic_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.lead_forms.basic_desc') }}</p>
                </div>
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.name') }}</label>
                        <input type="text" name="name" value="{{ old('name', $leadForm?->name ?? '') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.slug') }}</label>
                        <input type="text" name="slug" value="{{ old('slug', $leadForm?->slug ?? '') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="contact-us">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.status') }}</label>
                        <select name="status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach (['active', 'inactive'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $leadForm?->status ?? 'active') === $status)>{{ __('admin.lead_forms.status.'.$status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.submit_button_label') }}</label>
                        <input type="text" name="submit_button_label" value="{{ old('submit_button_label', $leadForm?->submit_button_label ?? __('admin.lead_forms.default_submit_button')) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.description') }}</label>
                        <textarea name="description" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description', $leadForm?->description ?? '') }}</textarea>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.lead_forms.field.success_message') }}</label>
                        <textarea name="success_message" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('success_message', $leadForm?->success_message ?? '') }}</textarea>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.lead_forms.fields_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.lead_forms.fields_desc') }}</p>
                </div>
                <div class="space-y-4">
                    @foreach ($rows as $index => $field)
                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4">
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                                <div class="lg:col-span-3">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.lead_forms.field.label') }}</label>
                                    <input type="text" name="fields[{{ $index }}][label]" value="{{ $field['label'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.lead_forms.field.field_name') }}</label>
                                    <input type="text" name="fields[{{ $index }}][name]" value="{{ $field['name'] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.lead_forms.field.type') }}</label>
                                    <select name="fields[{{ $index }}][type]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @foreach (\App\Models\LeadForm::FIELD_TYPES as $type)
                                            <option value="{{ $type }}" @selected(($field['type'] ?? 'text') === $type)>{{ __('admin.lead_forms.type.'.$type) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="lg:col-span-4">
                                    <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.lead_forms.field.options') }}</label>
                                    <textarea name="fields[{{ $index }}][options]" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.lead_forms.options_placeholder') }}">{{ implode("\n", $field['options'] ?? []) }}</textarea>
                                </div>
                                <div class="flex items-end lg:col-span-1">
                                    <label class="mb-2 flex items-center gap-2 text-sm font-medium text-gray-700">
                                        <input type="checkbox" name="fields[{{ $index }}][required]" value="1" @checked(!empty($field['required'])) class="rounded border-gray-300 text-blue-600">
                                        {{ __('admin.lead_forms.field.required') }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.lead-forms.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.lead_forms.save_button') }}
                </button>
            </div>
        </form>
    </div>
@endsection
