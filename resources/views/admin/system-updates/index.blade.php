@extends('admin.layouts.app')

@section('content')
    @php
        $state = is_array($summary['state'] ?? null) ? $summary['state'] : [];
        $links = is_array($summary['links'] ?? null) ? $summary['links'] : [];
        $deployment = is_array($summary['deployment'] ?? null) ? $summary['deployment'] : [];
        $latestPlan = $summary['latest_plan'] ?? null;
        $preflight = is_array($summary['preflight'] ?? null) ? $summary['preflight'] : [];
        $preflightItems = is_array($preflight['items'] ?? null) ? $preflight['items'] : [];
        $recentBackups = $summary['recent_backups'] ?? collect();
        $planJson = $latestPlan && is_array($latestPlan->plan_json) ? $latestPlan->plan_json : [];
        $planCounts = is_array($planJson['summary'] ?? null) ? $planJson['summary'] : [];
        $planFlags = is_array($planJson['flags'] ?? null) ? $planJson['flags'] : [];
        $changes = is_array($planJson['changes'] ?? null) ? $planJson['changes'] : [];
        $manualCommands = is_array($planJson['manual_commands'] ?? null) ? $planJson['manual_commands'] : [];
        $updateScript = (string) ($planJson['update_script'] ?? '');
        $commandStatuses = is_array($planJson['manual_command_statuses'] ?? null) ? $planJson['manual_command_statuses'] : [];
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $executionReady = !empty($summary['execution_enabled']) && !empty($summary['archive_apply_enabled']);
        $rollbackReady = !empty($summary['rollback_enabled']);
        $passwordRequired = !empty($summary['admin_password_required']);
        $localeForChangelog = app()->getLocale() === 'en' ? 'en' : 'zh-CN';
        $summaryText = (string) ($localeForChangelog === 'en'
            ? ($payload['summary_en'] ?? '')
            : ($payload['summary_zh'] ?? ($payload['summary_en'] ?? '')));
        $status = (string) ($state['status'] ?? 'unavailable');
        $statusClass = match ($status) {
            'available' => 'bg-amber-50 text-amber-700 border-amber-200',
            'current' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'disabled' => 'bg-slate-50 text-slate-600 border-slate-200',
            default => 'bg-red-50 text-red-700 border-red-200',
        };
        $risk = (string) ($latestPlan->risk_level ?? ($planJson['risk_level'] ?? 'low'));
        $riskClass = match ($risk) {
            'high' => 'bg-red-50 text-red-700 border-red-200',
            'medium' => 'bg-amber-50 text-amber-700 border-amber-200',
            default => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        };
        $preflightStatus = (string) ($preflight['status'] ?? 'info');
        $preflightClass = match ($preflightStatus) {
            'fail' => 'bg-red-50 text-red-700 border-red-200',
            'warn' => 'bg-amber-50 text-amber-700 border-amber-200',
            'pass' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            default => 'bg-slate-50 text-slate-600 border-slate-200',
        };
        $preflightItemClasses = [
            'pass' => 'border-emerald-100 bg-emerald-50 text-emerald-700',
            'warn' => 'border-amber-100 bg-amber-50 text-amber-700',
            'fail' => 'border-red-100 bg-red-50 text-red-700',
            'info' => 'border-slate-100 bg-slate-50 text-slate-600',
        ];
        $commandLevelClasses = [
            'required' => 'border-red-200 bg-red-50 text-red-700',
            'deployment' => 'border-blue-200 bg-blue-50 text-blue-700',
            'recommended' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        ];
        $githubUrl = (string) ($links['github'] ?? 'https://github.com/yaojingang/GEOFlow');
        $changelogLinks = is_array($links['changelog'] ?? null) ? $links['changelog'] : [];
        $changelogUrl = (string) ($changelogLinks[$localeForChangelog] ?? $changelogLinks['zh-CN'] ?? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md');
        $flagLabels = [
            'requires_composer' => __('admin.system_updates.plan.requires_composer'),
            'requires_npm_build' => __('admin.system_updates.plan.requires_npm_build'),
            'requires_migration' => __('admin.system_updates.plan.requires_migration'),
            'touches_docker' => __('admin.system_updates.plan.touches_docker'),
            'touches_config' => __('admin.system_updates.plan.touches_config'),
            'touches_routes' => __('admin.system_updates.plan.touches_routes'),
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.page_title') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ __('admin.system_updates.page_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.system-updates.check') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.system_updates.button.check') }}
                    </button>
                </form>
                <a href="{{ $githubUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    <i data-lucide="github" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.system_updates.button.open_github') }}
                </a>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.overview') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.overview_desc') }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $statusClass }}">
                            {{ __('admin.system_updates.status.'.$status) }}
                        </span>
                    </div>
                </div>
                <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.current_version') }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900">v{{ (string) ($state['current_version'] ?? config('geoflow.app_version', '2.0')) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.latest_version') }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900">{{ filled($state['latest_version'] ?? null) ? 'v'.(string) $state['latest_version'] : __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.current_commit') }}</div>
                        <div class="mt-2 truncate font-mono text-sm font-semibold text-gray-900">{{ (string) ($deployment['current_commit'] ?? '') ?: __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.checked_at') }}</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">{{ (string) ($state['checked_at'] ?? '') ?: __('admin.common.none') }}</div>
                    </div>
                </div>
                @if($summaryText !== '')
                    <div class="border-t border-gray-100 px-6 py-5">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.release_summary') }}</div>
                        <p class="mt-2 text-sm leading-6 text-gray-700">{{ $summaryText }}</p>
                    </div>
                @endif
            </section>

            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.deployment') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.deployment_desc') }}</p>
                </div>
                <div class="space-y-4 px-6 py-6">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.label.deployment_mode') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900">{{ (string) ($deployment['label'] ?? __('admin.common.none')) }}</div>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ (string) ($deployment['reason'] ?? '') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-lg border border-gray-100 p-3">
                            <div class="text-gray-500">{{ __('admin.system_updates.label.writable') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ !empty($deployment['writable']) ? __('admin.common.yes') : __('admin.common.no') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 p-3">
                            <div class="text-gray-500">{{ __('admin.system_updates.label.git_available') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ !empty($deployment['git_available']) ? __('admin.common.yes') : __('admin.common.no') }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ $changelogUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.system_updates.button.view_changelog') }}
                        </a>
                        <span class="inline-flex items-center rounded-md bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700">
                            {{ __('admin.system_updates.label.backup_keep', ['count' => (int) ($summary['backup_keep'] ?? 10)]) }}
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.preflight') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.preflight_desc') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $preflightClass }}">
                        {{ __('admin.system_updates.preflight.status_'.$preflightStatus) }}
                    </span>
                </div>
            </div>
            <div class="grid gap-4 px-6 py-6 lg:grid-cols-3">
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.system_updates.preflight.summary') }}</div>
                    <div class="mt-3 grid grid-cols-4 gap-2 text-center text-sm">
                        <div class="rounded-md bg-white p-2">
                            <div class="text-lg font-bold text-emerald-700">{{ (int) ($preflight['pass'] ?? 0) }}</div>
                            <div class="text-xs text-gray-500">{{ __('admin.system_updates.preflight.pass') }}</div>
                        </div>
                        <div class="rounded-md bg-white p-2">
                            <div class="text-lg font-bold text-amber-700">{{ (int) ($preflight['warn'] ?? 0) }}</div>
                            <div class="text-xs text-gray-500">{{ __('admin.system_updates.preflight.warn') }}</div>
                        </div>
                        <div class="rounded-md bg-white p-2">
                            <div class="text-lg font-bold text-red-700">{{ (int) ($preflight['fail'] ?? 0) }}</div>
                            <div class="text-xs text-gray-500">{{ __('admin.system_updates.preflight.fail') }}</div>
                        </div>
                        <div class="rounded-md bg-white p-2">
                            <div class="text-lg font-bold text-slate-600">{{ (int) ($preflight['info'] ?? 0) }}</div>
                            <div class="text-xs text-gray-500">{{ __('admin.system_updates.preflight.info') }}</div>
                        </div>
                    </div>
                </div>
                <div class="grid gap-3 lg:col-span-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($preflightItems as $item)
                        @php($itemStatus = (string) ($item['status'] ?? 'info'))
                        @php($itemClass = $preflightItemClasses[$itemStatus] ?? $preflightItemClasses['info'])
                        <div class="rounded-lg border p-4 {{ $itemClass }}">
                            <div class="flex items-start gap-3">
                                <i data-lucide="{{ $itemStatus === 'pass' ? 'check-circle-2' : ($itemStatus === 'fail' ? 'x-circle' : ($itemStatus === 'warn' ? 'alert-triangle' : 'info')) }}" class="mt-0.5 h-4 w-4 shrink-0"></i>
                                <div>
                                    <div class="text-sm font-semibold">{{ (string) ($item['title'] ?? '') }}</div>
                                    <p class="mt-1 text-xs leading-5 opacity-90">{{ (string) ($item['message'] ?? '') }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <div class="mt-6 grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.plan') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.plan_desc') }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.system-updates.plan') }}">
                            @csrf
                            <button type="submit" @disabled(empty($summary['can_plan'])) class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                                <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.system_updates.button.generate_plan') }}
                            </button>
                        </form>
                    </div>
                </div>

                @if($latestPlan)
                    <div class="border-b border-gray-100 px-6 py-5">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.label.target_version') }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-900">v{{ $latestPlan->target_version }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.label.risk_level') }}</div>
                                <span class="mt-1 inline-flex rounded-full border px-3 py-1 text-sm font-semibold {{ $riskClass }}">{{ __('admin.system_updates.risk.'.$risk) }}</span>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.plan.added') }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($planCounts['added'] ?? 0) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.plan.modified') }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($planCounts['modified'] ?? 0) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.plan.deleted') }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($planCounts['deleted'] ?? 0) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">{{ __('admin.system_updates.plan.total') }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) ($planCounts['total'] ?? count($changes)) }}</div>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 text-xs text-gray-500 sm:grid-cols-2">
                            <div class="truncate">
                                {{ __('admin.system_updates.label.target_commit') }}：
                                <span class="font-mono text-gray-700">{{ (string) ($latestPlan->target_commit ?? '') ?: __('admin.common.none') }}</span>
                            </div>
                            <div>
                                {{ __('admin.system_updates.label.plan_generated_at') }}：
                                <span class="text-gray-700">{{ (string) ($planJson['generated_at'] ?? optional($latestPlan->finished_at)->format('Y-m-d H:i:s')) }}</span>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($flagLabels as $flag => $label)
                                @if(!empty($planFlags[$flag]))
                                    <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">{{ $label }}</span>
                                @endif
                            @endforeach
                        </div>

                        <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.section.commands') }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-gray-600">{{ __('admin.system_updates.section.commands_desc') }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-600">
                                        {{ __('admin.system_updates.commands.count', ['count' => count($manualCommands)]) }}
                                    </span>
                                    @if($updateScript !== '')
                                        <button type="button" data-system-update-copy data-copy-text="{{ $updateScript }}" data-default-label="{{ __('admin.system_updates.button.copy_script') }}" data-copied-label="{{ __('admin.system_updates.commands.copied') }}" class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                            <i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>
                                            <span>{{ __('admin.system_updates.button.copy_script') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-4 space-y-2">
                                @forelse($manualCommands as $index => $command)
                                    @php($commandKey = (string) ($command['key'] ?? 'custom'))
                                    @php($commandLevel = (string) ($command['level'] ?? 'recommended'))
                                    @php($commandStatus = is_array($commandStatuses[(string) $index] ?? null) ? $commandStatuses[(string) $index] : null)
                                    @php($levelClass = $commandLevelClasses[$commandLevel] ?? $commandLevelClasses['recommended'])
                                    <div class="rounded-md border border-white bg-white p-3 text-xs">
                                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                            <div class="min-w-0 flex-1">
                                                <div class="mb-2 flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold text-gray-700">{{ __('admin.system_updates.commands.'.$commandKey) }}</span>
                                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $levelClass }}">
                                                        {{ __('admin.system_updates.commands.level_'.$commandLevel) }}
                                                    </span>
                                                    @if($commandStatus)
                                                        <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                            {{ __('admin.system_updates.commands.executed_at', ['time' => (string) ($commandStatus['executed_at'] ?? '')]) }}
                                                        </span>
                                                    @else
                                                        <span class="inline-flex rounded-full bg-gray-50 px-2 py-0.5 text-[11px] font-semibold text-gray-500">
                                                            {{ __('admin.system_updates.commands.pending_execution') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <code class="block break-all rounded-md bg-slate-50 px-3 py-2 font-mono text-gray-900">{{ (string) ($command['command'] ?? '') }}</code>
                                            </div>
                                            <div class="flex shrink-0 flex-wrap gap-2">
                                                <button type="button" data-system-update-copy data-copy-text="{{ (string) ($command['command'] ?? '') }}" data-default-label="{{ __('admin.system_updates.button.copy_command') }}" data-copied-label="{{ __('admin.system_updates.commands.copied') }}" class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                                    <i data-lucide="copy" class="mr-1.5 h-3.5 w-3.5"></i>
                                                    <span>{{ __('admin.system_updates.button.copy_command') }}</span>
                                                </button>
                                                <form method="POST" action="{{ route('admin.system-updates.commands.executed', ['runUuid' => $latestPlan->run_uuid, 'commandIndex' => $index]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                                        <i data-lucide="check" class="mr-1.5 h-3.5 w-3.5"></i>
                                                        {{ __('admin.system_updates.button.mark_command_executed') }}
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-md bg-white p-3 text-sm text-gray-500">{{ __('admin.system_updates.empty.no_commands') }}</div>
                                @endforelse
                            </div>
                            @if($updateScript !== '')
                                <pre class="mt-4 overflow-auto rounded-md bg-gray-950 p-4 text-xs leading-6 text-gray-100">{{ $updateScript }}</pre>
                            @endif
                        </div>

                        <div class="mt-5 rounded-lg border {{ $executionReady ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-4">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.section.execution') }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-gray-600">
                                        {{ $executionReady ? __('admin.system_updates.execution.enabled_desc') : __('admin.system_updates.execution.disabled_desc') }}
                                    </p>
                                </div>
                                @if($executionReady)
                                    <form method="POST" action="{{ route('admin.system-updates.apply') }}" class="flex flex-wrap items-end gap-2" data-confirm-message="{{ __('admin.system_updates.confirm.apply_update') }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                                        @csrf
                                        <input type="hidden" name="run_uuid" value="{{ $latestPlan->run_uuid }}">
                                        @if($passwordRequired)
                                            <label class="block">
                                                <span class="sr-only">{{ __('admin.system_updates.label.current_admin_password') }}</span>
                                                <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-52 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </label>
                                        @endif
                                        <button type="submit" class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700">
                                            <i data-lucide="rocket" class="mr-2 h-4 w-4"></i>
                                            {{ __('admin.system_updates.button.apply_update') }}
                                        </button>
                                    </form>
                                @else
                                    <button type="button" disabled class="inline-flex cursor-not-allowed items-center rounded-md bg-gray-300 px-4 py-2 text-sm font-medium text-white">
                                        <i data-lucide="lock" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.system_updates.button.apply_update') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="max-h-[460px] overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="sticky top-0 bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.file') }}</th>
                                    <th class="px-6 py-3 text-left font-semibold text-gray-500">{{ __('admin.system_updates.plan.action') }}</th>
                                    <th class="px-6 py-3 text-right font-semibold text-gray-500">{{ __('admin.system_updates.plan.bytes') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach(array_slice($changes, 0, 80) as $change)
                                    <tr>
                                        <td class="px-6 py-3 font-mono text-xs text-gray-800">{{ (string) ($change['path'] ?? '') }}</td>
                                        <td class="px-6 py-3 text-gray-600">{{ __('admin.system_updates.plan.'.(string) ($change['action'] ?? 'modified')) }}</td>
                                        <td class="px-6 py-3 text-right text-gray-500">{{ number_format((int) ($change['bytes'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="px-6 py-10 text-center text-sm text-gray-500">
                        {{ __('admin.system_updates.empty.no_plan') }}
                    </div>
                @endif
            </section>

            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.section.backups') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.system_updates.section.backups_desc') }}</p>
                        </div>
                        @if($latestPlan)
                            <form method="POST" action="{{ route('admin.system-updates.backup') }}">
                                @csrf
                                <input type="hidden" name="run_uuid" value="{{ $latestPlan->run_uuid }}">
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                    <i data-lucide="archive" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.system_updates.button.create_backup') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse($recentBackups as $backup)
                        <div class="px-6 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-mono text-sm font-semibold text-gray-900">{{ $backup->backup_uuid }}</div>
                                    <div class="mt-1 text-sm text-gray-500">v{{ $backup->from_version }} → v{{ $backup->to_version }}</div>
                                </div>
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ __('admin.system_updates.backup.status_'.$backup->status) }}</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-gray-500">
                                <div>{{ __('admin.system_updates.backup.file_count', ['count' => $backup->file_count]) }}</div>
                                <div>{{ __('admin.system_updates.backup.created_at', ['time' => optional($backup->created_at)->format('Y-m-d H:i')]) }}</div>
                                <div class="col-span-2">
                                    {{ __('admin.system_updates.label.created_by') }}：
                                    <span class="text-gray-700">{{ optional($backup->createdBy)->display_name ?: optional($backup->createdBy)->username ?: __('admin.common.none') }}</span>
                                </div>
                            </div>
                            <div class="mt-4 flex flex-wrap items-end gap-2">
                                <a href="{{ route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]) }}" class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="file-search" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.system_updates.button.view_backup_detail') }}
                                </a>
                                @if($rollbackReady)
                                    <form method="POST" action="{{ route('admin.system-updates.rollback', ['backupUuid' => $backup->backup_uuid]) }}" class="flex flex-wrap items-end gap-2" data-confirm-message="{{ __('admin.system_updates.confirm.rollback_backup') }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                                        @csrf
                                        @if($passwordRequired)
                                            <label class="block">
                                                <span class="sr-only">{{ __('admin.system_updates.label.current_admin_password') }}</span>
                                                <input type="password" name="current_admin_password" placeholder="{{ __('admin.system_updates.label.current_admin_password') }}" class="block w-52 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </label>
                                        @endif
                                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">
                                            <i data-lucide="rotate-ccw" class="mr-2 h-4 w-4"></i>
                                            {{ __('admin.system_updates.button.rollback_backup') }}
                                        </button>
                                    </form>
                                @else
                                    <button type="button" disabled class="inline-flex cursor-not-allowed items-center rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-400">
                                        <i data-lucide="lock" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.system_updates.button.rollback_backup') }}
                                    </button>
                                    <p class="mt-2 text-xs leading-5 text-gray-500">{{ __('admin.system_updates.execution.rollback_disabled_desc') }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm text-gray-500">
                            {{ __('admin.system_updates.empty.no_backups') }}
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-system-update-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const text = button.dataset.copyText || '';
                const label = button.querySelector('span');
                const defaultLabel = button.dataset.defaultLabel || (label ? label.textContent : '');
                const copiedLabel = button.dataset.copiedLabel || defaultLabel;

                try {
                    await navigator.clipboard.writeText(text);
                    if (label) {
                        label.textContent = copiedLabel;
                    }
                    setTimeout(() => {
                        if (label) {
                            label.textContent = defaultLabel;
                        }
                    }, 1400);
                } catch (error) {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.setAttribute('readonly', 'readonly');
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (label) {
                        label.textContent = copiedLabel;
                    }
                    setTimeout(() => {
                        if (label) {
                            label.textContent = defaultLabel;
                        }
                    }, 1400);
                }
            });
        });
    </script>
@endpush
