@props([
    'job',
    'showTechnical' => false,
])

@php
    $status = (string) ($job['status'] ?? 'pending');
    $statusClasses = match ($status) {
        'running' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'pending' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'failed' => 'bg-red-50 text-red-700 ring-red-200',
        'cancelled' => 'bg-amber-50 text-amber-700 ring-amber-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

<article id="job-{{ (int) ($job['id'] ?? 0) }}" {{ $attributes->merge(['class' => 'grid gap-4 px-5 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center']) }} data-job-row>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $statusClasses }}">
                {{ $job['status_label'] ?? __('admin.tasks.jobs.status.pending') }}
            </span>
            <span class="text-xs text-gray-500 tabular-nums">
                {{ $job['updated_at'] ?? '' }}
            </span>
        </div>
        <p class="mt-2 text-sm font-semibold leading-6 text-gray-900">{{ $job['summary'] ?? '' }}</p>
        <p class="mt-1 text-sm leading-6 {{ $status === 'failed' ? 'text-red-700' : 'text-gray-600' }}">{{ $job['explanation'] ?? '' }}</p>

        @if ($showTechnical)
            <details class="mt-3 text-xs text-gray-500">
                <summary class="w-fit cursor-pointer rounded text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">{{ __('admin.tasks.monitoring.technical_details') }}</summary>
                <div class="mt-2 grid gap-1 rounded-lg bg-gray-50 px-3 py-2.5 font-mono leading-5">
                    <span>Job #{{ (int) ($job['id'] ?? 0) }} · {{ __('admin.tasks.jobs.task_prefix') }} #{{ (int) ($job['task_id'] ?? 0) }}</span>
                    <span>{{ __('admin.tasks.jobs.started_at') }}: {{ $job['started_at'] ?: '-' }}</span>
                    <span>{{ __('admin.tasks.jobs.finished_at') }}: {{ $job['finished_at'] ?: '-' }}</span>
                    <span>{{ __('admin.tasks.jobs.duration') }}: {{ number_format(max(0, (int) ($job['duration_ms'] ?? 0)) / 1000, 2) }}s</span>
                    @if ((int) ($job['max_attempts'] ?? 0) > 0)
                        <span>{{ __('admin.tasks.jobs.attempts') }}: {{ (int) ($job['attempt_count'] ?? 0) }} / {{ (int) $job['max_attempts'] }}</span>
                    @endif
                </div>
            </details>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
        @if (empty($job['task_deleted']))
            <a href="{{ route('admin.articles.index', ['task_id' => (int) ($job['task_id'] ?? 0)]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96]">
                <i data-lucide="list-tree" class="h-4 w-4" aria-hidden="true"></i>
                {{ __('admin.tasks.monitoring.view_task_content') }}
            </a>
        @endif
        @if (!empty($job['article_id']) && empty($job['article_deleted']))
            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $job['article_id']]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-md bg-blue-600 px-3 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96]">
                {{ __('admin.tasks.monitoring.view_article') }}
                <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
            </a>
        @endif
        @if (!empty($job['task_deleted']) || !empty($job['article_deleted']))
            <span class="text-xs leading-5 text-gray-500">{{ __('admin.tasks.monitoring.record_deleted') }}</span>
        @endif
    </div>
</article>
