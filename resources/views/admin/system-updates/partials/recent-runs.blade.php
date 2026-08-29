@php
    $recentRuns = $recentRuns ?? collect();
    $statusClasses = [
        'queued' => 'border-slate-200 bg-slate-50 text-slate-600',
        'running' => 'border-blue-200 bg-blue-50 text-blue-700',
        'succeeded' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'failed' => 'border-red-200 bg-red-50 text-red-700',
    ];
@endphp

<div class="divide-y divide-gray-100">
    @forelse($recentRuns as $run)
        @php
            $status = (string) ($run->status ?? 'queued');
            $errorMessage = $run->error_message === 'legacy_executor_retired'
                ? __('admin.system_updates.error.legacy_executor_retired')
                : $run->error_message;
        @endphp
        <a href="{{ route('admin.system-updates.runs.show', ['runUuid' => $run->run_uuid]) }}" class="block px-6 py-5 hover:bg-gray-50">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-gray-900">{{ __('admin.system_updates.run.action_'.$run->action) }}</span>
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$status] ?? 'border-gray-200 bg-gray-50 text-gray-600' }}">{{ __('admin.system_updates.run.status_'.$status) }}</span>
                    </div>
                    <p class="mt-2 break-all font-mono text-xs text-gray-500">{{ $run->run_uuid }}</p>
                    @if($errorMessage)
                        <p class="mt-2 line-clamp-2 text-xs leading-5 text-red-600">{{ $errorMessage }}</p>
                    @endif
                </div>
                <div class="grid flex-none gap-1 text-xs text-gray-500 sm:text-right">
                    <span>{{ filled($run->target_version) ? 'v'.$run->target_version : __('admin.common.none') }}</span>
                    <span>{{ optional($run->created_at)->format('Y-m-d H:i:s') }}</span>
                    <span>{{ optional($run->startedBy)->display_name ?: optional($run->startedBy)->username ?: __('admin.common.none') }}</span>
                </div>
            </div>
        </a>
    @empty
        <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.system_updates.empty.no_recent_runs') }}</div>
    @endforelse
</div>
