@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <header>
            <div class="sr-only">
                <h1>{{ __('admin.tasks.worker.page_title') }}</h1>
                <p>{{ __('admin.tasks.worker.page_subtitle') }}</p>
            </div>
            <x-admin.v3.tasks-subnav active="workers" />
        </header>

        <section class="overflow-hidden rounded-lg bg-white shadow" aria-labelledby="workers-heading">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h2 id="workers-heading" class="text-lg font-medium text-gray-900">{{ __('admin.tasks.worker.title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.tasks.worker.explanation') }}</p>
            </div>
            <div class="divide-y divide-gray-200">
                @forelse ($workers as $worker)
                    <x-admin.task-monitoring.worker-row :worker="$worker" :show-technical="true" />
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gray-500">{{ __('admin.tasks.worker.none') }}</div>
                @endforelse
            </div>
            @if ($workers->hasPages())
                <div class="border-t border-gray-200 px-5 py-4 sm:px-6">{{ $workers->onEachSide(1)->links() }}</div>
            @endif
        </section>
    </div>
@endsection
