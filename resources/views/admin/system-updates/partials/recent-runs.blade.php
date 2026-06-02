@php
    $recentRuns = $recentRuns ?? collect();
    $runStatusClasses = [
        'queued' => 'border-slate-200 bg-slate-50 text-slate-600',
        'running' => 'border-blue-200 bg-blue-50 text-blue-700',
        'succeeded' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'failed' => 'border-red-200 bg-red-50 text-red-700',
    ];
    $verificationStatusClasses = [
        'pass' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'warn' => 'border-amber-200 bg-amber-50 text-amber-700',
        'fail' => 'border-red-200 bg-red-50 text-red-700',
    ];
    $verificationItemClasses = [
        'pass' => 'bg-emerald-50 text-emerald-700',
        'warn' => 'bg-amber-50 text-amber-700',
        'fail' => 'bg-red-50 text-red-700',
    ];
@endphp

<div class="divide-y divide-gray-100">
    @forelse($recentRuns as $run)
        @php($runPayload = is_array($run->plan_json) ? $run->plan_json : [])
        @php($progress = is_array($runPayload['progress'] ?? null) ? $runPayload['progress'] : [])
        @php($latestProgress = $progress !== [] ? end($progress) : null)
        @php($progressPercent = (int) ($runPayload['progress_percent'] ?? ($latestProgress['percent'] ?? 0)))
        @php($runStatus = (string) ($run->status ?? 'queued'))
        @php($runStatusClass = $runStatusClasses[$runStatus] ?? $runStatusClasses['queued'])
        @php($verification = is_array($runPayload['verification'] ?? null) ? $runPayload['verification'] : [])
        @php($verificationStatus = (string) ($verification['status'] ?? 'warn'))
        @php($verificationClass = $verificationStatusClasses[$verificationStatus] ?? $verificationStatusClasses['warn'])
        @php($verificationItems = is_array($verification['items'] ?? null) ? $verification['items'] : [])
        <div class="px-6 py-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-gray-900">{{ __('admin.system_updates.run.action_'.$run->action) }}</span>
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $runStatusClass }}">
                            {{ __('admin.system_updates.run.status_'.$runStatus) }}
                        </span>
                        @if($verification)
                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $verificationClass }}">
                                {{ __('admin.system_updates.verification.status_'.$verificationStatus) }}
                            </span>
                        @endif
                    </div>
                    <div class="mt-2 grid gap-2 text-xs text-gray-500 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            {{ __('admin.system_updates.label.target_version') }}：
                            <span class="font-semibold text-gray-700">{{ filled($run->target_version) ? 'v'.$run->target_version : __('admin.common.none') }}</span>
                        </div>
                        <div>
                            {{ __('admin.system_updates.label.started_at') }}：
                            <span class="text-gray-700">{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: __('admin.common.none') }}</span>
                        </div>
                        <div>
                            {{ __('admin.system_updates.label.finished_at') }}：
                            <span class="text-gray-700">{{ optional($run->finished_at)->format('Y-m-d H:i:s') ?: __('admin.common.none') }}</span>
                        </div>
                        <div>
                            {{ __('admin.system_updates.label.created_by') }}：
                            <span class="text-gray-700">{{ optional($run->startedBy)->display_name ?: optional($run->startedBy)->username ?: __('admin.common.none') }}</span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
                            <span>
                                {{ is_array($latestProgress) ? __('admin.system_updates.progress.'.(string) ($latestProgress['key'] ?? 'complete')) : __('admin.common.none') }}
                            </span>
                            <span class="font-semibold text-gray-700">{{ $progressPercent }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full {{ $runStatus === 'failed' ? 'bg-red-500' : ($runStatus === 'succeeded' ? 'bg-emerald-500' : 'bg-blue-600') }}" style="width: {{ max(0, min(100, $progressPercent)) }}%"></div>
                        </div>
                        @if($run->error_message)
                            <p class="mt-2 text-xs leading-5 text-red-600">{{ $run->error_message }}</p>
                        @endif
                    </div>
                </div>
                <div class="w-full rounded-lg border border-gray-100 bg-gray-50 p-4 xl:w-[360px]">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.verification.title') }}</div>
                        @if($verification)
                            <div class="flex gap-2 text-xs">
                                <span class="text-emerald-700">{{ __('admin.system_updates.preflight.pass') }} {{ (int) ($verification['pass'] ?? 0) }}</span>
                                <span class="text-amber-700">{{ __('admin.system_updates.preflight.warn') }} {{ (int) ($verification['warn'] ?? 0) }}</span>
                                <span class="text-red-700">{{ __('admin.system_updates.preflight.fail') }} {{ (int) ($verification['fail'] ?? 0) }}</span>
                            </div>
                        @endif
                    </div>
                    @if($verification)
                        <div class="mt-2 text-xs text-gray-500">
                            {{ __('admin.system_updates.label.verified_at') }}：
                            <span class="text-gray-700">{{ (string) ($verification['verified_at'] ?? __('admin.common.none')) }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach(array_slice($verificationItems, 0, 6) as $item)
                                @php($itemStatus = (string) ($item['status'] ?? 'warn'))
                                @php($itemClass = $verificationItemClasses[$itemStatus] ?? $verificationItemClasses['warn'])
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $itemClass }}">
                                    {{ __('admin.system_updates.verification.'.(string) ($item['key'] ?? 'database_available')) }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.common.none') }}</p>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="px-6 py-10 text-center text-sm text-gray-500">
            {{ __('admin.system_updates.empty.no_recent_runs') }}
        </div>
    @endforelse
</div>
