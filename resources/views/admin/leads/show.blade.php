@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.leads.detail_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.leads.detail_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.leads.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                {{ __('admin.leads.back_to_list') }}
            </a>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.leads.payload_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $submission->form?->name ?? __('admin.leads.deleted_form') }}</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach (($submission->payload ?? []) as $key => $value)
                        <div class="grid grid-cols-1 gap-2 px-6 py-4 sm:grid-cols-3">
                            <div class="text-sm font-medium text-gray-500">{{ $key }}</div>
                            <div class="text-sm text-gray-900 sm:col-span-2">
                                {{ is_bool($value) ? ($value ? __('admin.common.yes') : __('admin.common.no')) : $value }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.leads.handle_title') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.status') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ __('admin.leads.status.'.$submission->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.source') }}</dt>
                        <dd class="mt-1 break-words text-gray-900">{{ $submission->source_url ?: __('admin.growth_center.direct_source') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.ip') }}</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $submission->ip_address }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.user_agent') }}</dt>
                        <dd class="mt-1 break-words text-gray-900">{{ $submission->user_agent ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.created_at') }}</dt>
                        <dd class="mt-1 text-gray-900">{{ $submission->created_at?->format('Y-m-d H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.leads.meta.handler') }}</dt>
                        <dd class="mt-1 text-gray-900">{{ $submission->handler?->username ?? '-' }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.leads.update', ['submissionId' => $submission->id]) }}" class="mt-6 space-y-4 border-t border-gray-200 pt-6">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.leads.field.status') }}</label>
                        <select name="status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach (\App\Models\LeadSubmission::STATUSES as $status)
                                <option value="{{ $status }}" @selected(old('status', $submission->status) === $status)>{{ __('admin.leads.status.'.$status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.leads.field.note') }}</label>
                        <textarea name="note" rows="6" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('note', $submission->note ?? '') }}</textarea>
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.leads.save_button') }}
                    </button>
                </form>
            </section>
        </div>
    </div>
@endsection
