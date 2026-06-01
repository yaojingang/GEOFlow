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
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
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
