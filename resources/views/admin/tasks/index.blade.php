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
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.tasks.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.tasks.page_subtitle') }}</p>
            </div>
            <div class="flex space-x-3">
                <a href="{{ route('admin.tasks.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.button.create_task') }}
                </a>
                <button onclick="executeAllActiveTasks()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
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

        <div class="bg-white shadow rounded-lg">
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
                    <table class="w-full min-w-[1110px] table-fixed divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.name') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.created_at') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.model') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.article_stats') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.loop_count') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.status') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.actions') }}</th>
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
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                    @php
                                        $articleLimit = max(1, (int) ($task['article_limit'] ?? $task['draft_limit'] ?? 10));
                                        $createdForProgress = min($articleLimit, (int) ($task['created_count'] ?? $task['total_articles'] ?? 0));
                                        $progressPercent = (int) floor(($createdForProgress / $articleLimit) * 100);
                                    @endphp
                                    <div id="task-created-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.created_of_limit', ['created' => (int) ($task['created_count'] ?? $task['total_articles'] ?? 0), 'limit' => $articleLimit]) }}</div>
                                    <div id="task-published-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.published_articles', ['count' => (int) ($task['published_articles'] ?? 0)]) }}</div>
                                    <div id="task-drafts-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.draft_articles', ['count' => (int) ($task['draft_articles'] ?? 0)]) }}</div>
                                    <div class="mt-2 h-1.5 w-28 overflow-hidden rounded-full bg-gray-200">
                                        <div id="task-progress-{{ (int) $task['id'] }}" class="h-full rounded-full bg-blue-600" style="width: {{ $progressPercent }}%"></div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                    <span id="task-loop-{{ (int) $task['id'] }}">{{ __('admin.tasks.label.loop_times', ['count' => (int) ($task['loop_count'] ?? 0)]) }}</span>
                                    <div id="task-publish-interval-{{ (int) $task['id'] }}" class="mt-1 text-xs text-gray-400">
                                        {{ __('admin.tasks.label.publish_interval_minutes', ['count' => max(1, (int) ceil(((int) ($task['publish_interval'] ?? 3600)) / 60))]) }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <form method="POST" action="{{ route('admin.tasks.toggle-status', ['taskId' => (int) $task['id']]) }}" class="inline" id="status-form-{{ (int) $task['id'] }}">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ $task['status'] }}">
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" @checked(($task['status'] ?? '') === 'active') onchange="handleStatusToggle({{ (int) $task['id'] }}, this)" class="rounded border-gray-300 text-blue-600 shadow-sm">
                                            <span class="ml-2 text-sm {{ ($task['status'] ?? '') === 'active' ? 'text-green-600' : 'text-gray-500' }}">
                                                {{ ($task['status'] ?? '') === 'active' ? __('admin.tasks.status.enabled') : __('admin.tasks.status.disabled') }}
                                            </span>
                                        </label>
                                    </form>
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <div class="flex w-fit items-center gap-1.5">
                                        @if (($task['status'] ?? '') === 'active')
                                            <button onclick="stopBatchExecution({{ (int) $task['id'] }}, '{{ addslashes((string) ($task['name'] ?? '')) }}')" data-batch-action="stop" class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200" title="{{ __('admin.tasks.action.stop_batch') }}" aria-label="{{ __('admin.tasks.action.stop_batch') }}" id="batch-btn-{{ (int) $task['id'] }}">
                                                <i data-lucide="square" class="w-4 h-4"></i>
                                            </button>
                                        @else
                                            <button onclick="startBatchExecution({{ (int) $task['id'] }}, '{{ addslashes((string) ($task['name'] ?? '')) }}')" data-batch-action="start" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200" title="{{ __('admin.tasks.action.start_batch') }}" aria-label="{{ __('admin.tasks.action.start_batch') }}" id="batch-btn-{{ (int) $task['id'] }}">
                                                <i data-lucide="play" class="w-4 h-4"></i>
                                            </button>
                                        @endif

                                        <a href="{{ route('admin.tasks.edit', ['taskId' => (int) $task['id']]) }}" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors border border-blue-200" title="{{ __('admin.tasks.action.settings') }}">
                                            <i data-lucide="settings" class="w-4 h-4"></i>
                                        </a>

                                        <a href="{{ route('admin.articles.index', ['task_id' => (int) $task['id']]) }}" class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200" title="{{ __('admin.tasks.action.articles') }}">
                                            <i data-lucide="file-text" class="w-4 h-4"></i>
                                        </a>

                                        <form method="POST" action="{{ route('admin.tasks.delete', ['taskId' => (int) $task['id']]) }}" class="inline" onsubmit="return confirm(@js(__('admin.tasks.confirm.delete')))">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200" title="{{ __('admin.tasks.action.delete') }}">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="mt-2 max-w-[165px]" id="batch-status-{{ (int) $task['id'] }}"></div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <div class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_tasks') }}</div>
                            <div id="stats-total-tasks" class="text-2xl font-semibold text-gray-900">{{ count($tasks) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="play" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <div class="text-sm text-gray-500">{{ __('admin.tasks.stats.enabled') }}</div>
                            <div id="stats-enabled-tasks" class="text-2xl font-semibold text-gray-900">{{ count(array_filter($tasks, static fn (array $row): bool => ($row['status'] ?? '') === 'active')) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <div class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_articles') }}</div>
                            <div id="stats-total-articles" class="text-2xl font-semibold text-gray-900">{{ array_sum(array_map(static fn (array $row): int => (int) ($row['total_articles'] ?? 0), $tasks)) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="globe" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <div class="text-sm text-gray-500">{{ __('admin.tasks.stats.total_published') }}</div>
                            <div id="stats-total-published" class="text-2xl font-semibold text-gray-900">{{ array_sum(array_map(static fn (array $row): int => (int) ($row['published_articles'] ?? 0), $tasks)) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900">{{ __('admin.tasks.worker.title') }}</h3>
                </div>
                <div class="p-5">
                    <div id="worker-overview-container">
                        @if (empty($workers))
                            <p class="text-sm text-gray-500">{{ __('admin.tasks.worker.none') }}</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($workers as $worker)
                                    <div class="rounded-lg border border-gray-200 px-3 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-mono text-xs text-gray-700">{{ $worker['worker_id'] ?? '' }}</span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ ($worker['status'] ?? '') === 'running' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-50 text-gray-700 border border-gray-200' }}">
                                                {{ $worker['status'] ?? 'idle' }}
                                            </span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <div>{{ __('admin.tasks.worker.current_job') }}: {{ !empty($worker['current_job_id']) ? '#'.(int) $worker['current_job_id'] : __('admin.tasks.worker.idle') }}</div>
                                            <div>{{ __('admin.tasks.worker.last_seen') }}: {{ (string) ($worker['last_seen_at'] ?? '') }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900">{{ __('admin.tasks.queue.title') }}</h3>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
                            <div class="text-xs text-blue-700">{{ __('admin.tasks.queue.pending') }}</div>
                            <div class="mt-1 text-2xl font-semibold text-blue-900" id="queue-pending">{{ (int) ($queueStats['pending'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                            <div class="text-xs text-emerald-700">{{ __('admin.tasks.queue.running') }}</div>
                            <div class="mt-1 text-2xl font-semibold text-emerald-900" id="queue-running">{{ (int) ($queueStats['running'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                            <div class="text-xs text-red-700">{{ __('admin.tasks.queue.failed') }}</div>
                            <div class="mt-1 text-2xl font-semibold text-red-900" id="queue-failed">{{ (int) ($queueStats['failed'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs text-gray-700">{{ __('admin.tasks.queue.completed') }}</div>
                            <div class="mt-1 text-2xl font-semibold text-gray-900" id="queue-completed">{{ (int) ($queueStats['completed'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900">{{ __('admin.tasks.jobs.recent') }}</h3>
                </div>
                <div class="p-5">
                    <div id="recent-runs-container">
                        @if (empty($recentJobs))
                            <p class="text-sm text-gray-500">{{ __('admin.tasks.jobs.none') }}</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($recentJobs as $job)
                                    <div class="rounded-lg border border-gray-200 px-3 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium text-gray-900 truncate">{{ $job['task_name'] ?: __('admin.tasks.jobs.unknown_task') }}</div>
                                                <div class="text-xs text-gray-500">Job #{{ (int) $job['id'] }} · {{ __('admin.tasks.jobs.task_prefix') }} #{{ (int) $job['task_id'] }}</div>
                                            </div>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border
                                                @if (($job['status'] ?? '') === 'running') bg-emerald-50 text-emerald-700 border-emerald-200
                                                @elseif (($job['status'] ?? '') === 'pending') bg-blue-50 text-blue-700 border-blue-200
                                                @elseif (($job['status'] ?? '') === 'failed') bg-red-50 text-red-700 border-red-200
                                                @else bg-gray-50 text-gray-700 border-gray-200 @endif">
                                                {{ $job['status'] ?? 'idle' }}
                                            </span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <div>{{ __('admin.tasks.jobs.updated_at') }}: {{ (string) ($job['updated_at'] ?? '') }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
@php
    $taskInitialOverview = [
        'tasks' => $tasks,
        'queue_overview' => $queueStats,
        'worker_overview' => $workers,
        'recent_runs' => $recentJobs,
    ];
@endphp
<script>
const TASK_I18N = @json($taskI18n, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
const TASK_REALTIME = @json($taskRealtime, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
const TASK_HEALTH_URL = @js(route('admin.tasks.health'));
const TASK_BATCH_URL = @js(route('admin.tasks.batch'));
const TASK_INITIAL_OVERVIEW = @json($taskInitialOverview, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
const TASK_TEXT = {
    workerNone: @js(__('admin.tasks.worker.none')),
    workerCurrentJob: @js(__('admin.tasks.worker.current_job')),
    workerIdle: @js(__('admin.tasks.worker.idle')),
    workerLastSeen: @js(__('admin.tasks.worker.last_seen')),
    jobsNone: @js(__('admin.tasks.jobs.none')),
    jobsUnknownTask: @js(__('admin.tasks.jobs.unknown_task')),
    jobsTaskPrefix: @js(__('admin.tasks.jobs.task_prefix')),
    jobsUpdatedAt: @js(__('admin.tasks.jobs.updated_at')),
};

function renderIcons() { if (typeof lucide !== 'undefined') { lucide.createIcons(); } }

function showNotification(type, message) { if (window.AdminUtils && typeof window.AdminUtils.showToast === 'function') { window.AdminUtils.showToast(message, type); return; } alert(message); }

function setButtonLoading(btn, text, classes) { btn.disabled = true; btn.className = classes; btn.innerHTML = `<i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i><span class="sr-only">${text}</span>`; renderIcons(); }

function updateBatchButton(btn, taskId, taskName, isActive) {
    if (!btn) return;
    btn.disabled = false;
    btn.dataset.batchAction = isActive ? 'stop' : 'start';
    btn.className = isActive ? 'inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200' : 'inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200';
    btn.innerHTML = isActive ? '<i data-lucide="square" class="w-4 h-4"></i>' : '<i data-lucide="play" class="w-4 h-4"></i>';
    btn.title = isActive ? TASK_I18N.stopBatch : TASK_I18N.startBatch;
    btn.setAttribute('aria-label', btn.title);
    btn.onclick = isActive ? () => stopBatchExecution(taskId, taskName) : () => startBatchExecution(taskId, taskName);
    renderIcons();
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
    renderIcons();
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

function updateTopStats(tasks) {
    const totalTasks = Array.isArray(tasks) ? tasks.length : 0;
    const enabledTasks = (Array.isArray(tasks) ? tasks : []).filter(task => task.status === 'active').length;
    const totalArticles = (Array.isArray(tasks) ? tasks : []).reduce((sum, task) => sum + Number(task.total_articles || 0), 0);
    const totalPublished = (Array.isArray(tasks) ? tasks : []).reduce((sum, task) => sum + Number(task.published_articles || 0), 0);
    document.getElementById('stats-total-tasks').textContent = String(totalTasks);
    document.getElementById('stats-enabled-tasks').textContent = String(enabledTasks);
    document.getElementById('stats-total-articles').textContent = String(totalArticles);
    document.getElementById('stats-total-published').textContent = String(totalPublished);
}

function renderWorkerOverview(workers) {
    const container = document.getElementById('worker-overview-container');
    if (!container) return;
    if (!Array.isArray(workers) || workers.length === 0) {
        container.innerHTML = `<p class="text-sm text-gray-500">${escapeHtml(TASK_TEXT.workerNone)}</p>`;
        return;
    }
    const html = workers.map(worker => {
        const status = String(worker.status || 'idle');
        const statusClasses = status === 'running'
            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
            : 'bg-gray-50 text-gray-700 border border-gray-200';
        const currentJob = worker.current_job_id ? `#${Number(worker.current_job_id)}` : escapeHtml(TASK_TEXT.workerIdle);
        return `<div class="rounded-lg border border-gray-200 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
                <span class="font-mono text-xs text-gray-700">${escapeHtml(String(worker.worker_id || ''))}</span>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${statusClasses}">${escapeHtml(status)}</span>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                <div>${escapeHtml(TASK_TEXT.workerCurrentJob)}: ${currentJob}</div>
                <div>${escapeHtml(TASK_TEXT.workerLastSeen)}: ${escapeHtml(String(worker.last_seen_at || ''))}</div>
            </div>
        </div>`;
    }).join('');
    container.innerHTML = `<div class="space-y-3">${html}</div>`;
}

function renderRecentRuns(recentRuns) {
    const container = document.getElementById('recent-runs-container');
    if (!container) return;
    if (!Array.isArray(recentRuns) || recentRuns.length === 0) {
        container.innerHTML = `<p class="text-sm text-gray-500">${escapeHtml(TASK_TEXT.jobsNone)}</p>`;
        return;
    }
    const html = recentRuns.map(job => {
        const status = String(job.status || 'idle');
        let badgeClass = 'bg-gray-50 text-gray-700 border-gray-200';
        if (status === 'running') {
            badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
        } else if (status === 'pending') {
            badgeClass = 'bg-blue-50 text-blue-700 border-blue-200';
        } else if (status === 'failed') {
            badgeClass = 'bg-red-50 text-red-700 border-red-200';
        }
        const taskName = String(job.task_name || '') || TASK_TEXT.jobsUnknownTask;
        return `<div class="rounded-lg border border-gray-200 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-sm font-medium text-gray-900 truncate">${escapeHtml(taskName)}</div>
                    <div class="text-xs text-gray-500">Job #${Number(job.id || 0)} · ${escapeHtml(TASK_TEXT.jobsTaskPrefix)} #${Number(job.task_id || 0)}</div>
                </div>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border ${badgeClass}">${escapeHtml(status)}</span>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                <div>${escapeHtml(TASK_TEXT.jobsUpdatedAt)}: ${escapeHtml(String(job.updated_at || ''))}</div>
            </div>
        </div>`;
    }).join('');
    container.innerHTML = `<div class="space-y-3">${html}</div>`;
}

function applyOverview(overview) {
    if (!overview || !Array.isArray(overview.tasks)) return;
    overview.tasks.forEach(task => {
        updateTaskUI(task);
        updateTaskCounters(task);
    });
    updateTopStats(overview.tasks);
    if (overview.queue_overview) {
        updateQueueOverview(overview.queue_overview);
    }
    renderWorkerOverview(overview.worker_overview || []);
    renderRecentRuns(overview.recent_runs || []);
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
    if (!TASK_REALTIME.enabled || !TASK_REALTIME.key || typeof window.Pusher === 'undefined') {
        return;
    }

    const pusher = new window.Pusher(TASK_REALTIME.key, {
        cluster: 'mt1',
        wsHost: TASK_REALTIME.host,
        wsPort: TASK_REALTIME.port || 80,
        wssPort: TASK_REALTIME.port || 443,
        forceTLS: TASK_REALTIME.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    const channel = pusher.subscribe('admin.tasks');
    channel.bind('tasks.overview.updated', (payload) => {
        applyOverview(payload);
    });
}

function startBatchExecution(taskId, taskName) {
    if (!confirm(TASK_I18N.confirmStart.replace('__NAME__', taskName))) return;
    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.starting, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-green-200 bg-green-50 text-green-600 cursor-wait');
    fetch(TASK_BATCH_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ task_id: taskId, action: 'start' }) }).then(response => response.json()).then(data => { if (!data.success) { showNotification('error', TASK_I18N.startFailed.replace('__MESSAGE__', data.message)); updateBatchButton(btn, taskId, taskName, false); return; } showNotification('success', TASK_I18N.taskQueued.replace('__NAME__', taskName)); updateBatchButton(btn, taskId, taskName, true); requestTaskSnapshot(); }).catch(error => { showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message)); updateBatchButton(btn, taskId, taskName, false); });
}

function stopBatchExecution(taskId, taskName) {
    if (!confirm(TASK_I18N.confirmStop.replace('__NAME__', taskName))) return;
    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.stopping, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-orange-200 bg-orange-50 text-orange-600 cursor-wait');
    fetch(TASK_BATCH_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ task_id: taskId, action: 'stop' }) }).then(response => response.json()).then(data => { if (!data.success) { showNotification('error', TASK_I18N.stopFailed.replace('__MESSAGE__', data.message)); updateBatchButton(btn, taskId, taskName, true); return; } showNotification('success', TASK_I18N.taskStopped.replace('__NAME__', taskName)); updateBatchButton(btn, taskId, taskName, false); requestTaskSnapshot(); }).catch(error => { showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message)); updateBatchButton(btn, taskId, taskName, true); });
}

function executeAllActiveTasks() {
    const buttons = Array.from(document.querySelectorAll('[id^="batch-btn-"]')).filter(btn => btn.dataset.batchAction === 'start');
    if (buttons.length === 0) { showNotification('info', TASK_I18N.noRunnable); return; }
    if (!confirm(TASK_I18N.confirmRunAll)) return;
    let completed = 0; let success = 0;
    buttons.forEach((btn, index) => {
        const taskId = Number(btn.id.replace('batch-btn-', ''));
        setTimeout(() => {
            fetch(TASK_BATCH_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ task_id: taskId, action: 'start' }) }).then(response => response.json()).then(data => { completed += 1; if (data.success) success += 1; if (completed === buttons.length) { showNotification('success', TASK_I18N.bulkSubmitted.replace('__SUCCESS__', success).replace('__TOTAL__', buttons.length)); requestTaskSnapshot(); } }).catch(() => { completed += 1; if (completed === buttons.length) { showNotification('warning', TASK_I18N.bulkSubmittedPartial.replace('__SUCCESS__', success).replace('__TOTAL__', buttons.length)); requestTaskSnapshot(); } });
        }, index * 150);
    });
}

function handleStatusToggle(taskId, checkbox) {
    const form = checkbox.closest('form');
    const currentStatus = form.querySelector('input[name="status"]').value;
    const nextLabel = checkbox.checked ? TASK_I18N.activating : TASK_I18N.pausing;
    const statusSpan = form.querySelector('label span');
    if (!confirm(checkbox.checked ? TASK_I18N.confirmActivate : TASK_I18N.confirmPause)) { checkbox.checked = currentStatus === 'active'; return; }
    checkbox.disabled = true;
    statusSpan.textContent = nextLabel;
    statusSpan.className = `ml-2 text-sm ${checkbox.checked ? 'text-blue-600' : 'text-orange-600'}`;
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    renderIcons();
    applyOverview(TASK_INITIAL_OVERVIEW);
    requestTaskSnapshot();
    initTaskRealtime();
});
</script>
@endpush
