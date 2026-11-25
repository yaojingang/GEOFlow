@props([
    'worker',
    'showTechnical' => false,
])

@php
    $status = (string) ($worker['status'] ?? 'idle');
    $statusClasses = match ($status) {
        'running' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'stale' => 'bg-red-50 text-red-700 ring-red-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

<article {{ $attributes->merge(['class' => 'grid gap-4 px-5 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center']) }} data-worker-row>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $statusClasses }}">
                {{ $worker['status_label'] ?? __('admin.tasks.worker.status.idle') }}
            </span>
            <span class="text-xs text-gray-500">
                {{ __('admin.tasks.worker.last_seen') }} {{ $worker['last_seen_human'] ?? __('admin.tasks.worker.never_seen') }}
            </span>
        </div>
        <p class="mt-2 text-sm font-medium leading-6 text-gray-900">{{ $worker['summary'] ?? __('admin.tasks.worker.summary_idle') }}</p>
        @if (!empty($worker['task_name']))
            <p class="mt-1 text-xs leading-5 text-gray-500">
                {{ __('admin.tasks.worker.current_job') }} #{{ (int) ($worker['current_job_id'] ?? 0) }}
            </p>
        @endif

        @if ($showTechnical)
            <details class="mt-3 text-xs text-gray-500">
                <summary class="w-fit cursor-pointer rounded text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">{{ __('admin.tasks.monitoring.technical_details') }}</summary>
                <div class="mt-2 grid min-w-0 gap-1 rounded-lg bg-gray-50 px-3 py-2.5 font-mono leading-5">
                    <span class="break-all">{{ __('admin.tasks.worker.worker_id') }}: {{ $worker['worker_id'] ?? '' }}</span>
                    <span class="break-all">{{ __('admin.tasks.worker.last_seen') }}: {{ $worker['last_seen_at'] ?? '-' }}</span>
                    @if (isset($worker['memory_mb']))
                        <span>{{ __('admin.tasks.worker.memory') }}: {{ number_format((float) $worker['memory_mb'], 1) }} MB / {{ number_format((float) ($worker['peak_memory_mb'] ?? 0), 1) }} MB</span>
                    @endif
                </div>
            </details>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
        @if (!empty($worker['task_id']) && empty($worker['task_deleted']))
            <a href="{{ route('admin.articles.index', ['task_id' => (int) $worker['task_id']]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96]">
                <i data-lucide="list-tree" class="h-4 w-4" aria-hidden="true"></i>
                {{ __('admin.tasks.monitoring.view_task_content') }}
            </a>
        @endif
        @if (!empty($worker['article_id']) && empty($worker['article_deleted']))
            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $worker['article_id']]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md bg-blue-600 px-3 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96]">
                {{ __('admin.tasks.monitoring.view_article') }}
                <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
            </a>
        @elseif (!empty($worker['current_job_id']))
            <a href="{{ route('admin.tasks.jobs', ['run_id' => (int) $worker['current_job_id']]).'#job-'.(int) $worker['current_job_id'] }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md bg-blue-600 px-3 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96]">
                {{ __('admin.tasks.monitoring.view_run') }}
                <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
            </a>
        @endif
        @if ((!empty($worker['task_deleted']) || !empty($worker['article_deleted'])) && empty($worker['current_job_id']))
            <span class="text-xs leading-5 text-gray-500">{{ __('admin.tasks.monitoring.record_deleted') }}</span>
        @endif
    </div>
</article>
