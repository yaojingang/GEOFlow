@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <header>
            <div class="sr-only">
                <h1>{{ __('admin.tasks.jobs.page_title') }}</h1>
                <p>{{ __('admin.tasks.jobs.page_subtitle') }}</p>
            </div>
            <x-admin.v3.tasks-subnav active="jobs" />
            @if ($focusedRunId !== null)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded-full bg-blue-50 px-2.5 py-1 font-medium text-blue-700">{{ __('admin.tasks.jobs.focused_run', ['id' => $focusedRunId]) }}</span>
                    <a href="{{ route('admin.tasks.jobs') }}" class="font-semibold text-blue-700 hover:text-blue-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">{{ __('admin.tasks.jobs.show_all') }}</a>
                </div>
            @endif
        </header>

        <section class="overflow-hidden rounded-lg bg-white shadow" aria-labelledby="jobs-heading">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h2 id="jobs-heading" class="text-lg font-medium text-gray-900">{{ __('admin.tasks.jobs.all') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.tasks.jobs.explanation_copy') }}</p>
            </div>
            <div class="divide-y divide-gray-200">
                @forelse ($jobs as $job)
                    <x-admin.task-monitoring.job-row :job="$job" :show-technical="true" />
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gray-500">{{ __('admin.tasks.jobs.none') }}</div>
                @endforelse
            </div>
            @if ($jobs->hasPages())
                <div class="border-t border-gray-200 px-5 py-4 sm:px-6">{{ $jobs->onEachSide(1)->links() }}</div>
            @endif
        </section>
    </div>
@endsection
