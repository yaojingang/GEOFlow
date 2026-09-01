@extends('admin.layouts.app')

@php
        $formatTaskErrorSnippet = static function (?string $message, int $maxLength = 72): string {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }
        if (str_contains($message, '任务已暂停') || str_contains($message, '管理员手动停止')) {
            return __('admin.tasks.failure.paused_detail');
        }
        if (str_contains($message, 'AI返回空正文')) {
            return __('admin.tasks.failure.empty_content_detail');
        }
        if (str_contains($message, '正文过短')) {
            return __('admin.tasks.failure.content_too_short_detail');
        }
        if (str_contains($message, '没有可用的标题')) {
            return __('admin.tasks.failure.title_exhausted_detail');
        }
        if (preg_match('/CURL错误:\s*Operation timed out after\s+(\d+)\s+milliseconds/i', $message, $matches)) {
            $seconds = max(1, (int) round(((int) $matches[1]) / 1000));
            return __('admin.tasks.failure.model_timeout_detail', ['seconds' => $seconds]);
        }
        if (mb_strlen($message, 'UTF-8') <= $maxLength) {
            return $message;
        }
        return mb_substr($message, 0, $maxLength - 1, 'UTF-8').'…';
    };
    $describeTaskFailure = static function (?string $message) use ($formatTaskErrorSnippet): array {
        $message = trim((string) $message);
        if ($message === '') {
            return ['label' => __('admin.tasks.failure.execution_failed'), 'detail' => '', 'tone' => 'red'];
        }
        if (str_contains($message, 'AI返回空正文')) {
            return ['label' => __('admin.tasks.failure.empty_content'), 'detail' => __('admin.tasks.failure.empty_content_detail'), 'tone' => 'red'];
        }
        if (str_contains($message, '正文过短')) {
            return ['label' => __('admin.tasks.failure.content_too_short'), 'detail' => __('admin.tasks.failure.content_too_short_detail'), 'tone' => 'amber'];
        }
        if (str_contains($message, '没有可用的标题')) {
            return ['label' => __('admin.tasks.failure.title_exhausted'), 'detail' => __('admin.tasks.failure.title_exhausted_detail'), 'tone' => 'amber'];
        }
        if (str_contains($message, '任务已暂停') || str_contains($message, '管理员手动停止')) {
            return ['label' => __('admin.tasks.failure.paused'), 'detail' => __('admin.tasks.failure.paused_detail'), 'tone' => 'slate'];
        }
        return ['label' => __('admin.tasks.failure.execution_failed'), 'detail' => $formatTaskErrorSnippet($message, 110), 'tone' => 'red'];
    };
    $getFailureToneClasses = static function (string $tone): array {
        return match ($tone) {
            'amber' => ['chip' => 'bg-amber-50 text-amber-700 border-amber-200', 'card' => 'border-amber-200 bg-amber-50 text-amber-800', 'detail' => 'text-amber-700'],
            'slate' => ['chip' => 'bg-slate-50 text-slate-700 border-slate-200', 'card' => 'border-slate-200 bg-slate-50 text-slate-800', 'detail' => 'text-slate-600'],
            default => ['chip' => 'bg-red-50 text-red-700 border-red-200', 'card' => 'border-red-200 bg-red-50 text-red-800', 'detail' => 'text-red-700'],
        };
    };
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-task-realtime>
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin.tasks.page_title') }}</h1>
                    <p>{{ __('admin.tasks.page_subtitle') }}</p>
                </div>
                <x-admin.v3.tasks-subnav active="task-list" />
            </div>
            <div class="flex flex-wrap gap-3 sm:justify-end">
                <a href="{{ route('admin.tasks.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.button.create_task') }}
                </a>
                <button type="button" data-run-all-tasks class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.button.run_all_tasks') }}
                </button>
            </div>
        </div>

        @if (!empty($legacyError))
            <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <span class="block sm:inline">{{ $legacyError }}</span>
            </div>
        @endif

        <section class="mb-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="task-overview-heading">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h2 id="task-overview-heading" class="text-lg font-medium text-gray-900">{{ __('admin.tasks.monitoring.overview_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.tasks.monitoring.overview_description') }}</p>
            </div>
            <dl class="grid grid-cols-2 divide-x divide-y divide-gray-200 sm:grid-cols-4">
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_tasks') }}</dt>
                    <dd id="stats-total-tasks" class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ (int) ($taskSummary['total_tasks'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-gray-500">{{ __('admin.tasks.stats.enabled') }}</dt>
                    <dd id="stats-enabled-tasks" class="mt-1 text-2xl font-semibold tabular-nums text-emerald-700">{{ (int) ($taskSummary['enabled_tasks'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_articles') }}</dt>
                    <dd id="stats-total-articles" class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ (int) ($taskSummary['total_articles'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_published') }}</dt>
                    <dd id="stats-total-published" class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ (int) ($taskSummary['published_articles'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-blue-700">{{ __('admin.tasks.queue.pending') }}</dt>
                    <dd id="queue-pending" class="mt-1 text-2xl font-semibold tabular-nums text-blue-800">{{ (int) ($queueStats['pending'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-emerald-700">{{ __('admin.tasks.queue.running') }}</dt>
                    <dd id="queue-running" class="mt-1 text-2xl font-semibold tabular-nums text-emerald-800">{{ (int) ($queueStats['running'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-red-700">{{ __('admin.tasks.queue.failed') }}</dt>
                    <dd id="queue-failed" class="mt-1 text-2xl font-semibold tabular-nums text-red-700">{{ (int) ($queueStats['failed'] ?? 0) }}</dd>
                </div>
                <div class="flex flex-col items-center justify-center px-5 py-4 text-center sm:px-6">
                    <dt class="text-sm text-gray-500">{{ __('admin.tasks.queue.completed') }}</dt>
                    <dd id="queue-completed" class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ (int) ($queueStats['completed'] ?? 0) }}</dd>
                </div>
            </dl>
        </section>

        <div class="bg-white shadow rounded-lg" data-task-list>
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.tasks.list_title') }}</h3>
            </div>

            @if (empty($tasks))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.tasks.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.tasks.empty_desc') }}</p>
                    <a href="{{ route('admin.tasks.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.new_task') }}
                    </a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1200px] table-fixed divide-y divide-gray-200" data-sticky-actions data-task-list-table>
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.name') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.created_at') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.model') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.article_stats') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.loop_count') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.status') }}</th>
                            <th class="w-[11.5rem] py-3 pl-3 pr-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sm:w-[12.5rem] sm:pl-4 sm:pr-5">{{ __('admin.tasks.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($tasks as $task)
                            @php
                                $failureInfo = $describeTaskFailure($task['batch_error_message'] ?? '');
                                $failureClasses = $getFailureToneClasses($failureInfo['tone']);
                                $hasVisibleFailure = !empty($task['batch_error_message']) && in_array($task['batch_status'], ['failed', 'cancelled'], true);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-medium leading-6 text-gray-900 break-words">{{ $task['name'] ?? '' }}</div>
                                    <div class="mt-1 text-sm text-gray-500 break-words">{{ __('admin.tasks.label.title_library') }}: {{ $task['title_library_name'] ?? '' }}</div>
                                    @if ($hasVisibleFailure)
                                        <div class="mt-2 rounded-md border px-3 py-2 text-xs {{ $failureClasses['card'] }}">
                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-medium {{ $failureClasses['chip'] }}">{{ $failureInfo['label'] }}</span>
                                            @if (!empty($failureInfo['detail']))
                                                <div class="mt-1 {{ $failureClasses['detail'] }}">{{ $failureInfo['detail'] }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">{{ !empty($task['created_at']) ? \Illuminate\Support\Carbon::parse($task['created_at'])->format('Y-m-d H:i') : '' }}</td>
                                <td class="px-5 py-4 align-top text-sm text-gray-500">
                                    <div class="break-words leading-6">{{ $task['ai_model_name'] ?? '' }}</div>
                                    <div class="mt-1">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ (($task['model_selection_mode'] ?? 'fixed') === 'smart_failover') ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-700' }}">
                                            {{ (($task['model_selection_mode'] ?? 'fixed') === 'smart_failover') ? __('admin.tasks.mode.smart_failover') : __('admin.tasks.mode.fixed') }}
                                        </span>
                                    </div>
                                    @if($task['ai_quality_enabled'] ?? false)
                                        <div class="mt-2 rounded-md border border-blue-100 bg-blue-50 px-2.5 py-2 text-xs text-blue-800">
                                            <div class="font-semibold">{{ __('admin.tasks.ai_quality.enabled') }}</div>
                                            <div class="mt-0.5 max-w-[190px] truncate" title="{{ $task['ai_quality_prompt_name'] ?? '' }}">{{ $task['ai_quality_prompt_name'] ?? '' }}</div>
                                            <div class="mt-0.5">{{ __('admin.tasks.ai_quality.thresholds', ['pass' => (int) ($task['ai_quality_pass_score'] ?? 85), 'floor' => (int) ($task['ai_quality_manual_override_min_score'] ?? 70)]) }}</div>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                    @php
                                        $articleLimit = max(1, (int) ($task['article_limit'] ?? $task['draft_limit'] ?? 10));
                                        $createdForProgress = min($articleLimit, (int) ($task['created_count'] ?? $task['total_articles'] ?? 0));
                                        $progressPercent = (int) floor(($createdForProgress / $articleLimit) * 100);
                                        $distributionTotal = (int) ($task['distribution_total_count'] ?? 0);
                                        $distributionSynced = (int) ($task['distribution_synced_count'] ?? 0);
                                        $distributionFailed = (int) ($task['distribution_failed_count'] ?? 0);
                                        $distributionPending = max(0, $distributionTotal - $distributionSynced - $distributionFailed);
                                        $taskDistributionBadge = null;
                                        if ($distributionTotal > 0) {
                                            if ($distributionFailed > 0) {
                                                $taskDistributionBadge = [
                                                    'label' => __('admin.distribution.task_status.failed', ['count' => $distributionFailed]),
                                                    'class' => 'bg-red-50 text-red-700 ring-red-100',
                                                ];
                                            } elseif ($distributionSynced >= $distributionTotal) {
                                                $taskDistributionBadge = [
                                                    'label' => __('admin.distribution.task_status.synced', ['count' => $distributionTotal]),
                                                    'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                                ];
                                            } else {
                                                $taskDistributionBadge = [
                                                    'label' => __('admin.distribution.task_status.queued', ['count' => $distributionPending]),
                                                    'class' => 'bg-sky-50 text-sky-700 ring-sky-100',
                                                ];
                                            }
                                        }
                                    @endphp
                                    <div id="task-created-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.created_of_limit', ['created' => (int) ($task['created_count'] ?? $task['total_articles'] ?? 0), 'limit' => $articleLimit]) }}</div>
                                    <div id="task-published-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.published_articles', ['count' => (int) ($task['published_articles'] ?? 0)]) }}</div>
                                    <div id="task-drafts-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.draft_articles', ['count' => (int) ($task['draft_articles'] ?? 0)]) }}</div>
                                    <div class="mt-2 h-1.5 w-28 overflow-hidden rounded-full bg-gray-200">
                                        <div id="task-progress-{{ (int) $task['id'] }}" class="h-full rounded-full bg-blue-600" style="width: {{ $progressPercent }}%"></div>
                                    </div>
                                    @if($taskDistributionBadge !== null)
                                        <div class="mt-2">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $taskDistributionBadge['class'] }}">
                                                <i data-lucide="send" class="mr-1 h-3 w-3"></i>
                                                {{ $taskDistributionBadge['label'] }}
                                            </span>
                                        </div>
                                    @endif
                                    @if($task['ai_quality_enabled'] ?? false)
                                        @php
                                            $qualityStats = is_array($task['ai_quality_stats'] ?? null)
                                                ? $task['ai_quality_stats']
                                                : [];
                                        @endphp
                                        <div class="mt-2 flex max-w-[210px] flex-wrap gap-1">
                                            <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id'], 'ai_quality_status' => 'passed']) }}" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100">{{ __('admin.tasks.ai_quality.passed_count', ['count' => (int) ($qualityStats['passed'] ?? 0)]) }}</a>
                                            <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id'], 'ai_quality_status' => 'needs_review']) }}" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-100">{{ __('admin.tasks.ai_quality.review_count', ['count' => (int) ($qualityStats['needs_review'] ?? 0)]) }}</a>
                                            <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id'], 'ai_quality_status' => 'blocked']) }}" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-100">{{ __('admin.tasks.ai_quality.blocked_count', ['count' => (int) ($qualityStats['blocked'] ?? 0)]) }}</a>
                                            @if((int) ($qualityStats['pending'] ?? 0) > 0)
                                                <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id'], 'ai_quality_status' => 'pending']) }}" class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-sky-100">{{ __('admin.tasks.ai_quality.pending_count', ['count' => (int) $qualityStats['pending']]) }}</a>
                                            @endif
                                            @php
                                                $optimizationStats = is_array($task['ai_quality_optimization_stats'] ?? null)
                                                    ? $task['ai_quality_optimization_stats']
                                                    : [];
                                            @endphp
                                            @if((int) ($optimizationStats['active'] ?? 0) > 0)
                                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-100">{{ __('admin.tasks.ai_quality.optimizing_count', ['count' => (int) $optimizationStats['active']]) }}</span>
                                            @endif
                                            @if((int) ($optimizationStats['needs_review'] ?? 0) > 0)
                                                <span class="rounded-full bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700 ring-1 ring-orange-100">{{ __('admin.tasks.ai_quality.optimization_review_count', ['count' => (int) $optimizationStats['needs_review']]) }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                    <span id="task-loop-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.loop_times', ['count' => (int) ($task['loop_count'] ?? 0)]) }}</span>
                                    <div id="task-publish-interval-{{ (int) $task['id'] }}" class="mt-1 text-xs text-gray-400">
                                        {{ __('admin.tasks.label.publish_interval_minutes', ['count' => max(1, (int) ceil(((int) ($task['publish_interval'] ?? 3600)) / 60))]) }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    @if($task['can_manage'] ?? true)
                                        <form method="POST" action="{{ route('admin.tasks.toggle-status', ['taskId' => (int) $task['id']]) }}" class="inline" id="status-form-{{ (int) $task['id'] }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $task['status'] }}">
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" @checked(($task['status'] ?? '') === 'active') data-task-status-toggle data-task-id="{{ (int) $task['id'] }}" class="rounded border-gray-300 text-blue-600 shadow-sm">
                                                <span class="ml-2 text-sm {{ ($task['status'] ?? '') === 'active' ? 'text-green-600' : 'text-gray-500' }}">
                                                    {{ ($task['status'] ?? '') === 'active' ? __('admin.tasks.status.enabled') : __('admin.tasks.status.disabled') }}
                                                </span>
                                            </label>
                                        </form>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                            <i data-lucide="lock-keyhole" class="h-3.5 w-3.5"></i>
                                            {{ __('admin.tasks.action.super_admin_managed') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="w-[11.5rem] py-4 pl-3 pr-4 align-top sm:w-[12.5rem] sm:pl-4 sm:pr-5">
                                    <div class="flex items-center justify-end gap-1.5 sm:gap-2">
                                        @if($task['can_manage'] ?? true)
                                            @if (($task['status'] ?? '') === 'active')
                                                <button type="button" data-batch-action="stop" data-task-id="{{ (int) $task['id'] }}" data-task-name="{{ $task['name'] ?? '' }}" class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200" title="{{ __('admin.tasks.action.stop_batch') }}" aria-label="{{ __('admin.tasks.action.stop_batch') }}" id="batch-btn-{{ (int) $task['id'] }}">
                                                    <i data-lucide="square" class="w-4 h-4"></i>
                                                </button>
                                            @else
                                                <button type="button" data-batch-action="start" data-task-id="{{ (int) $task['id'] }}" data-task-name="{{ $task['name'] ?? '' }}" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200" title="{{ __('admin.tasks.action.start_batch') }}" aria-label="{{ __('admin.tasks.action.start_batch') }}" id="batch-btn-{{ (int) $task['id'] }}">
                                                    <i data-lucide="play" class="w-4 h-4"></i>
                                                </button>
                                            @endif

                                            <a href="{{ route('admin.tasks.edit', ['taskId' => (int) $task['id']]) }}" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors border border-blue-200" title="{{ __('admin.tasks.action.settings') }}">
                                                <i data-lucide="settings" class="w-4 h-4"></i>
                                            </a>
                                        @endif

                                        <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id']]) }}" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200" title="{{ __('admin.tasks.action.articles') }}">
                                            <i data-lucide="file-text" class="w-4 h-4"></i>
                                        </a>

                                        @if($task['can_manage'] ?? true)
                                            <form method="POST" action="{{ route('admin.tasks.delete', ['taskId' => (int) $task['id']]) }}" class="inline" data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.tasks.delete_dialog.title') }} “{{ $task['name'] ?? '' }}”" data-admin-confirm-message="{{ __('admin.tasks.delete_dialog.impact') }}" data-admin-confirm-label="{{ __('admin.tasks.delete_dialog.confirm') }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center justify-center w-8 h-8 text-red-600 [@media(hover:hover)]:hover:text-red-800 [@media(hover:hover)]:hover:bg-red-50 rounded-md transition-[background-color,color,transform] duration-150 active:scale-[.96] border border-red-200" title="{{ __('admin.tasks.action.delete') }}" aria-label="{{ __('admin.tasks.action.delete') }}" data-admin-confirm-submit disabled aria-disabled="true">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                    <div class="mt-2 max-w-[165px]" id="batch-status-{{ (int) $task['id'] }}"></div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ((int) ($pagination['total_pages'] ?? 1) > 1)
                    <div class="flex items-center justify-between border-t border-gray-200 px-5 py-4">
                        <span class="text-sm text-gray-500">
                            {{ (int) ($pagination['total'] ?? 0) }} · {{ (int) ($pagination['page'] ?? 1) }} / {{ (int) ($pagination['total_pages'] ?? 1) }}
                        </span>
                        <div class="flex items-center gap-2">
                            @if ((int) ($pagination['page'] ?? 1) > 1)
                                <a href="{{ route('admin.tasks.index', ['page' => (int) $pagination['page'] - 1]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('pagination.previous') }}</a>
                            @endif
                            @if ((int) ($pagination['page'] ?? 1) < (int) ($pagination['total_pages'] ?? 1))
                                <a href="{{ route('admin.tasks.index', ['page' => (int) $pagination['page'] + 1]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('pagination.next') }}</a>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>

        <details id="task-trash" class="group mt-6 overflow-hidden rounded-lg bg-white shadow" data-task-trash @if($taskTrashOpen) open @endif>
            <summary class="flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 active:scale-[.995] [&::-webkit-details-marker]:hidden">
                <span class="flex min-w-0 items-center gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600" aria-hidden="true">
                        <i data-lucide="trash-2" class="h-5 w-5"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-semibold text-gray-900">{{ __('admin.tasks.trash.title') }}</span>
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 tabular-nums">
                                {{ __('admin.tasks.trash.count', ['count' => (int) ($trashPagination['total'] ?? 0)]) }}
                            </span>
                        </span>
                        <span class="mt-0.5 block text-sm leading-6 text-gray-500">
                            {{ __('admin.tasks.trash.retention', ['days' => $taskTrashRetentionDays]) }}
                        </span>
                    </span>
                </span>
                <i data-lucide="chevron-down" class="h-5 w-5 shrink-0 text-gray-500 transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true"></i>
            </summary>

            <div class="border-t border-gray-200" data-task-trash-content>
                @if (empty($trashedTasks))
                    <div class="px-6 py-8 text-center">
                        <i data-lucide="archive" class="mx-auto h-9 w-9 text-gray-300" aria-hidden="true"></i>
                        <p class="mt-3 text-sm text-gray-500">{{ __('admin.tasks.trash.empty') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full divide-y divide-gray-200 sm:min-w-[900px]">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.trash.column.name') }}</th>
                                    <th class="hidden px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 sm:table-cell">{{ __('admin.tasks.trash.column.created_at') }}</th>
                                    <th class="hidden px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 sm:table-cell">{{ __('admin.tasks.trash.column.deleted_at') }}</th>
                                    <th class="hidden px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 sm:table-cell">{{ __('admin.tasks.trash.column.expires_at') }}</th>
                                    <th class="sticky right-0 z-10 bg-gray-50 px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.trash.column.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($trashedTasks as $task)
                                    <tr class="group [@media(hover:hover)]:hover:bg-gray-50">
                                        <td class="px-5 py-4 text-sm">
                                            <div class="font-medium leading-6 text-gray-900 break-words">{{ $task['name'] }}</div>
                                            <div class="mt-0.5 text-xs text-gray-400 tabular-nums">#{{ $task['id'] }}</div>
                                        </td>
                                        <td class="hidden px-5 py-4 text-sm text-gray-500 tabular-nums whitespace-nowrap sm:table-cell">
                                            {{ $task['created_at'] ? \Illuminate\Support\Carbon::parse($task['created_at'])->format('Y-m-d H:i') : '-' }}
                                        </td>
                                        <td class="hidden px-5 py-4 text-sm text-gray-600 tabular-nums whitespace-nowrap sm:table-cell">
                                            {{ \Illuminate\Support\Carbon::parse($task['deleted_at'])->format('Y-m-d H:i') }}
                                        </td>
                                        <td class="hidden px-5 py-4 text-sm text-gray-600 tabular-nums whitespace-nowrap sm:table-cell">
                                            {{ \Illuminate\Support\Carbon::parse($task['expires_at'])->format('Y-m-d H:i') }}
                                        </td>
                                        <td class="sticky right-0 z-10 bg-white px-5 py-4 text-right text-sm whitespace-nowrap shadow-[-8px_0_14px_-14px_rgba(15,23,42,0.45)] transition-colors [@media(hover:hover)]:group-hover:bg-gray-50">
                                            @if ($task['can_restore'] ?? true)
                                            <form
                                                method="POST"
                                                action="{{ route('admin.tasks.restore', [
                                                    'taskId' => (int) $task['id'],
                                                    'page' => (int) ($pagination['page'] ?? 1),
                                                    'trash_page' => (int) ($trashPagination['page'] ?? 1),
                                                    'trash_snapshot_id' => (int) ($trashPagination['snapshot_id'] ?? 0),
                                                    'trash_sequence' => (int) ($task['trash_sequence'] ?? 0),
                                                ]) }}"
                                                class="inline-flex"
                                                data-admin-confirm-form
                                                data-admin-confirm-tone="success"
                                                data-admin-confirm-title="{{ __('admin.tasks.trash.confirm_restore', ['name' => $task['name']]) }}"
                                                data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}"
                                                data-admin-confirm-label="{{ __('admin.tasks.trash.action_restore') }}"
                                            >
                                                @csrf
                                                <button type="submit" class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 shadow-sm transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-emerald-300 [@media(hover:hover)]:hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 active:scale-[.96]" data-admin-confirm-submit disabled aria-disabled="true">
                                                    <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
                                                    <span>{{ __('admin.tasks.trash.action_restore') }}</span>
                                                </button>
                                            </form>
                                            @else
                                                <span class="inline-flex min-h-9 items-center gap-1.5 rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-500">
                                                    <i data-lucide="lock-keyhole" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                                    {{ __('admin.tasks.trash.super_admin_restore') }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ((int) ($trashPagination['total_pages'] ?? 1) > 1)
                        <div class="flex items-center justify-between gap-4 border-t border-gray-200 px-5 py-4">
                            <span class="text-sm text-gray-500 tabular-nums">
                                {{ (int) ($trashPagination['total'] ?? 0) }} · {{ (int) ($trashPagination['page'] ?? 1) }} / {{ (int) ($trashPagination['total_pages'] ?? 1) }}
                            </span>
                            <div class="flex items-center gap-2">
                                @if ((int) ($trashPagination['page'] ?? 1) > 1)
                                    <a href="{{ route('admin.tasks.index', ['page' => (int) ($pagination['page'] ?? 1), 'trash_page' => (int) $trashPagination['page'] - 1, 'trash_snapshot_id' => (int) ($trashPagination['snapshot_id'] ?? 0)]).'#task-trash' }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 transition-colors [@media(hover:hover)]:hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:bg-gray-100">{{ __('pagination.previous') }}</a>
                                @endif
                                @if ((int) ($trashPagination['page'] ?? 1) < (int) ($trashPagination['total_pages'] ?? 1))
                                    <a href="{{ route('admin.tasks.index', ['page' => (int) ($pagination['page'] ?? 1), 'trash_page' => (int) $trashPagination['page'] + 1, 'trash_snapshot_id' => (int) ($trashPagination['snapshot_id'] ?? 0)]).'#task-trash' }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 transition-colors [@media(hover:hover)]:hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:bg-gray-100">{{ __('pagination.next') }}</a>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </details>

        <section class="mt-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="worker-overview-heading">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="worker-overview-heading" class="text-lg font-medium text-gray-900">{{ __('admin.tasks.worker.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.tasks.worker.explanation') }}</p>
                </div>
                <a href="{{ route('admin.tasks.workers') }}" class="inline-flex min-h-9 shrink-0 items-center gap-1.5 self-start rounded-md px-3 text-sm font-semibold text-blue-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:scale-[.96] sm:self-auto">
                    {{ __('admin.tasks.monitoring.view_more') }}
                    <i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i>
                </a>
            </div>
            <div id="worker-overview-container" class="divide-y divide-gray-200">
                @include('admin.tasks.partials.worker-overview', ['workers' => $workers])
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-lg bg-white shadow" aria-labelledby="recent-jobs-heading">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="recent-jobs-heading" class="text-lg font-medium text-gray-900">{{ __('admin.tasks.jobs.recent') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.tasks.jobs.explanation_copy') }}</p>
                </div>
                <a href="{{ route('admin.tasks.jobs') }}" class="inline-flex min-h-9 shrink-0 items-center gap-1.5 self-start rounded-md px-3 text-sm font-semibold text-blue-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:scale-[.96] sm:self-auto">
                    {{ __('admin.tasks.monitoring.view_more') }}
                    <i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i>
                </a>
            </div>
            <div id="recent-runs-container" class="divide-y divide-gray-200">
                @include('admin.tasks.partials.recent-runs', ['recentJobs' => $recentJobs])
            </div>
        </section>
    </div>

    <dialog
        class="fixed inset-0 m-auto w-[min(600px,calc(100vw-2rem))] max-w-none overflow-hidden overscroll-contain rounded-2xl border-0 bg-white p-0 text-left text-gray-900 shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
        data-task-index-readiness-dialog
        data-blocked-title="{{ __('admin.task_create.readiness.dialog_blocked_title') }}"
        data-warning-title="{{ __('admin.task_create.readiness.dialog_warning_title') }}"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="task-index-readiness-title"
        aria-describedby="task-index-readiness-summary task-index-readiness-recommendation"
    >
        <div class="flex max-h-[min(760px,calc(100dvh-2rem))] flex-col">
            <header class="flex items-start gap-4 px-6 pb-5 pt-6 max-[520px]:px-5">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600" data-task-index-readiness-icon-wrap aria-hidden="true">
                    <i data-lucide="triangle-alert" class="h-5 w-5"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">{{ __('admin.task_create.readiness.dialog_eyebrow') }}</p>
                    <h2 id="task-index-readiness-title" class="mt-1 text-xl font-semibold leading-7 text-gray-900 text-balance" data-task-index-readiness-title>{{ __('admin.task_create.readiness.dialog_blocked_title') }}</h2>
                </div>
                <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 [@media(hover:hover)]:hover:text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:scale-[.96]" data-task-index-readiness-close aria-label="{{ __('admin.common.close') }}">
                    <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                </button>
            </header>

            <div class="grid grid-cols-4 divide-x divide-gray-200 border-y border-gray-200 bg-gray-50 max-[520px]:grid-cols-2 max-[520px]:divide-x-0">
                @foreach ([
                    'remaining' => __('admin.task_create.readiness.stats.remaining'),
                    'total' => __('admin.task_create.readiness.stats.total'),
                    'used' => __('admin.task_create.readiness.stats.used'),
                    'available' => __('admin.task_create.readiness.stats.available'),
                ] as $statKey => $statLabel)
                    <div class="px-4 py-3 max-[520px]:border-b max-[520px]:border-gray-200 max-[520px]:px-5">
                        <p class="text-[11px] font-medium leading-4 text-gray-500">{{ $statLabel }}</p>
                        <p class="mt-0.5 text-lg font-semibold leading-6 text-gray-900 tabular-nums" data-task-index-readiness-stat="{{ $statKey }}">0</p>
                    </div>
                @endforeach
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5 max-[520px]:px-5">
                <p id="task-index-readiness-summary" class="text-sm leading-6 text-gray-700 text-pretty" data-task-index-readiness-summary></p>
                <div class="mt-5 space-y-3" data-task-index-readiness-issues></div>
                <div class="mt-5 rounded-xl bg-gray-50 px-4 py-3.5">
                    <p id="task-index-readiness-recommendation" class="text-sm leading-6 text-gray-700 text-pretty" data-task-index-readiness-recommendation></p>
                </div>
            </div>

            <footer class="flex flex-wrap justify-end gap-2.5 border-t border-gray-100 bg-gray-50 px-6 py-4 max-[520px]:flex-col max-[520px]:px-5">
                <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-index-readiness-close>{{ __('admin.common.close') }}</button>
                <a href="#" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-index-readiness-manage>{{ __('admin.task_create.readiness.actions.manage_library') }}</a>
                <a href="#" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-index-readiness-edit>{{ __('admin.tasks.action.settings') }}</a>
            </footer>
        </div>
    </dialog>

    <script type="application/json" data-task-index-readiness-initial>@json(session('title_readiness_report'))</script>
@endsection

@push('scripts')
@php
    $taskInitialOverview = [
        'tasks' => $tasks,
        'queue_overview' => $queueStats,
        'worker_overview' => $workers,
        'recent_runs' => $recentJobs,
        'pagination' => $pagination,
        'task_summary' => $taskSummary,
    ];
@endphp
<script>
const TASK_I18N = @json($taskI18n, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
const TASK_HEALTH_URL = @js(\App\Support\AdminWeb::routePath('admin.tasks.health').'?page='.(int) ($pagination['page'] ?? 1));
const TASK_BATCH_URL = @js(\App\Support\AdminWeb::routePath('admin.tasks.batch'));
const TASK_INITIAL_OVERVIEW = @json($taskInitialOverview, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
function renderIcons(target = document) {
    if (window.GeoFlowAdminUi?.refreshIcons) { window.GeoFlowAdminUi.refreshIcons(target); return; }
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
}

function showNotification(type, message) {
    if (type === 'error') {
        void window.AdminActionDialog?.alert?.({
            title: @json(__('admin.action_dialog.error_title')),
            message,
            guidance: @json(__('admin.action_dialog.error_guidance')),
            tone: 'error',
            confirmLabel: @json(__('admin.action_dialog.close')),
        });
        return;
    }
    window.GeoFlowAdminUi?.showToast?.(message, type);
}

function setButtonLoading(btn, text, classes) { btn.disabled = true; btn.className = classes; btn.innerHTML = `<i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i><span class="sr-only">${text}</span>`; renderIcons(btn); }

async function openTaskLifecycleDialog({ title, description, confirmLabel, tone = 'start', trigger = null, onConfirm, onCancel = null }) {
    if (!window.AdminActionDialog?.confirm) {
        onCancel?.();
        trigger?.focus?.();
        return;
    }
    const confirmed = await window.AdminActionDialog.confirm({
        title,
        message: String(description ?? '').replaceAll('\\n', '\n'),
        confirmLabel,
        tone: tone === 'stop' ? 'warning' : 'success',
        opener: trigger,
    });
    if (confirmed) onConfirm?.();
    else onCancel?.();
}

function updateBatchButton(btn, taskId, taskName, isActive) {
    if (!btn) return;
    btn.disabled = false;
    btn.dataset.batchAction = isActive ? 'stop' : 'start';
    btn.className = isActive ? 'inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200' : 'inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200';
    btn.innerHTML = isActive ? '<i data-lucide="square" class="w-4 h-4"></i>' : '<i data-lucide="play" class="w-4 h-4"></i>';
    btn.title = isActive ? TASK_I18N.stopBatch : TASK_I18N.startBatch;
    btn.setAttribute('aria-label', btn.title);
    btn.dataset.taskId = taskId;
    btn.dataset.taskName = taskName;
    renderIcons(btn);
}

function formatEstimatedTime(seconds) { if (seconds < 60) return `${seconds}${TASK_I18N.secondsSuffix}`; if (seconds < 3600) return `${Math.round(seconds / 60)}${TASK_I18N.minutesSuffix}`; if (seconds < 86400) return `${Math.round(seconds / 3600)}${TASK_I18N.hoursSuffix}`; return `${Math.round(seconds / 86400)}${TASK_I18N.daysSuffix}`; }

function escapeHtml(value) { return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function truncateText(value, maxLength) { return value.length <= maxLength ? value : `${value.slice(0, maxLength - 1)}…`; }
function normalizeRuntimeError(message) { return String(message || '').trim(); }
function getFailureMeta() { return {label: TASK_I18N.recentFailed, chipClasses: 'bg-red-50 text-red-700 border-red-200', detailClasses: 'text-red-700'}; }
function formatTaskDateTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    const pad = number => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function updateBatchStatus(task) {
    const statusDiv = document.getElementById(`batch-status-${task.id}`);
    if (!statusDiv) return;
    const createdCount = Number(task.created_count || 0);
    const articleLimit = Number(task.article_limit || task.draft_limit || 0);
    const pendingJobs = Number(task.pending_jobs || 0);
    const runningJobs = Number(task.running_jobs || 0);
    const isRunning = task.batch_status === 'running' || task.batch_status === 'pending';
    const errorMessage = normalizeRuntimeError(task.batch_error_message || '');
    if (!isRunning) {
        if (task.batch_status === 'failed') {
            const failureMeta = getFailureMeta(errorMessage);
            statusDiv.innerHTML = `<div class="flex flex-col gap-1 text-xs"><span class="inline-flex items-center justify-center rounded-full border px-2 py-1 ${failureMeta.chipClasses}">${escapeHtml(failureMeta.label)}</span>${errorMessage ? `<div class="mx-auto max-w-[220px] break-words leading-5 ${failureMeta.detailClasses}">${escapeHtml(truncateText(errorMessage, 60))}</div>` : ''}</div>`;
        } else if (task.batch_status === 'completed') {
            statusDiv.innerHTML = `<span class="text-xs text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-200">${escapeHtml(TASK_I18N.completed)}</span>`;
        } else if (task.batch_status === 'waiting') {
            const nextRunAt = formatTaskDateTime(task.next_run_at || '');
            statusDiv.innerHTML = `<div class="flex flex-col gap-1 text-xs"><span class="inline-flex w-fit items-center rounded-full border px-2 py-1 bg-slate-50 text-slate-700 border-slate-200">${escapeHtml(TASK_I18N.waiting)}</span>${nextRunAt ? `<div class="text-gray-500">${escapeHtml(TASK_I18N.nextRunAt.replace('__TIME__', nextRunAt))}</div>` : ''}</div>`;
        } else if (task.batch_status === 'waiting_publish') {
            const nextPublishAt = formatTaskDateTime(task.next_publish_at || task.next_run_at || '');
            statusDiv.innerHTML = `<div class="flex flex-col gap-1 text-xs"><span class="inline-flex w-fit items-center rounded-full border px-2 py-1 bg-cyan-50 text-cyan-700 border-cyan-200">${escapeHtml(TASK_I18N.waitingPublish)}</span>${nextPublishAt ? `<div class="text-gray-500">${escapeHtml(TASK_I18N.nextRunAt.replace('__TIME__', nextPublishAt))}</div>` : ''}</div>`;
        } else if (task.batch_status === 'draft_pool_full') {
            statusDiv.innerHTML = `<span class="text-xs text-orange-700 bg-orange-50 px-2 py-1 rounded-full border border-orange-200">${escapeHtml(TASK_I18N.draftPoolFull)}</span>`;
        } else if (task.batch_status === 'limit_reached') {
            statusDiv.innerHTML = `<span class="text-xs text-amber-700 bg-amber-50 px-2 py-1 rounded-full border border-amber-200">${escapeHtml(TASK_I18N.limitReached)}</span>`;
        } else { statusDiv.innerHTML = ''; }
        return;
    }
    const stateLabel = task.batch_status === 'pending' ? TASK_I18N.queued : TASK_I18N.running;
    const remainingArticles = Math.max(0, articleLimit - createdCount);
    const estimatedTime = formatEstimatedTime(remainingArticles * Number(task.publish_interval || 3600));
    statusDiv.innerHTML = `<div class="flex flex-col gap-1 text-xs"><div class="flex items-center gap-2"><span class="inline-flex items-center rounded-full border px-2 py-0.5 bg-blue-50 text-blue-700 border-blue-200"><i data-lucide="activity" class="h-3 w-3 mr-1"></i>${stateLabel}</span><span class="text-gray-600">${createdCount}/${articleLimit}</span></div><div class="text-gray-500">${TASK_I18N.pendingRunning.replace('__PENDING__', pendingJobs).replace('__RUNNING__', runningJobs)}${remainingArticles > 0 ? ` · ${TASK_I18N.estimated.replace('__TIME__', estimatedTime)}` : ''}</div></div>`;
    renderIcons(statusDiv);
}

function updateTaskUI(task) {
    const btn = document.getElementById(`batch-btn-${task.id}`);
    const isActive = task.status === 'active';
    updateBatchButton(btn, task.id, task.name, isActive);
    updateTaskStatusToggle(task.id, isActive);
    updateBatchStatus(task);
}

function updateTaskStatusToggle(taskId, isActive) {
    const form = document.getElementById(`status-form-${taskId}`);
    if (!form) return;
    const hidden = form.querySelector('input[name="status"]');
    const checkbox = form.querySelector('input[type="checkbox"]');
    const label = form.querySelector('span');
    if (hidden) hidden.value = isActive ? 'active' : 'paused';
    if (checkbox) checkbox.checked = isActive;
    if (label) {
        label.textContent = isActive ? TASK_I18N.enabledStatus : TASK_I18N.disabledStatus;
        label.className = `ml-2 text-sm ${isActive ? 'text-green-600' : 'text-gray-500'}`;
    }
}

function updateTaskCounters(task) {
    const createdEl = document.getElementById(`task-created-${task.id}`);
    const publishedEl = document.getElementById(`task-published-${task.id}`);
    const draftsEl = document.getElementById(`task-drafts-${task.id}`);
    const progressEl = document.getElementById(`task-progress-${task.id}`);
    const loopEl = document.getElementById(`task-loop-${task.id}`);
    const publishIntervalEl = document.getElementById(`task-publish-interval-${task.id}`);
    const createdCount = Number(task.created_count || task.total_articles || 0);
    const articleLimit = Math.max(1, Number(task.article_limit || task.draft_limit || 10));
    if (createdEl) {
        createdEl.textContent = TASK_I18N.createdOfLimitLabel.replace('__CREATED__', String(createdCount)).replace('__LIMIT__', String(articleLimit));
    }
    if (publishedEl) {
        publishedEl.textContent = TASK_I18N.publishedArticlesLabel.replace('__COUNT__', String(Number(task.published_articles || 0)));
    }
    if (draftsEl) {
        draftsEl.textContent = TASK_I18N.draftArticlesLabel.replace('__COUNT__', String(Number(task.draft_articles || 0)));
    }
    if (progressEl) {
        const percent = Math.max(0, Math.min(100, Math.floor((createdCount / articleLimit) * 100)));
        progressEl.style.width = `${percent}%`;
    }
    if (loopEl) {
        loopEl.textContent = TASK_I18N.loopTimesLabel.replace('__COUNT__', String(Number(task.loop_count || 0)));
    }
    if (publishIntervalEl) {
        const minutes = Math.max(1, Math.ceil(Number(task.publish_interval || 3600) / 60));
        publishIntervalEl.textContent = TASK_I18N.publishIntervalMinutes.replace('__COUNT__', String(minutes));
    }
}

function updateQueueOverview(queueOverview) {
    document.getElementById('queue-pending').textContent = String(Number(queueOverview.pending || 0));
    document.getElementById('queue-running').textContent = String(Number(queueOverview.running || 0));
    document.getElementById('queue-failed').textContent = String(Number(queueOverview.failed || 0));
    document.getElementById('queue-completed').textContent = String(Number(queueOverview.completed || 0));
}

function updateTopStats(summary) {
    if (!summary) return;
    document.getElementById('stats-total-tasks').textContent = String(Number(summary.total_tasks || 0));
    document.getElementById('stats-enabled-tasks').textContent = String(Number(summary.enabled_tasks || 0));
    document.getElementById('stats-total-articles').textContent = String(Number(summary.total_articles || 0));
    document.getElementById('stats-total-published').textContent = String(Number(summary.published_articles || 0));
}

function applyOverview(overview) {
    if (!overview || !Array.isArray(overview.tasks)) return;
    overview.tasks.forEach(task => {
        updateTaskUI(task);
        updateTaskCounters(task);
    });
    updateTopStats(overview.task_summary);
    if (overview.queue_overview) {
        updateQueueOverview(overview.queue_overview);
    }
    if (typeof overview.worker_overview_html === 'string') {
        const workerContainer = document.getElementById('worker-overview-container');
        if (workerContainer) workerContainer.innerHTML = overview.worker_overview_html;
    }
    if (typeof overview.recent_runs_html === 'string') {
        const jobsContainer = document.getElementById('recent-runs-container');
        if (jobsContainer) jobsContainer.innerHTML = overview.recent_runs_html;
    }
    renderIcons(document);
}

function requestTaskSnapshot() {
    fetch(TASK_HEALTH_URL)
        .then(response => response.json())
        .then(data => {
            if (!data.success) return;
            applyOverview(data);
        })
        .catch(error => { console.error(TASK_I18N.syncFailed, error); });
}

function initTaskRealtime() {
    if (!window.Echo || typeof window.Echo.private !== 'function') {
        return;
    }

    window.Echo.private('admin.tasks').listen('.tasks.overview.updated', (payload) => {
        scheduleTaskSnapshot();
    });
}

let taskSnapshotTimer = null;
function scheduleTaskSnapshot() {
    if (taskSnapshotTimer !== null) {
        clearTimeout(taskSnapshotTimer);
    }
    taskSnapshotTimer = setTimeout(() => {
        taskSnapshotTimer = null;
        requestTaskSnapshot();
    }, 300);
}

function startBatchExecution(taskId, taskName) {
    const btn = document.getElementById(`batch-btn-${taskId}`);
    openTaskLifecycleDialog({
        title: TASK_I18N.startBatch,
        description: TASK_I18N.confirmStart.replace('__NAME__', taskName),
        confirmLabel: TASK_I18N.startBatch,
        trigger: btn,
        onConfirm: () => performStartBatchExecution(taskId, taskName),
    });
}

function performStartBatchExecution(taskId, taskName) {
    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.starting, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-green-200 bg-green-50 text-green-600 cursor-wait');
    fetch(TASK_BATCH_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
        body: JSON.stringify({ task_id: taskId, action: 'start' }),
    }).then(response => response.json()).then(data => {
        if (!data.success) {
            const readiness = data.error === 'task_title_library_not_ready'
                ? data.details?.title_readiness
                : null;
            if (readiness) {
                window.dispatchEvent(new CustomEvent('geoflow:task-title-readiness', {
                    detail: { report: readiness, trigger: btn },
                }));
            } else {
                showNotification('error', TASK_I18N.startFailed.replace('__MESSAGE__', data.message));
            }
            updateBatchButton(btn, taskId, taskName, false);
            return;
        }
        showNotification('success', TASK_I18N.taskQueued.replace('__NAME__', taskName));
        updateBatchButton(btn, taskId, taskName, true);
        requestTaskSnapshot();
    }).catch(error => {
        showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message));
        updateBatchButton(btn, taskId, taskName, false);
    });
}

function stopBatchExecution(taskId, taskName) {
    const btn = document.getElementById(`batch-btn-${taskId}`);
    openTaskLifecycleDialog({
        title: TASK_I18N.stopBatch,
        description: TASK_I18N.confirmStop.replace('__NAME__', taskName),
        confirmLabel: TASK_I18N.stopBatch,
        tone: 'stop',
        trigger: btn,
        onConfirm: () => performStopBatchExecution(taskId, taskName),
    });
}

function performStopBatchExecution(taskId, taskName) {
    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.stopping, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-orange-200 bg-orange-50 text-orange-600 cursor-wait');
    fetch(TASK_BATCH_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ task_id: taskId, action: 'stop' }) }).then(response => response.json()).then(data => { if (!data.success) { showNotification('error', TASK_I18N.stopFailed.replace('__MESSAGE__', data.message)); updateBatchButton(btn, taskId, taskName, true); return; } showNotification('success', TASK_I18N.taskStopped.replace('__NAME__', taskName)); updateBatchButton(btn, taskId, taskName, false); requestTaskSnapshot(); }).catch(error => { showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message)); updateBatchButton(btn, taskId, taskName, true); });
}

function executeAllActiveTasks() {
    const buttons = Array.from(document.querySelectorAll('[id^="batch-btn-"]')).filter(btn => btn.dataset.batchAction === 'start');
    if (buttons.length === 0) { showNotification('info', TASK_I18N.noRunnable); return; }
    openTaskLifecycleDialog({
        title: TASK_I18N.startBatch,
        description: TASK_I18N.confirmRunAll,
        confirmLabel: TASK_I18N.startBatch,
        trigger: document.querySelector('[data-run-all-tasks]'),
        onConfirm: () => performAllActiveTasks(buttons),
    });
}

function performAllActiveTasks(buttons) {
    let completed = 0; let success = 0; let firstReadiness = null; let hadNetworkFailure = false;
    const finishBulkExecution = () => {
        if (completed !== buttons.length) return;
        if (firstReadiness) {
            window.dispatchEvent(new CustomEvent('geoflow:task-title-readiness', {
                detail: { report: firstReadiness },
            }));
        }
        showNotification(
            hadNetworkFailure || success !== buttons.length ? 'warning' : 'success',
            (hadNetworkFailure || success !== buttons.length ? TASK_I18N.bulkSubmittedPartial : TASK_I18N.bulkSubmitted)
                .replace('__SUCCESS__', success)
                .replace('__TOTAL__', buttons.length),
        );
        requestTaskSnapshot();
    };
    buttons.forEach((btn, index) => {
        const taskId = Number(btn.id.replace('batch-btn-', ''));
        setTimeout(() => {
            fetch(TASK_BATCH_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                body: JSON.stringify({ task_id: taskId, action: 'start' }),
            }).then(response => response.json()).then(data => {
                completed += 1;
                if (data.success) success += 1;
                if (!firstReadiness && data.error === 'task_title_library_not_ready') {
                    firstReadiness = data.details?.title_readiness || null;
                }
                finishBulkExecution();
            }).catch(() => {
                completed += 1;
                hadNetworkFailure = true;
                finishBulkExecution();
            });
        }, index * 150);
    });
}

function handleStatusToggle(taskId, checkbox) {
    const form = checkbox.closest('form');
    const currentStatus = form.querySelector('input[name="status"]').value;
    const activating = checkbox.checked;
    openTaskLifecycleDialog({
        title: activating ? TASK_I18N.activating : TASK_I18N.pausing,
        description: activating ? TASK_I18N.confirmActivate : TASK_I18N.confirmPause,
        confirmLabel: activating ? TASK_I18N.activating : TASK_I18N.pausing,
        tone: activating ? 'start' : 'stop',
        trigger: checkbox,
        onCancel: () => { checkbox.checked = currentStatus === 'active'; },
        onConfirm: () => {
            const statusSpan = form.querySelector('label span');
            checkbox.disabled = true;
            statusSpan.textContent = activating ? TASK_I18N.activating : TASK_I18N.pausing;
            statusSpan.className = `ml-2 text-sm ${activating ? 'text-blue-600' : 'text-orange-600'}`;
            form.submit();
        },
    });
}

document.querySelector('[data-run-all-tasks]')?.addEventListener('click', executeAllActiveTasks);
document.querySelectorAll('[data-batch-action][data-task-id]').forEach(btn => {
    btn.addEventListener('click', () => {
        const taskId = Number(btn.dataset.taskId);
        const taskName = btn.dataset.taskName || '';
        if (btn.dataset.batchAction === 'stop') {
            stopBatchExecution(taskId, taskName);
            return;
        }
        startBatchExecution(taskId, taskName);
    });
});
document.querySelectorAll('[data-task-status-toggle][data-task-id]').forEach(checkbox => {
    checkbox.addEventListener('change', () => handleStatusToggle(Number(checkbox.dataset.taskId), checkbox));
});

document.addEventListener('DOMContentLoaded', () => {
    if (!window.GeoFlowAdminUi?.refreshIcons) renderIcons();
    applyOverview(TASK_INITIAL_OVERVIEW);
    requestTaskSnapshot();
    initTaskRealtime();
});
</script>
@endpush
