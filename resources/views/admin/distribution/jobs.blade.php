@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <header class="flex items-start gap-3 sm:gap-4">
                <a href="{{ route('admin.distribution.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-gray-400 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-white [@media(hover:hover)]:hover:text-gray-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.jobs_heading') }}</h1>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.distribution.jobs_subtitle') }}</p>
                </div>
        </header>

        <div class="rounded-lg bg-white shadow">
            <form method="GET" action="{{ route('admin.distribution.jobs') }}" class="grid grid-cols-1 gap-4 border-b border-gray-200 px-6 py-4 md:grid-cols-4">
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.status') }}</label>
                    <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.distribution.filter.all_statuses') }}</option>
                        @foreach (['queued', 'sending', 'synced', 'failed', 'outcome_unknown'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('admin.distribution.job_status.'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="channel_id" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.field.channel') }}</label>
                    <select id="channel_id" name="channel_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="0">{{ __('admin.distribution.filter.all_channels') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ (int) $channel->id }}" @selected((int) ($filters['channel_id'] ?? 0) === (int) $channel->id)>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-3 md:col-span-2">
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="filter" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.filter') }}
                    </button>
                    <a href="{{ route('admin.distribution.jobs') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ __('admin.button.reset') }}
                    </a>
                </div>
            </form>
            @include('admin.distribution._jobs-table', ['jobs' => $jobs])
        </div>
    </div>
@endsection
