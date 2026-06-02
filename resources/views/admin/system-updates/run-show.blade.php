@extends('admin.layouts.app')

@section('content')
    @php
        $payload = is_array($run->plan_json) ? $run->plan_json : [];
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $verification = is_array($payload['verification'] ?? null) ? $payload['verification'] : [];
        $verificationItems = is_array($verification['items'] ?? null) ? $verification['items'] : [];
        $applyReport = is_array($payload['apply_report'] ?? null) ? $payload['apply_report'] : [];
        $rollbackReport = is_array($payload['rollback_report'] ?? null) ? $payload['rollback_report'] : [];
        $report = $applyReport !== [] ? $applyReport : $rollbackReport;
        $reportFiles = is_array($report['files'] ?? null) ? $report['files'] : [];
        $recovery = is_array($payload['recovery'] ?? null) ? $payload['recovery'] : [];
        $status = (string) $run->status;
        $statusClass = match ($status) {
            'queued' => 'border-slate-200 bg-slate-50 text-slate-600',
            'running' => 'border-blue-200 bg-blue-50 text-blue-700',
            'succeeded' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'failed' => 'border-red-200 bg-red-50 text-red-700',
            default => 'border-gray-200 bg-gray-50 text-gray-600',
        };
        $verificationStatus = (string) ($verification['status'] ?? 'warn');
        $verificationClass = match ($verificationStatus) {
            'pass' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'fail' => 'border-red-200 bg-red-50 text-red-700',
            default => 'border-amber-200 bg-amber-50 text-amber-700',
        };
        $verificationItemClasses = [
            'pass' => 'bg-emerald-50 text-emerald-700',
            'warn' => 'bg-amber-50 text-amber-700',
            'fail' => 'bg-red-50 text-red-700',
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.system-updates.index') }}" class="mt-2 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.section.run_detail') }}</h1>
                    <p class="mt-2 text-sm text-gray-600">{{ __('admin.system_updates.section.run_detail_desc') }}</p>
                </div>
            </div>
            <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $statusClass }}">
                {{ __('admin.system_updates.run.status_'.$status) }}
            </span>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.05fr_.95fr]">
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.run_overview') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.run_overview_desc') }}</p>
                </div>
                <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.run_uuid') }}</div>
                        <div class="mt-2 break-all font-mono text-sm font-semibold text-gray-900">{{ $run->run_uuid }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.plan.action') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900">{{ __('admin.system_updates.run.action_'.$run->action) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.target_version') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900">{{ filled($run->target_version) ? 'v'.$run->target_version : __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.created_by') }}</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">{{ optional($run->startedBy)->display_name ?: optional($run->startedBy)->username ?: __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.started_at') }}</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.finished_at') }}</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">{{ optional($run->finished_at)->format('Y-m-d H:i:s') ?: __('admin.common.none') }}</div>
                    </div>
                </div>
                <div class="border-t border-gray-100 px-6 py-5">
                    <div class="grid gap-3 text-sm text-gray-600 sm:grid-cols-2">
                        <div>
                            {{ __('admin.system_updates.label.backup_uuid') }}：
                            <span class="font-mono text-gray-900">{{ (string) ($payload['backup_uuid'] ?? __('admin.common.none')) }}</span>
                        </div>
                        <div>
                            {{ __('admin.system_updates.label.log_path') }}：
                            <span class="font-mono text-gray-900">{{ $run->log_path ?: __('admin.common.none') }}</span>
                        </div>
                        @if(!empty($payload['retried_from_run_uuid']))
                            <div class="sm:col-span-2">
                                {{ __('admin.system_updates.label.retried_from') }}：
                                <a href="{{ route('admin.system-updates.runs.show', ['runUuid' => (string) $payload['retried_from_run_uuid']]) }}" class="font-mono text-blue-600 hover:text-blue-700">{{ (string) $payload['retried_from_run_uuid'] }}</a>
                            </div>
                        @endif
                    </div>
                    @if($isStale)
                        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700">
                            {{ __('admin.system_updates.queue_health.run_stale_notice', ['minutes' => (int) config('geoflow.update_run_stale_minutes', 15)]) }}
                        </div>
                    @endif
                    @if($run->error_message)
                        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700">
                            {{ $run->error_message }}
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.run_recovery') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.run_recovery_desc') }}</p>
                </div>
                <div class="space-y-4 px-6 py-6">
                    @if($canRetry)
                        <form method="POST" action="{{ route('admin.system-updates.runs.retry', ['runUuid' => $run->run_uuid]) }}" class="rounded-lg border border-blue-100 bg-blue-50 p-4" data-system-update-confirm="{{ __('admin.system_updates.confirm.retry_run') }}">
                            @csrf
                            <div class="text-sm font-semibold text-blue-900">{{ __('admin.system_updates.button.retry_run') }}</div>
                            <p class="mt-1 text-xs leading-5 text-blue-700">{{ __('admin.system_updates.recovery.retry_desc') }}</p>
                            <div class="mt-4 flex flex-wrap items-end gap-2">
                                @if($passwordRequired)
                                    <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-56 rounded-md border-blue-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @endif
                                <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                                    <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.system_updates.button.retry_run') }}
                                </button>
                            </div>
                        </form>
                    @endif

                    @if($canMarkFailed)
                        <form method="POST" action="{{ route('admin.system-updates.runs.mark-failed', ['runUuid' => $run->run_uuid]) }}" class="rounded-lg border border-amber-100 bg-amber-50 p-4" data-system-update-confirm="{{ __('admin.system_updates.confirm.mark_run_failed') }}">
                            @csrf
                            <div class="text-sm font-semibold text-amber-900">{{ __('admin.system_updates.button.mark_run_failed') }}</div>
                            <p class="mt-1 text-xs leading-5 text-amber-700">{{ __('admin.system_updates.recovery.mark_failed_desc') }}</p>
                            <div class="mt-4 flex flex-wrap items-end gap-2">
                                @if($passwordRequired)
                                    <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-56 rounded-md border-amber-200 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                @endif
                                <button type="submit" class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700">
                                    <i data-lucide="x-circle" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.system_updates.button.mark_run_failed') }}
                                </button>
                            </div>
                        </form>
                    @endif

                    @if(! $canRetry && ! $canMarkFailed)
                        <div class="rounded-lg bg-gray-50 p-4 text-sm leading-6 text-gray-600">{{ __('admin.system_updates.recovery.no_action') }}</div>
                    @endif

                    @if($recovery !== [])
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.recovery.history') }}</div>
                            <div class="mt-3 space-y-2">
                                @foreach($recovery as $item)
                                    @php($recoveryAction = (string) ($item['action'] ?? 'mark_failed'))
                                    @php($recoveryTranslationKey = 'admin.system_updates.recovery.action_'.$recoveryAction)
                                    @php($recoveryActionLabel = __($recoveryTranslationKey))
                                    @php($recoveryActionLabel = $recoveryActionLabel === $recoveryTranslationKey ? str($recoveryAction)->replace('_', ' ')->title()->toString() : $recoveryActionLabel)
                                    <div class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                                        {{ (string) ($item['at'] ?? '') }} · {{ (string) ($item['admin_name'] ?? __('admin.common.none')) }} · {{ $recoveryActionLabel }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-[1.05fr_.95fr]">
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.run_timeline') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.run_timeline_desc') }}</p>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        @forelse($progress as $step)
                            @php($stepStatus = (string) ($step['status'] ?? 'running'))
                            <div class="flex gap-4">
                                <div class="mt-1 h-3 w-3 shrink-0 rounded-full {{ $stepStatus === 'failed' ? 'bg-red-500' : ($stepStatus === 'succeeded' ? 'bg-emerald-500' : ($stepStatus === 'queued' ? 'bg-slate-400' : 'bg-blue-500')) }}"></div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-semibold text-gray-900">{{ __('admin.system_updates.progress.'.(string) ($step['key'] ?? 'complete')) }}</div>
                                        <div class="text-xs text-gray-500">{{ (string) ($step['at'] ?? '') }}</div>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-full rounded-full {{ $stepStatus === 'failed' ? 'bg-red-500' : ($stepStatus === 'succeeded' ? 'bg-emerald-500' : 'bg-blue-600') }}" style="width: {{ max(0, min(100, (int) ($step['percent'] ?? 0))) }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.system_updates.empty.no_progress') }}</div>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.verification.title') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.run_verification_desc') }}</p>
                        </div>
                        @if($verification)
                            <span class="inline-flex rounded-full border px-3 py-1 text-sm font-semibold {{ $verificationClass }}">{{ __('admin.system_updates.verification.status_'.$verificationStatus) }}</span>
                        @endif
                    </div>
                </div>
                <div class="px-6 py-6">
                    @if($verification)
                        <div class="grid grid-cols-3 gap-3 text-center text-sm">
                            <div class="rounded-lg bg-emerald-50 p-3 text-emerald-700">{{ __('admin.system_updates.preflight.pass') }} {{ (int) ($verification['pass'] ?? 0) }}</div>
                            <div class="rounded-lg bg-amber-50 p-3 text-amber-700">{{ __('admin.system_updates.preflight.warn') }} {{ (int) ($verification['warn'] ?? 0) }}</div>
                            <div class="rounded-lg bg-red-50 p-3 text-red-700">{{ __('admin.system_updates.preflight.fail') }} {{ (int) ($verification['fail'] ?? 0) }}</div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($verificationItems as $item)
                                @php($itemStatus = (string) ($item['status'] ?? 'warn'))
                                @php($itemClass = $verificationItemClasses[$itemStatus] ?? $verificationItemClasses['warn'])
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $itemClass }}">
                                    {{ __('admin.system_updates.verification.'.(string) ($item['key'] ?? 'database_available')) }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.common.none') }}</div>
                    @endif
                </div>
            </section>
        </div>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.run_report') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.run_report_desc') }}</p>
            </div>
            <div class="px-6 py-6">
                @if($report !== [])
                    <div class="mb-5 grid gap-3 sm:grid-cols-4">
                        @foreach(['added', 'modified', 'deleted', 'skipped', 'restored', 'removed'] as $metric)
                            @if(array_key_exists($metric, $report))
                                <div class="rounded-lg bg-gray-50 p-4">
                                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.report.'.$metric) }}</div>
                                    <div class="mt-2 text-2xl font-bold text-gray-900">{{ (int) $report[$metric] }}</div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <div class="max-h-[420px] overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.file') }}</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach($reportFiles as $file)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-gray-800">{{ (string) ($file['path'] ?? '') }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ (string) ($file['action'] ?? '') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">{{ __('admin.system_updates.empty.no_run_report') }}</div>
                @endif
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-system-update-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const message = form.dataset.systemUpdateConfirm || '';
                if (message !== '' && !window.confirm(message)) {
                    event.preventDefault();
                    return;
                }

                window.setTimeout(() => {
                    form.querySelectorAll('button').forEach((button) => {
                        button.disabled = true;
                    });
                }, 0);
            });
        });
    </script>
@endpush
