@extends('admin.layouts.app')

@section('content')
    @php
        $releaseNotice = is_array($summary['release_notice'] ?? null) ? $summary['release_notice'] : [];
        $hasReleaseUpdate = !empty($releaseNotice['available']);
        $currentReleaseVersion = (string) ($releaseNotice['current_version'] ?? '');
        $latestReleaseVersion = (string) ($releaseNotice['latest_version'] ?? '');
        $releaseDate = (string) ($releaseNotice['release_date'] ?? '');
        $releaseType = (string) ($releaseNotice['release_type'] ?? '');
        $releaseSummary = (string) ($releaseNotice['summary'] ?? '');
        $releaseUrl = (string) ($releaseNotice['url'] ?? 'https://github.com/yaojingang/GEOFlow/releases');
        $recentRuns = $summary['recent_runs'] ?? collect();
        $recentBackups = $summary['recent_backups'] ?? collect();
        $historyScope = (string) ($summary['history_scope'] ?? 'recent');
        $historyDays = (int) ($summary['history_days'] ?? 90);
        $archivedCount = (int) ($summary['archived_run_count'] ?? 0) + (int) ($summary['archived_backup_count'] ?? 0);
        $passwordRequired = !empty($summary['admin_password_required']);
        $legacyActiveRun = !empty($summary['has_legacy_active_run']);
        $updaterBridge = is_array($summary['updater_bridge'] ?? null) ? $summary['updater_bridge'] : [];
        $updaterConnection = (string) ($updaterBridge['connection'] ?? 'disconnected');
        $updaterInstance = is_array($updaterBridge['instance'] ?? null) ? $updaterBridge['instance'] : [];
        $updaterChecks = is_array($updaterBridge['checks'] ?? null) ? array_values(array_filter($updaterBridge['checks'], 'is_array')) : [];
        $updaterDoctorStatus = (string) ($updaterBridge['doctor_status'] ?? 'unavailable');
        $updaterOperationsAvailable = !empty($updaterBridge['operations_available']);
        $mutationAuthorizationReady = !empty($updaterBridge['mutation_authorization_ready']);
        $phaseBHandoverReady = !empty($updaterBridge['phase_b_handover_ready']);
        $legacyWorkerAbsent = !empty($updaterBridge['legacy_worker_absent']);
        $legacyCutoverBlocked = $legacyActiveRun && !$legacyWorkerAbsent;
        $updaterOperation = is_array($updaterBridge['current_operation'] ?? null) ? $updaterBridge['current_operation'] : [];
        $updaterOperationStages = is_array($updaterOperation['stages'] ?? null) ? array_values(array_filter($updaterOperation['stages'], 'is_array')) : [];
        $updaterOperationStatus = (string) ($updaterOperation['status'] ?? '');
        $updaterOperationBlocksMutations = in_array($updaterOperationStatus, ['queued', 'running', 'recovery_required'], true);
        $updaterOperationNeedsPolling = in_array($updaterOperationStatus, ['queued', 'running'], true);
        $updaterRecoveryPoints = is_array($updaterBridge['recovery_points'] ?? null) ? array_values(array_filter($updaterBridge['recovery_points'], 'is_array')) : [];
        $webRollbackPointId = null;
        foreach ($updaterRecoveryPoints as $recoveryPoint) {
            if (str_starts_with((string) ($recoveryPoint['reason'] ?? ''), 'update-to-')) {
                $webRollbackPointId = (string) ($recoveryPoint['id'] ?? '');
                break;
            }
        }
        $preparedUpdater = is_array($updaterBridge['prepared'] ?? null) ? $updaterBridge['prepared'] : [];
        $hasPreparedUpdater = $preparedUpdater !== [];
        $updaterHostRoot = trim((string) config('geoflow.updater_host_root'));
        $updaterHostRootConfigured = str_starts_with($updaterHostRoot, '/');
        $updaterInstanceId = (string) config('geoflow.updater_instance_id', 'primary');
        $updaterProjectUrl = 'https://github.com/yaojingang/geoflow-updater';
        $updaterReleasesUrl = $updaterProjectUrl.'/releases';
        $updaterReleaseCandidateWorkflowUrl = $updaterProjectUrl.'/actions/workflows/release-candidate.yml';
        $updaterReleaseWorkflowUrl = $updaterProjectUrl.'/actions/workflows/release.yml';
        $updaterError = is_array(session('system_updater_error')) ? session('system_updater_error') : [];
        $updaterErrorReasons = ['release_not_found', 'release_unavailable', 'connection_failed', 'platform_unsupported', 'verification_failed', 'storage_failed', 'unexpected'];
        $updaterErrorReason = in_array((string) ($updaterError['reason'] ?? ''), $updaterErrorReasons, true)
            ? (string) $updaterError['reason']
            : 'unexpected';
        $updaterPresent = $updaterConnection !== 'disconnected';
        $updaterReady = $updaterConnection === 'connected' && $updaterOperationsAvailable && $mutationAuthorizationReady;
        $updaterReadiness = match (true) {
            !$updaterPresent && !$hasPreparedUpdater => 'not_installed',
            !$updaterPresent => 'installation_pending',
            $updaterConnection === 'degraded' || !$updaterOperationsAvailable => 'attention_required',
            !$mutationAuthorizationReady => 'authorization_pending',
            default => 'ready',
        };
        $updaterReadinessClass = match ($updaterReadiness) {
            'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'attention_required', 'authorization_pending' => 'border-amber-200 bg-amber-50 text-amber-800',
            'installation_pending' => 'border-blue-200 bg-blue-50 text-blue-700',
            default => 'border-slate-200 bg-slate-100 text-slate-700',
        };
        $journeySteps = [
            'obtain' => $updaterPresent || $hasPreparedUpdater ? 'complete' : 'current',
            'install' => $updaterPresent ? 'complete' : ($hasPreparedUpdater ? ($updaterHostRootConfigured ? 'current' : 'attention') : 'pending'),
            'authorize' => $mutationAuthorizationReady ? 'complete' : ($updaterPresent ? 'current' : 'pending'),
            'operate' => $updaterReady ? 'complete' : ($updaterPresent && $updaterConnection === 'degraded' ? 'attention' : 'pending'),
        ];
        $journeyStateClasses = [
            'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'current' => 'border-blue-200 bg-blue-50 text-blue-800',
            'attention' => 'border-amber-200 bg-amber-50 text-amber-900',
            'pending' => 'border-gray-200 bg-gray-50 text-gray-500',
        ];
        $journeyStateIcons = [
            'complete' => 'check',
            'current' => 'circle-dot',
            'attention' => 'triangle-alert',
            'pending' => 'circle',
        ];
        $updaterInstallCommands = [];
        if ($hasPreparedUpdater && $updaterHostRootConfigured) {
            $archiveArg = escapeshellarg((string) ($preparedUpdater['filename'] ?? ''));
            $updaterInstanceArg = escapeshellarg($updaterInstanceId);
            $updaterEnrollRootArg = escapeshellarg($updaterHostRoot);
            $updaterEnvironmentArg = escapeshellarg(rtrim($updaterHostRoot, '/').'/.env.prod');
            $updaterReleaseEnvironmentArg = escapeshellarg('/var/lib/geoflow-updater/instances/'.$updaterInstanceId.'/release.env');
            $updaterComposeArg = escapeshellarg('/var/lib/geoflow-updater/instances/'.$updaterInstanceId.'/docker-compose.managed.yml');
            $updaterInstallCommands = [
                'unpack' => 'tar -xzf '.$archiveArg,
                'install' => 'sudo ./packaging/scripts/install.sh',
                'enroll' => 'sudo geoflow-updater enroll --instance-id '.$updaterInstanceArg.' --instance-root '.$updaterEnrollRootArg,
                'authorize' => 'sudo geoflow-updater authorization-uri --instance '.$updaterInstanceArg,
                'activate' => 'sudo docker compose --env-file '.$updaterEnvironmentArg.' --env-file '.$updaterReleaseEnvironmentArg.' -f '.$updaterComposeArg." down --remove-orphans\n".'sudo docker compose --env-file '.$updaterEnvironmentArg.' --env-file '.$updaterReleaseEnvironmentArg.' -f '.$updaterComposeArg.' up -d --remove-orphans',
                'doctor' => 'sudo geoflow-updater doctor --instance '.$updaterInstanceArg,
            ];
        }
        $readOnlyOperationDisabled = $updaterOperationBlocksMutations || !$updaterOperationsAvailable;
        $mutationDisabled = $readOnlyOperationDisabled || $legacyCutoverBlocked || $updaterConnection !== 'connected' || !$mutationAuthorizationReady;
        $updateMutationDisabled = $readOnlyOperationDisabled || $legacyCutoverBlocked || ($updaterConnection !== 'connected' && !$phaseBHandoverReady) || !$mutationAuthorizationReady;
        $authorizationCheckFailed = !$mutationAuthorizationReady;
        $manualCommands = is_array($summary['manual_commands'] ?? null) ? $summary['manual_commands'] : [];
    @endphp

    <div class="px-4 sm:px-0" @if($updaterOperationNeedsPolling) data-system-updater-auto-reload="5000" @endif>
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.system_updates.page_title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.system_updates.page_subtitle') }}</p>
            </div>
            <a href="{{ $updaterProjectUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-gray-50">
                <i data-lucide="github" class="mr-2 h-4 w-4"></i>
                {{ __('admin.system_updates.updater.project_link') }}
            </a>
        </div>

        @if($hasReleaseUpdate)
            <section class="mb-6 overflow-hidden rounded-xl border border-amber-200 bg-amber-50 shadow-sm" data-system-update-release-notice aria-labelledby="system-update-release-title">
                <div class="px-5 py-5 sm:px-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-700" aria-hidden="true">
                                <i data-lucide="sparkles" class="h-5 w-5"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ __('admin.system_updates.release_notice.eyebrow') }}</span>
                                    @if($releaseDate !== '')
                                        <span class="text-xs font-medium text-amber-800">{{ __('admin.system_updates.release_notice.release_date', ['date' => $releaseDate]) }}</span>
                                    @endif
                                </div>
                                <h2 id="system-update-release-title" class="mt-2 text-xl font-semibold text-gray-950">
                                    {{ __('admin.system_updates.release_notice.title', ['version' => $latestReleaseVersion]) }}
                                </h2>
                                <p class="mt-2 text-sm leading-6 text-gray-700">{{ __('admin.system_updates.release_notice.description') }}</p>
                                <p class="mt-1 text-sm font-medium text-gray-900">
                                    {{ __('admin.system_updates.release_notice.version_line', ['current' => $currentReleaseVersion, 'latest' => $latestReleaseVersion]) }}
                                </p>
                            </div>
                        </div>
                        <a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 w-full flex-none items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-blue-700 sm:w-auto">
                            <i data-lucide="external-link" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.system_updates.release_notice.cta') }}
                        </a>
                    </div>

                    <div class="mt-5 rounded-lg border border-amber-200 bg-white/80 px-4 py-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.release_notice.changes_title') }}</h3>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                {{ __('admin.system_updates.release_notice.release_type.'.$releaseType) }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-gray-700">
                            {{ $releaseSummary !== '' ? $releaseSummary : __('admin.system_updates.release_notice.summary_fallback') }}
                        </p>
                    </div>

                    <div class="mt-4 flex items-start gap-2 text-sm leading-6 text-amber-900">
                        <i data-lucide="arrow-down" class="mt-1 h-4 w-4 flex-none" aria-hidden="true"></i>
                        <p>{{ __('admin.system_updates.release_notice.next_step') }}</p>
                    </div>
                </div>
            </section>
        @endif

        @if($hasReleaseUpdate)
            <section class="mb-6 overflow-hidden rounded-xl border border-amber-200 bg-amber-50 shadow-sm" data-system-update-release-notice aria-labelledby="system-update-release-title">
                <div class="px-5 py-5 sm:px-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-700" aria-hidden="true">
                                <i data-lucide="sparkles" class="h-5 w-5"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ __('admin.system_updates.release_notice.eyebrow') }}</span>
                                    @if($releaseDate !== '')
                                        <span class="text-xs font-medium text-amber-800">{{ __('admin.system_updates.release_notice.release_date', ['date' => $releaseDate]) }}</span>
                                    @endif
                                </div>
                                <h2 id="system-update-release-title" class="mt-2 text-xl font-semibold text-gray-950">
                                    {{ __('admin.system_updates.release_notice.title', ['version' => $latestReleaseVersion]) }}
                                </h2>
                                <p class="mt-2 text-sm leading-6 text-gray-700">{{ __('admin.system_updates.release_notice.description') }}</p>
                                <p class="mt-1 text-sm font-medium text-gray-900">
                                    {{ __('admin.system_updates.release_notice.version_line', ['current' => $currentReleaseVersion, 'latest' => $latestReleaseVersion]) }}
                                </p>
                            </div>
                        </div>
                        <a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 w-full flex-none items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-blue-700 sm:w-auto">
                            <i data-lucide="external-link" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.system_updates.release_notice.cta') }}
                        </a>
                    </div>

                    <div class="mt-5 rounded-lg border border-amber-200 bg-white/80 px-4 py-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.release_notice.changes_title') }}</h3>
                            @if($releaseType !== '')
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                    {{ __('admin.system_updates.release_notice.release_type.'.$releaseType) }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm leading-6 text-gray-700">
                            {{ $releaseSummary !== '' ? $releaseSummary : __('admin.system_updates.release_notice.summary_fallback') }}
                        </p>
                    </div>

                    <div class="mt-4 flex items-start gap-2 text-sm leading-6 text-amber-900">
                        <i data-lucide="arrow-down" class="mt-1 h-4 w-4 flex-none" aria-hidden="true"></i>
                        <p>{{ __('admin.system_updates.release_notice.next_step') }}</p>
                    </div>
                </div>
            </section>
        @endif

        @if($manualCommands !== [])
            <section class="mb-6 overflow-hidden rounded-xl border border-blue-200 bg-blue-50 shadow-sm">
                <div class="border-b border-blue-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-blue-950">{{ __('admin.system_updates.manual_commands.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('admin.system_updates.manual_commands.description') }}</p>
                </div>
                <div class="space-y-3 px-6 py-5">
                    @foreach($manualCommands as $manualCommand)
                        @php
                            $manualCommandStatus = ($manualCommand['status'] ?? 'pending') === 'complete' ? 'complete' : 'pending';
                            $manualCommandId = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($manualCommand['id'] ?? $loop->index));
                            $manualCommandCodeId = 'manual-command-'.$loop->index.'-'.$manualCommandId;
                        @endphp
                        <div class="rounded-lg border border-blue-200 bg-white p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900">{{ (string) ($manualCommand['label'] ?? '') }}</h3>
                                @if(!empty($manualCommand['required']))
                                    <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700">{{ __('admin.system_updates.manual_commands.required') }}</span>
                                @endif
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $manualCommandStatus === 'complete' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">
                                    {{ __('admin.system_updates.manual_commands.'.$manualCommandStatus) }}
                                </span>
                            </div>
                            <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-600">{{ (string) ($manualCommand['description'] ?? '') }}</p>
                            <div class="mt-3 flex items-start gap-2 rounded-lg px-3 py-2.5 text-sm leading-6 {{ $manualCommandStatus === 'complete' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900' }}">
                                <i data-lucide="{{ $manualCommandStatus === 'complete' ? 'circle-check' : 'triangle-alert' }}" class="mt-0.5 h-4 w-4 flex-none"></i>
                                <p>{{ (string) ($manualCommand['status_description'] ?? '') }}</p>
                            </div>
                            <details class="mt-3 border-t border-gray-100 pt-3" @if($manualCommandStatus === 'pending') open @endif>
                                <summary class="cursor-pointer text-sm font-medium text-gray-700 marker:text-gray-400">
                                    {{ $manualCommandStatus === 'pending' ? __('admin.system_updates.manual_commands.command_label') : __('admin.system_updates.manual_commands.show_command') }}
                                </summary>
                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                                    <code id="{{ $manualCommandCodeId }}" class="min-w-0 flex-1 overflow-x-auto rounded-md bg-gray-950 px-3 py-2.5 text-xs leading-5 text-gray-100">{{ (string) ($manualCommand['command'] ?? '') }}</code>
                                    <button
                                        type="button"
                                        data-system-updater-copy="#{{ $manualCommandCodeId }}"
                                        data-copied-label="{{ __('admin.system_updates.updater.copied') }}"
                                        data-copy-failed-label="{{ __('admin.system_updates.updater.copy_failed') }}"
                                        class="inline-flex min-h-10 flex-none items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-gray-100"
                                    >
                                        <i data-lucide="copy" class="mr-2 h-4 w-4"></i>
                                        <span data-system-updater-copy-label aria-live="polite">{{ __('admin.system_updates.updater.copy') }}</span>
                                    </button>
                                </div>
                            </details>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.updater.title') }}</h2>
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $updaterReadinessClass }}">
                            {{ __('admin.system_updates.updater.readiness.'.$updaterReadiness) }}
                        </span>
                    </div>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.system_updates.updater.description') }}</p>
                </div>
                @if(!$updaterPresent && !$hasPreparedUpdater)
                    <form method="POST" action="{{ route('admin.system-updates.updater.prepare') }}" class="flex-none">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-blue-700">
                            <i data-lucide="package-check" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.system_updates.updater.cta.get') }}
                        </button>
                    </form>
                @elseif(!$updaterPresent)
                    <a href="{{ route('admin.system-updates.updater.download') }}" class="inline-flex min-h-10 flex-none items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-blue-700">
                        <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.system_updates.updater.cta.download') }}
                    </a>
                @else
                    <a href="{{ route('admin.system-updates.index') }}" class="inline-flex min-h-10 flex-none items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.system_updates.updater.cta.refresh') }}
                    </a>
                @endif
            </div>

            <div class="border-b border-gray-100 bg-slate-50/70 px-6 py-5">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.updater.journey.title') }}</h3>
                    <p class="text-xs leading-5 text-gray-500">{{ __('admin.system_updates.updater.journey.hint') }}</p>
                </div>
                <ol class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @foreach($journeySteps as $journeyStep => $journeyState)
                        <li data-system-updater-journey="{{ $journeyStep }}" class="rounded-lg border p-4 {{ $journeyStateClasses[$journeyState] }}">
                            <div class="flex items-center justify-between gap-3">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/80">
                                    <i data-lucide="{{ $journeyStateIcons[$journeyState] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="text-xs font-semibold">{{ __('admin.system_updates.updater.journey.state.'.$journeyState) }}</span>
                            </div>
                            <p class="mt-3 text-sm font-semibold">{{ __('admin.system_updates.updater.journey.'.$journeyStep) }}</p>
                            <p class="mt-1 text-xs leading-5 opacity-80">{{ __('admin.system_updates.updater.journey.'.$journeyStep.'_hint') }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if($updaterConnection !== 'disconnected')
                <div class="grid gap-4 px-6 py-6 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm text-gray-500">{{ __('admin.system_updates.updater.version') }}</div>
                        <div class="mt-2 font-semibold text-gray-900">{{ (string) ($updaterBridge['updater_version'] ?? __('admin.common.none')) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm text-gray-500">{{ __('admin.system_updates.updater.instance') }}</div>
                        <div class="mt-2 font-semibold text-gray-900">{{ (string) ($updaterInstance['id'] ?? __('admin.common.none')) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm text-gray-500">{{ __('admin.system_updates.updater.release') }}</div>
                        <div class="mt-2 font-semibold text-gray-900">{{ filled($updaterInstance['version'] ?? null) ? 'v'.(string) $updaterInstance['version'] : __('admin.common.none') }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="text-sm text-gray-500">{{ __('admin.system_updates.updater.doctor') }}</div>
                        <div class="mt-2 font-semibold text-gray-900">{{ __('admin.system_updates.updater.doctor_status.'.$updaterDoctorStatus) }}</div>
                    </div>
                </div>

                @if($authorizationCheckFailed)
                    <div class="mx-6 mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold">{{ __('admin.system_updates.updater.authorization_setup_title') }}</h3>
                                <p class="mt-1 text-sm leading-6">{{ __('admin.system_updates.updater.authorization_setup_hint') }}</p>
                            </div>
                            <button type="button" data-system-updater-copy="#updater-command-authorization" data-copied-label="{{ __('admin.system_updates.updater.copied') }}" data-copy-failed-label="{{ __('admin.system_updates.updater.copy_failed') }}" class="inline-flex min-h-10 flex-none items-center justify-center rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-900 transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-amber-100">
                                <i data-lucide="copy" class="mr-2 h-4 w-4"></i>
                                <span data-system-updater-copy-label aria-live="polite">{{ __('admin.system_updates.updater.copy') }}</span>
                            </button>
                        </div>
                        <pre class="mt-3 overflow-x-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100"><code id="updater-command-authorization">sudo geoflow-updater authorization-uri --instance {{ escapeshellarg($updaterInstanceId) }}</code></pre>
                    </div>
                @endif

                @if($phaseBHandoverReady)
                    <div class="mx-6 mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900">
                        {{ __('admin.system_updates.updater.phase_b_handover_hint') }}
                    </div>
                @endif

                @if($updaterOperationsAvailable)
                    <div class="border-t border-gray-100 px-6 py-6">
                        <div>
                            <div class="max-w-2xl">
                                <h3 class="text-base font-semibold text-gray-900">{{ __('admin.system_updates.updater.operations_title') }}</h3>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.system_updates.updater.operations_hint') }}</p>
                                <p class="mt-2 text-xs leading-5 text-gray-500">{{ __('admin.system_updates.updater.authorization_hint') }}</p>
                            </div>
                            <div class="mt-5 grid w-full gap-3 md:grid-cols-3">
                                @foreach(['update', 'backup'] as $operationKind)
                                    @php($operationDisabled = $operationKind === 'update' ? $updateMutationDisabled : $mutationDisabled)
                                    <form
                                        method="POST"
                                        action="{{ route('admin.system-updates.updater.'.$operationKind) }}"
                                        class="flex flex-col gap-2"
                                        data-system-updater-authorized-action
                                        data-dialog-tone="{{ $operationKind === 'update' ? 'warning' : 'info' }}"
                                        data-dialog-title="{{ __('admin.system_updates.updater.action.'.$operationKind) }}"
                                        data-dialog-message="{{ __('admin.system_updates.updater.operations_hint') }}"
                                        data-dialog-guidance="{{ __('admin.system_updates.release_notice.version_line', ['current' => $currentReleaseVersion, 'latest' => $latestReleaseVersion]) }}"
                                        data-dialog-confirm-label="{{ __('admin.system_updates.updater.action.'.$operationKind) }}"
                                        data-authorization-label="{{ __('admin.system_updates.updater.authorization_label') }}"
                                        data-password-label="{{ __('admin.system_updates.label.current_admin_password') }}"
                                        data-required-message="{{ __('admin.action_dialog.required') }}"
                                        data-authorization-pattern-message="{{ __('admin.system_updates.updater.authorization_label') }}"
                                        @if($passwordRequired) data-password-required="true" @endif
                                    >
                                        @csrf
                                        <input id="updater-{{ $operationKind }}-authorization" type="hidden" name="updater_authorization_code" @disabled($operationDisabled)>
                                        @if($passwordRequired)
                                            <input id="updater-{{ $operationKind }}-password" type="hidden" name="current_admin_password" @disabled($operationDisabled)>
                                        @endif
                                        <button type="submit" @disabled($operationDisabled) class="inline-flex min-h-10 items-center justify-center rounded-md {{ $operationKind === 'update' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50' }} px-4 py-2 text-sm font-semibold shadow-sm disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400">
                                            <i data-lucide="{{ $operationKind === 'update' ? 'rocket' : 'archive' }}" class="mr-2 h-4 w-4"></i>
                                            {{ __('admin.system_updates.updater.action.'.$operationKind) }}
                                        </button>
                                    </form>
                                @endforeach
                                <form method="POST" action="{{ route('admin.system-updates.updater.verify') }}">
                                    @csrf
                                    <button type="submit" @disabled($readOnlyOperationDisabled) class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400">
                                        <i data-lucide="shield-check" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.system_updates.updater.action.verify') }}
                                    </button>
                                </form>
                            </div>
                        </div>

                        @if($updaterOperation !== [])
                            <div class="mt-5 rounded-lg border {{ in_array($updaterOperationStatus, ['failed', 'recovery_required'], true) ? 'border-red-200 bg-red-50 text-red-800' : 'border-blue-200 bg-blue-50 text-blue-800' }} p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold">{{ __('admin.system_updates.updater.operation_kind.'.(string) ($updaterOperation['kind'] ?? 'verify')) }} · {{ __('admin.system_updates.updater.operation_status.'.$updaterOperationStatus) }}</p>
                                        <p class="mt-1 break-all font-mono text-xs opacity-80">{{ (string) ($updaterOperation['id'] ?? '') }}</p>
                                    </div>
                                    @if(filled($updaterOperation['current_stage'] ?? null))
                                        <span class="rounded-full border border-current/20 bg-white/60 px-3 py-1 text-xs font-semibold">{{ __('admin.system_updates.updater.stage_name.'.(string) $updaterOperation['current_stage']) }}</span>
                                    @endif
                                </div>
                                @if($updaterOperationStages !== [])
                                    <ol class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        @foreach($updaterOperationStages as $stage)
                                            <li class="rounded-md border border-current/15 bg-white/60 px-3 py-2">
                                                <p class="text-xs font-semibold">{{ __('admin.system_updates.updater.stage_name.'.(string) ($stage['name'] ?? 'verify')) }}</p>
                                                <p class="mt-1 text-xs opacity-80">{{ __('admin.system_updates.updater.stage_status.'.(string) ($stage['status'] ?? 'running')) }}</p>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 px-6 py-6">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.system_updates.updater.recovery_title') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.system_updates.updater.recovery_hint') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse($updaterRecoveryPoints as $recoveryPoint)
                                <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-gray-50/70 p-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="break-all font-mono text-sm font-semibold text-gray-900">{{ (string) ($recoveryPoint['id'] ?? '') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">v{{ (string) ($recoveryPoint['version'] ?? '') }} · {{ (string) ($recoveryPoint['created_at'] ?? '') }}</p>
                                    </div>
                                    @if((string) ($recoveryPoint['id'] ?? '') === $webRollbackPointId)
                                        <form
                                            method="POST"
                                            action="{{ route('admin.system-updates.updater.rollback') }}"
                                            class="grid gap-2"
                                            data-system-updater-authorized-action
                                            data-dialog-tone="danger"
                                            data-dialog-title="{{ __('admin.system_updates.updater.action.rollback') }} v{{ (string) ($recoveryPoint['version'] ?? '') }}"
                                            data-dialog-message="{{ __('admin.system_updates.updater.recovery_hint') }}"
                                            data-dialog-guidance="{{ (string) ($recoveryPoint['created_at'] ?? '') }} · {{ (string) ($recoveryPoint['id'] ?? '') }}"
                                            data-dialog-confirm-label="{{ __('admin.system_updates.updater.action.rollback') }}"
                                            data-authorization-label="{{ __('admin.system_updates.updater.authorization_label') }}"
                                            data-password-label="{{ __('admin.system_updates.label.current_admin_password') }}"
                                            data-required-message="{{ __('admin.action_dialog.required') }}"
                                            data-authorization-pattern-message="{{ __('admin.system_updates.updater.authorization_label') }}"
                                            @if($passwordRequired) data-password-required="true" @endif
                                        >
                                            @csrf
                                            <input type="hidden" name="recovery_point_id" value="{{ (string) ($recoveryPoint['id'] ?? '') }}">
                                            <input id="rollback-authorization-{{ $loop->index }}" type="hidden" name="updater_authorization_code" @disabled($mutationDisabled)>
                                            @if($passwordRequired)
                                                <input id="rollback-password-{{ $loop->index }}" type="hidden" name="current_admin_password" @disabled($mutationDisabled)>
                                            @endif
                                            <button type="submit" @disabled($mutationDisabled) class="inline-flex min-h-10 items-center justify-center rounded-md border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50 disabled:bg-gray-100 disabled:text-gray-400">
                                                <i data-lucide="rotate-ccw" class="mr-2 h-4 w-4"></i>
                                                {{ __('admin.system_updates.updater.action.rollback') }}
                                            </button>
                                        </form>
                                    @else
                                        <span class="inline-flex rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600">{{ __('admin.system_updates.updater.history_only') }}</span>
                                    @endif
                                </div>
                            @empty
                                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">{{ __('admin.system_updates.updater.no_recovery_points') }}</p>
                            @endforelse
                        </div>
                    </div>
                @endif

                @if($updaterChecks !== [])
                    <div class="border-t border-gray-100 px-6 py-6">
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.updater.checks') }}</h3>
                        <ul class="mt-3 grid gap-3 lg:grid-cols-2">
                            @foreach($updaterChecks as $check)
                                <li class="rounded-lg border px-4 py-3 {{ ($check['status'] ?? null) === 'pass' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : (($check['status'] ?? null) === 'warn' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-red-200 bg-red-50 text-red-800') }}">
                                    <p class="font-mono text-xs font-semibold">{{ (string) ($check['id'] ?? __('admin.common.none')) }}</p>
                                    <p class="mt-1 text-sm leading-5">{{ (string) ($check['message'] ?? __('admin.common.none')) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <div class="px-6 py-6">
                    <p class="text-sm font-medium text-gray-700">{{ __('admin.system_updates.updater.not_available') }}</p>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-500">{{ __('admin.system_updates.updater.prepare_hint') }}</p>
                    @if($hasPreparedUpdater)
                        <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50/60 p-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="font-mono text-sm font-semibold text-gray-900">{{ (string) ($preparedUpdater['filename'] ?? '') }}</p>
                                    <p class="mt-1 break-all font-mono text-xs text-gray-600">{{ __('admin.system_updates.updater.package_digest') }}: {{ (string) ($preparedUpdater['sha256'] ?? '') }}</p>
                                </div>
                                <a href="{{ route('admin.system-updates.updater.download') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-emerald-300 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-emerald-100">
                                    <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.system_updates.updater.download') }}
                                </a>
                            </div>
                        </div>

                        @if(!$updaterHostRootConfigured)
                            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                <div class="flex gap-3">
                                    <i data-lucide="folder-cog" class="mt-0.5 h-5 w-5 flex-none"></i>
                                    <div>
                                        <h3 class="text-sm font-semibold">{{ __('admin.system_updates.updater.host_root_required_title') }}</h3>
                                        <p class="mt-1 max-w-3xl text-sm leading-6">{{ __('admin.system_updates.updater.host_root_required_hint') }}</p>
                                    </div>
                                </div>
                            </div>
                        @else
                            <details class="mt-4 rounded-lg border border-gray-200 bg-white">
                                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 text-sm font-semibold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500">
                                    <span class="inline-flex items-center">
                                        <i data-lucide="square-terminal" class="mr-2 h-4 w-4 text-gray-500"></i>
                                        {{ __('admin.system_updates.updater.install_commands_title') }}
                                    </span>
                                    <i data-lucide="chevron-down" class="h-4 w-4 text-gray-400"></i>
                                </summary>
                                <div class="border-t border-gray-100 px-4 pb-5 pt-4">
                                    <p class="max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.system_updates.updater.install_commands_hint') }}</p>
                                    <ol class="mt-4 space-y-4">
                                        @foreach($updaterInstallCommands as $commandKey => $command)
                                            <li class="rounded-lg bg-slate-50 p-4">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <p class="text-sm font-semibold text-gray-900">
                                                        <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs text-white">{{ $loop->iteration }}</span>
                                                        {{ __('admin.system_updates.updater.command_step.'.$commandKey) }}
                                                    </p>
                                                    <button type="button" data-system-updater-copy="#updater-command-{{ $commandKey }}" data-copied-label="{{ __('admin.system_updates.updater.copied') }}" data-copy-failed-label="{{ __('admin.system_updates.updater.copy_failed') }}" class="inline-flex min-h-10 items-center justify-center self-start rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-transform active:scale-[0.98] motion-reduce:transform-none [@media(hover:hover)]:hover:bg-gray-100 sm:self-auto">
                                                        <i data-lucide="copy" class="mr-2 h-4 w-4"></i>
                                                        <span data-system-updater-copy-label aria-live="polite">{{ __('admin.system_updates.updater.copy') }}</span>
                                                    </button>
                                                </div>
                                                <pre class="mt-3 overflow-x-auto rounded-md bg-gray-950 p-3 text-xs leading-6 text-gray-100"><code id="updater-command-{{ $commandKey }}">{{ $command }}</code></pre>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            </details>
                        @endif
                    @endif
                </div>
            @endif
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.system_updates.history.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.system_updates.history.description', ['days' => $historyDays]) }}</p>
                </div>
                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 text-sm">
                    <a href="{{ route('admin.system-updates.index') }}" class="rounded-md px-3 py-2 font-semibold {{ $historyScope === 'recent' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500' }}">{{ __('admin.system_updates.history.recent') }}</a>
                    <a href="{{ route('admin.system-updates.index', ['history' => 'archived']) }}" class="rounded-md px-3 py-2 font-semibold {{ $historyScope === 'archived' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500' }}">{{ __('admin.system_updates.history.archived') }} ({{ $archivedCount }})</a>
                </div>
            </div>
            @include('admin.system-updates.partials.recent-runs', ['recentRuns' => $recentRuns])
            @if(method_exists($recentRuns, 'hasPages') && $recentRuns->hasPages())
                <div class="border-t border-gray-100 px-6 py-4">{{ $recentRuns->links() }}</div>
            @endif
            <div class="border-t border-gray-100 px-6 py-5">
                <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.system_updates.history.backups') }}</h3>
                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    @forelse($recentBackups as $backup)
                        <a href="{{ route('admin.system-updates.backups.show', ['backupUuid' => $backup->backup_uuid]) }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                            <div class="flex items-center justify-between gap-3">
                                <span class="break-all font-mono text-sm font-semibold text-gray-900">{{ $backup->backup_uuid }}</span>
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600">{{ __('admin.system_updates.backup.status_'.$backup->status) }}</span>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $backup->from_version ?: __('admin.common.none') }} → {{ $backup->to_version ?: __('admin.common.none') }} · {{ optional($backup->created_at)->format('Y-m-d H:i:s') }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('admin.system_updates.empty.no_backups') }}</p>
                    @endforelse
                </div>
                @if(method_exists($recentBackups, 'hasPages') && $recentBackups->hasPages())
                    <div class="mt-4">{{ $recentBackups->links() }}</div>
                @endif
            </div>
        </section>
    </div>

    @if($updaterError !== [])
        <dialog
            open
            data-system-updater-error-dialog
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="system-updater-error-title"
            aria-describedby="system-updater-error-summary"
            class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[min(42rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-left shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
        >
            <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
                <div class="flex items-start gap-4 border-b border-gray-100 px-5 py-5 sm:px-6">
                    <span class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-red-50 text-red-600">
                        <i data-lucide="package-x" class="h-5 w-5"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">{{ __('admin.system_updates.updater.error_dialog.eyebrow') }}</p>
                        <h2 id="system-updater-error-title" class="mt-1 text-xl font-semibold text-gray-950 sm:text-2xl">
                            {{ __('admin.system_updates.updater.error_dialog.title.'.$updaterErrorReason) }}
                        </h2>
                        <p id="system-updater-error-summary" class="mt-2 text-sm leading-6 text-gray-600">
                            {{ __('admin.system_updates.updater.error_dialog.summary.'.$updaterErrorReason) }}
                        </p>
                    </div>
                    <form method="dialog" class="flex-none">
                        <button type="submit" data-system-updater-error-close class="inline-flex h-10 w-10 items-center justify-center rounded-md text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" aria-label="{{ __('admin.system_updates.updater.error_dialog.close') }}">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </form>
                </div>

                <div class="overflow-y-auto px-5 py-5 sm:px-6">
                    <section class="rounded-xl border border-red-100 bg-red-50/70 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-red-950">{{ __('admin.system_updates.updater.error_dialog.reason_label') }}</h3>
                            @if($updaterErrorReason === 'release_not_found')
                                <span class="rounded-full border border-red-200 bg-white px-2.5 py-1 font-mono text-xs font-semibold text-red-700">HTTP 404</span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm leading-6 text-red-900">{{ __('admin.system_updates.updater.error_dialog.reason.'.$updaterErrorReason) }}</p>
                    </section>

                    <section class="mt-5">
                        <h3 class="text-sm font-semibold text-gray-950">{{ __('admin.system_updates.updater.error_dialog.solution_title') }}</h3>
                        <ol class="mt-3 space-y-3">
                            @foreach(__('admin.system_updates.updater.error_dialog.steps.'.$updaterErrorReason) as $step)
                                <li class="flex gap-3 text-sm leading-6 text-gray-700">
                                    <span class="inline-flex h-6 w-6 flex-none items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">{{ $loop->iteration }}</span>
                                    <span>{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </section>

                    <div class="mt-5 flex gap-3 rounded-xl border border-blue-100 bg-blue-50/70 p-4 text-sm leading-6 text-blue-950">
                        <i data-lucide="shield-check" class="mt-0.5 h-5 w-5 flex-none text-blue-600"></i>
                        <p>{{ __('admin.system_updates.updater.error_dialog.safety_note') }}</p>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        @if($updaterErrorReason === 'release_not_found')
                            <a href="{{ $updaterReleaseCandidateWorkflowUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                <i data-lucide="package-plus" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.system_updates.updater.error_dialog.open_candidate_workflow') }}
                            </a>
                            <a href="{{ $updaterReleaseWorkflowUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                <i data-lucide="workflow" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.system_updates.updater.error_dialog.open_release_workflow') }}
                            </a>
                        @else
                            <a href="{{ $updaterReleasesUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                <i data-lucide="external-link" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.system_updates.updater.error_dialog.view_releases') }}
                            </a>
                            <a href="{{ $updaterProjectUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                <i data-lucide="github" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.system_updates.updater.project_link') }}
                            </a>
                        @endif
                    </div>
                    @if($updaterErrorReason === 'release_not_found')
                        <p class="mt-3 text-center text-xs text-gray-500">
                            {{ __('admin.system_updates.updater.error_dialog.release_status_hint') }}
                            <a href="{{ $updaterReleasesUrl }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-blue-700 underline decoration-blue-200 underline-offset-2 hover:text-blue-800">
                                {{ __('admin.system_updates.updater.error_dialog.view_releases') }}
                            </a>
                        </p>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-gray-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-6">
                    <form method="dialog">
                        <button type="submit" data-system-updater-error-close class="inline-flex min-h-10 w-full items-center justify-center rounded-md px-4 py-2 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:w-auto">
                            {{ __('admin.system_updates.updater.error_dialog.close') }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.system-updates.updater.prepare') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 sm:w-auto">
                            <i data-lucide="rotate-cw" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.system_updates.updater.error_dialog.retry') }}
                        </button>
                    </form>
                </div>
            </div>
        </dialog>
    @endif
@endsection
