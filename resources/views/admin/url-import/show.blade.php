@extends('admin.layouts.app')

@php
    $page = data_get($result, 'page', []);
    $analysis = data_get($result, 'analysis', []);
    $import = data_get($result, 'import', []);
    $keywords = array_values(array_filter((array) data_get($analysis, 'keywords', [])));
    $titles = array_values(array_filter((array) data_get($analysis, 'titles', [])));
    $rawJson = data_get($analysis, 'page_json') ?: data_get($page, 'raw_json', []);
    $importStatus = (string) data_get($import, 'status', 'preview');
    $steps = [
        'queued' => __('admin.url_import.workflow.queued'),
        'fetch' => __('admin.url_import.workflow.fetch'),
        'page_json' => __('admin.url_import.workflow.page_json'),
        'knowledge' => __('admin.url_import.workflow.knowledge'),
        'keywords' => __('admin.url_import.workflow.keywords'),
        'titles' => __('admin.url_import.workflow.titles'),
        'preview' => __('admin.url_import.workflow.preview'),
        'imported' => __('admin.url_import.workflow.imported'),
    ];
    $stepKeys = array_keys($steps);
    $legacyStepAliases = ['extract' => 'page_json', 'clean' => 'knowledge'];
    $currentStepKey = $legacyStepAliases[$job->current_step] ?? ($job->current_step ?: 'queued');
    $currentStepIndex = array_search($currentStepKey, $stepKeys, true);
    $currentStepIndex = $currentStepIndex === false ? -1 : $currentStepIndex;
@endphp

@section('content')
    <div
        class="px-4 sm:px-0 space-y-8"
        data-url-import-page
        data-job-id="{{ $job->id }}"
        data-status="{{ $job->status }}"
        data-has-result="{{ $result !== [] ? '1' : '0' }}"
        data-autostart="{{ $job->status === 'queued' ? '1' : '0' }}"
        data-run-url="{{ route('admin.url-import.run', ['jobId' => $job->id]) }}"
        data-status-url="{{ route('admin.url-import.status', ['jobId' => $job->id]) }}"
        data-ai-config-url="{{ route('admin.ai-models.index') }}"
    >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.url-import') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.url_import.section.progress') }}</h1>
                    <p class="mt-1 text-sm text-gray-600 break-all">{{ $job->normalized_url ?: $job->url }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.url-import.history') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.url_import.button.view_history') }}
                </a>
                <a href="{{ route('admin.url-import') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.url_import_history.button.new_job') }}
                </a>
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-medium text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="hidden rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-medium text-red-700" data-runtime-error></div>
        <div class="hidden rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm font-medium text-blue-800" data-runtime-notice></div>

        <div class="bg-white shadow rounded-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.url_import.section.stage_status') }}</h2>
                        <p class="mt-1 text-sm text-gray-500" data-status-text>{{ __('admin.url_import.progress.' . ($job->status === 'completed' ? 'completed' : ($job->status === 'running' ? 'processing' : 'waiting_short'))) }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700" data-status-label>
                        {{ __('admin.url_import_history.status.' . $job->status) }}
                    </span>
                </div>
            </div>
            <div class="p-6">
                <div class="flex items-center justify-between text-sm text-gray-500">
                    <span data-current-step-label>{{ __('admin.url_import.label.current_step', ['step' => $steps[$currentStepKey] ?? $currentStepKey]) }}</span>
                    <span data-progress-number>{{ (int) $job->progress_percent }}%</span>
                </div>
                <div class="mt-2 h-2 rounded-full bg-gray-200">
                    <div class="h-2 rounded-full bg-blue-600 transition-all" data-progress-bar style="width: {{ max(0, min(100, (int) $job->progress_percent)) }}%"></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
            <div class="xl:col-span-2 bg-white shadow rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.url_import.section.workflow') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.url_import.section.workflow_desc') }}</p>
                </div>
                <div class="p-6 space-y-4">
                    @foreach ($steps as $stepKey => $stepLabel)
                        @php
                            $stepIndex = array_search($stepKey, $stepKeys, true);
                            $isTerminal = in_array($job->status, ['completed'], true) || $importStatus === 'imported';
                            $isFailedStep = $job->status === 'failed' && $currentStepKey === $stepKey;
                            $isCurrent = ! $isTerminal && $job->status !== 'failed' && $currentStepKey === $stepKey;
                            $isDone = $currentStepIndex !== -1 && $stepIndex !== false && ($isTerminal ? $stepIndex <= $currentStepIndex : $stepIndex < $currentStepIndex);
                            $isImported = $importStatus === 'imported' && $stepKey === 'imported';
                            $rowClass = $isCurrent
                                ? 'border-blue-300 bg-blue-50 shadow-sm'
                                : ($isFailedStep ? 'border-red-200 bg-red-50 shadow-sm' : ($isDone || $isImported ? 'border-green-100 bg-white' : 'border-gray-200 bg-white opacity-70'));
                            $iconClass = $isCurrent
                                ? 'bg-blue-600 text-white'
                                : ($isFailedStep ? 'bg-red-500 text-white' : ($isDone || $isImported ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-400'));
                            $iconName = $isDone || $isImported ? 'check' : ($isFailedStep ? 'x' : ($isCurrent ? 'loader-circle' : 'circle'));
                        @endphp
                        <div class="flex items-start gap-3 rounded-xl border {{ $rowClass }} p-4 transition-all duration-300" data-step-row="{{ $stepKey }}">
                            <div class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full {{ $iconClass }}" data-step-icon-shell>
                                <i data-lucide="{{ $iconName }}" class="w-3.5 h-3.5 {{ $isCurrent ? 'animate-spin' : '' }}"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $stepLabel }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ __('admin.url_import.workflow_desc.' . $stepKey) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="xl:col-span-3 bg-white shadow rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.url_import.section.logs') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.url_import.section.logs_desc') }}</p>
                    <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800" data-activity-text>
                        {{ $job->status === 'completed'
                            ? __('admin.url_import.stream.completed', ['current' => $steps[$currentStepKey] ?? $currentStepKey])
                            : __('admin.url_import.stream.current', ['current' => $steps[$currentStepKey] ?? $currentStepKey]) }}
                    </div>
                </div>
                <div class="max-h-[440px] overflow-y-auto p-6 space-y-3" data-log-list data-rendered-logs="{{ $logs->count() }}">
                    @forelse ($logs as $log)
                        <div class="rounded-xl border border-gray-200 bg-white p-3 transition-all duration-300">
                            <div class="text-xs text-gray-500">{{ optional($log->created_at)->format('Y-m-d H:i:s') }} · {{ $log->level }}</div>
                            <div class="mt-1 text-sm text-gray-700">{{ $log->message }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500" data-empty-log>{{ __('admin.materials.url_import_waiting_logs') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        @if ($job->status === 'failed')
            <div class="rounded-2xl border border-red-200 bg-red-50 p-6">
                <h3 class="text-base font-semibold text-red-800">{{ __('admin.url_import.error.job_failed') }}</h3>
                <p class="mt-2 text-sm text-red-700">{{ $job->error_message }}</p>
                <p class="mt-3 text-sm leading-6 text-red-700">{{ __('admin.url_import.error.ai_config_help') }}</p>
                <a href="{{ route('admin.ai-models.index') }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    <i data-lucide="external-link" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.url_import.error.ai_config_button') }}
                </a>
            </div>
        @endif

        @if ($result !== [])
            <div class="bg-white shadow rounded-2xl overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-gray-900">{{ __('admin.url_import.section.result_preview') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.url_import.section.result_preview_desc') }}</p>
                            @if (data_get($analysis, 'analysis_source') === 'ai')
                                <p class="mt-2 inline-flex items-center rounded-full bg-purple-50 px-3 py-1 text-xs font-medium text-purple-700">
                                    <i data-lucide="sparkles" class="mr-1 h-3.5 w-3.5"></i>
                                    {{ __('admin.url_import.preview.ai_powered', ['model' => data_get($analysis, 'model.name', '')]) }}
                                </p>
                            @else
                                <p class="mt-2 inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                    {{ __('admin.url_import.preview.local_fallback') }}
                                </p>
                            @endif
                        </div>
                        @if ($job->status === 'completed' && $importStatus !== 'imported')
                            <form method="POST" action="{{ route('admin.url-import.commit', ['jobId' => $job->id]) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-transparent bg-green-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-green-700">
                                    <i data-lucide="database" class="w-4 h-4 mr-2"></i>
                                    {{ __('admin.url_import.button.commit') }}
                                </button>
                            </form>
                        @elseif ($importStatus === 'imported')
                            <span class="inline-flex items-center rounded-full bg-green-50 px-3 py-1 text-sm font-medium text-green-700">
                                {{ __('admin.url_import_history.import.imported') }}
                            </span>
                        @endif
                    </div>
                </div>

                <div class="p-6 space-y-8">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="lg:col-span-2 rounded-xl border border-gray-200 p-5">
                            <div class="text-sm font-semibold text-gray-500">{{ __('admin.url_import.preview.summary') }}</div>
                            <h4 class="mt-2 text-xl font-bold text-gray-900">{{ data_get($page, 'title', $job->page_title ?: $job->source_domain) }}</h4>
                            <p class="mt-3 text-sm leading-6 text-gray-600">{{ data_get($analysis, 'summary') ?: data_get($page, 'summary', __('admin.url_import.preview.empty_summary')) }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 p-5">
                            <div class="text-sm font-semibold text-gray-500">{{ __('admin.url_import.preview.source') }}</div>
                            <p class="mt-2 break-all text-sm text-gray-700">{{ data_get($result, 'source.normalized_url', $job->normalized_url) }}</p>
                            <p class="mt-3 text-xs text-gray-500">{{ data_get($result, 'source.domain', $job->source_domain) }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="rounded-xl border border-gray-200 p-5">
                            <h4 class="text-base font-semibold text-gray-900">{{ __('admin.url_import.preview.keywords') }}</h4>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @forelse (array_slice($keywords, 0, 40) as $keyword)
                                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">{{ $keyword }}</span>
                                @empty
                                    <span class="text-sm text-gray-500">{{ __('admin.common.none') }}</span>
                                @endforelse
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 p-5">
                            <h4 class="text-base font-semibold text-gray-900">{{ __('admin.url_import.preview.titles') }}</h4>
                            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm leading-6 text-gray-700">
                                @forelse (array_slice($titles, 0, 12) as $title)
                                    <li>{{ $title }}</li>
                                @empty
                                    <li class="list-none text-gray-500">{{ __('admin.common.none') }}</li>
                                @endforelse
                            </ol>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-5">
                        <h4 class="text-base font-semibold text-gray-900">{{ __('admin.url_import.preview.knowledge') }}</h4>
                        <pre class="mt-4 max-h-[360px] overflow-auto whitespace-pre-wrap rounded-xl bg-gray-50 p-4 text-sm leading-6 text-gray-700">{{ data_get($analysis, 'knowledge_markdown', __('admin.url_import.preview.empty_knowledge')) }}</pre>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-5">
                        <h4 class="text-base font-semibold text-gray-900">{{ __('admin.url_import.preview.raw_json') }}</h4>
                        <pre class="mt-4 max-h-[280px] overflow-auto whitespace-pre-wrap rounded-xl bg-gray-950 p-4 text-xs leading-5 text-gray-100">{{ json_encode($rawJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-2xl border border-blue-100 bg-blue-50 p-6" data-processing-panel>
                <h3 class="text-base font-semibold text-blue-900" data-processing-title>{{ __('admin.url_import.progress.processing') }}</h3>
                <p class="mt-2 text-sm leading-6 text-blue-800" data-processing-message>
                    {{ __('admin.url_import.section.processing_hint', [
                        'current' => $steps[$currentStepKey] ?? $currentStepKey,
                        'next' => isset($stepKeys[$currentStepIndex + 1]) ? ($steps[$stepKeys[$currentStepIndex + 1]] ?? $stepKeys[$currentStepIndex + 1]) : '-',
                    ]) }}
                </p>
            </div>
        @endif
    </div>

    <script>
        (() => {
            const root = document.querySelector('[data-url-import-page]');
            if (!root) {
                return;
            }

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const progressBar = root.querySelector('[data-progress-bar]');
            const progressNumber = root.querySelector('[data-progress-number]');
            const statusLabel = root.querySelector('[data-status-label]');
            const statusText = root.querySelector('[data-status-text]');
            const logList = root.querySelector('[data-log-list]');
            const runtimeError = root.querySelector('[data-runtime-error]');
            const runtimeNotice = root.querySelector('[data-runtime-notice]');
            const processingPanel = root.querySelector('[data-processing-panel]');
            const processingTitle = root.querySelector('[data-processing-title]');
            const processingMessage = root.querySelector('[data-processing-message]');
            const jobId = root.dataset.jobId || '';
            const needsAutostart = root.dataset.autostart === '1';
            const initialStatus = root.dataset.status || '';
            const hasServerResult = root.dataset.hasResult === '1';
            const stepOrder = @json($stepKeys);
            const stepLabels = @json($steps);
            const stepAliases = {extract: 'page_json', clean: 'knowledge'};
            const currentStepTemplate = @json(__('admin.url_import.label.current_step', ['step' => '__STEP__']));
            const streamCurrentTemplate = @json(__('admin.url_import.stream.current', ['current' => '__CURRENT__']));
            const streamNextTemplate = @json(__('admin.url_import.stream.next', ['current' => '__CURRENT__', 'next' => '__NEXT__']));
            const streamCompletedTemplate = @json(__('admin.url_import.stream.completed', ['current' => '__CURRENT__']));
            const streamFailedText = @json(__('admin.url_import.stream.failed'));
            const processingTitleText = @json(__('admin.url_import.progress.processing'));
            const completedTitleText = @json(__('admin.url_import.progress.completed'));
            const failedTitleText = @json(__('admin.url_import.error.job_failed'));
            const aiConfigHelpText = @json(__('admin.url_import.error.ai_config_help'));
            const aiConfigButtonText = @json(__('admin.url_import.error.ai_config_button'));
            const processingHintTemplate = @json(__('admin.url_import.section.processing_hint', ['current' => '__CURRENT__', 'next' => '__NEXT__']));
            const completedHintText = @json(__('admin.url_import.progress.result_ready'));
            let polling = null;
            let hasFinished = ['completed', 'failed'].includes(initialStatus);
            let startInFlight = false;
            let renderedLogCount = Number(logList?.dataset.renderedLogs || 0);
            let reloadRequested = false;

            const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (match) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[match]));

            const stepLabel = (step) => stepLabels[step] || step || '';

            const renderActivity = (payload, currentStep, currentIndex) => {
                const activity = root.querySelector('[data-activity-text]');
                if (!activity) {
                    return;
                }
                const current = stepLabel(currentStep);
                const next = stepOrder[currentIndex + 1] ? stepLabel(stepOrder[currentIndex + 1]) : '';
                if (payload.status === 'failed') {
                    activity.textContent = streamFailedText;
                    activity.className = 'mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700';
                    return;
                }
                if (payload.status === 'completed') {
                    activity.textContent = streamCompletedTemplate.replace('__CURRENT__', current);
                    activity.className = 'mt-4 rounded-xl border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-700';
                    return;
                }
                activity.textContent = next
                    ? streamNextTemplate.replace('__CURRENT__', current).replace('__NEXT__', next)
                    : streamCurrentTemplate.replace('__CURRENT__', current);
                activity.className = 'mt-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800';
            };

            const renderProcessingPanel = (payload, currentStep, currentIndex) => {
                if (!processingPanel) {
                    return;
                }
                const current = stepLabel(currentStep);
                const next = stepOrder[currentIndex + 1] ? stepLabel(stepOrder[currentIndex + 1]) : '';

                if (payload.status === 'failed') {
                    processingPanel.className = 'rounded-2xl border border-red-200 bg-red-50 p-6';
                    if (processingTitle) {
                        processingTitle.textContent = failedTitleText;
                        processingTitle.className = 'text-base font-semibold text-red-800';
                    }
                    if (processingMessage) {
                        processingMessage.textContent = payload.error_message || streamFailedText;
                        processingMessage.className = 'mt-2 text-sm leading-6 text-red-700';
                    }
                    return;
                }

                if (payload.status === 'completed') {
                    processingPanel.className = 'rounded-2xl border border-green-200 bg-green-50 p-6';
                    if (processingTitle) {
                        processingTitle.textContent = completedTitleText;
                        processingTitle.className = 'text-base font-semibold text-green-800';
                    }
                    if (processingMessage) {
                        processingMessage.textContent = completedHintText;
                        processingMessage.className = 'mt-2 text-sm leading-6 text-green-700';
                    }
                    return;
                }

                processingPanel.className = 'rounded-2xl border border-blue-100 bg-blue-50 p-6';
                if (processingTitle) {
                    processingTitle.textContent = processingTitleText;
                    processingTitle.className = 'text-base font-semibold text-blue-900';
                }
                if (processingMessage) {
                    processingMessage.textContent = processingHintTemplate
                        .replace('__CURRENT__', current)
                        .replace('__NEXT__', next || '-');
                    processingMessage.className = 'mt-2 text-sm leading-6 text-blue-800';
                }
            };

            const appendLogs = (logs) => {
                if (!logList || !Array.isArray(logs)) {
                    return;
                }
                if (logs.length < renderedLogCount) {
                    logList.innerHTML = '';
                    renderedLogCount = 0;
                }
                const nextLogs = logs.slice(renderedLogCount);
                if (nextLogs.length === 0) {
                    return;
                }
                logList.querySelector('[data-empty-log]')?.remove();
                nextLogs.forEach((log) => {
                    const item = document.createElement('div');
                    item.className = 'rounded-xl border border-gray-200 bg-white p-3 opacity-0 translate-y-2 transition-all duration-300';
                    item.innerHTML = `
                        <div class="text-xs text-gray-500">${escapeHtml(log.created_at)} · ${escapeHtml(log.level)}</div>
                        <div class="mt-1 text-sm text-gray-700">${escapeHtml(log.message)}</div>
                    `;
                    logList.appendChild(item);
                    requestAnimationFrame(() => {
                        item.classList.remove('opacity-0', 'translate-y-2');
                    });
                });
                renderedLogCount = logs.length;
                logList.dataset.renderedLogs = String(renderedLogCount);
                logList.scrollTo({top: logList.scrollHeight, behavior: 'smooth'});
            };

            const showRuntimeError = (message) => {
                if (!runtimeError) {
                    return;
                }
                runtimeError.innerHTML = `
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>${escapeHtml(message)}</span>
                        <a href="${escapeHtml(root.dataset.aiConfigUrl || '#')}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            ${escapeHtml(aiConfigButtonText)}
                        </a>
                    </div>
                    <p class="mt-2 text-sm font-normal leading-6">${escapeHtml(aiConfigHelpText)}</p>
                `;
                runtimeError.classList.remove('hidden');
            };

            const showRuntimeNotice = (message) => {
                if (!runtimeNotice) {
                    return;
                }
                runtimeNotice.innerHTML = `
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>${message}</span>
                        <button type="button" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" data-refresh-result>
                            {{ __('admin.url_import.button.refresh_result') }}
                        </button>
                    </div>
                `;
                runtimeNotice.classList.remove('hidden');
                runtimeNotice.querySelector('[data-refresh-result]')?.addEventListener('click', () => {
                    window.location.reload();
                });
            };

            const stopPolling = () => {
                if (polling) {
                    window.clearInterval(polling);
                    polling = null;
                }
            };

            const renderStatus = (payload) => {
                const currentStep = stepAliases[payload.current_step] || payload.current_step || 'queued';
                const currentIndex = stepOrder.indexOf(currentStep);
                renderActivity(payload, currentStep, currentIndex);
                renderProcessingPanel(payload, currentStep, currentIndex);
                if (progressBar) {
                    progressBar.style.width = `${Math.max(0, Math.min(100, Number(payload.progress_percent || 0)))}%`;
                }
                if (progressNumber) {
                    progressNumber.textContent = `${Number(payload.progress_percent || 0)}%`;
                }
                const currentStepLabel = root.querySelector('[data-current-step-label]');
                if (currentStepLabel) {
                    currentStepLabel.textContent = currentStepTemplate.replace('__STEP__', stepLabel(currentStep));
                }
                if (statusLabel) {
                    statusLabel.textContent = payload.status_label || payload.status || '';
                }
                if (statusText) {
                    statusText.textContent = payload.status === 'running'
                        ? streamCurrentTemplate.replace('__CURRENT__', stepLabel(currentStep))
                        : (payload.status_label || '');
                }
                root.querySelectorAll('[data-step-row]').forEach((row) => {
                    const step = row.dataset.stepRow || '';
                    const stepIndex = stepOrder.indexOf(step);
                    const terminalComplete = payload.status === 'completed';
                    const done = currentIndex >= 0 && stepIndex >= 0 && (terminalComplete ? stepIndex <= currentIndex : stepIndex < currentIndex);
                    const failed = payload.status === 'failed' && currentIndex >= 0 && stepIndex === currentIndex;
                    const current = !terminalComplete && !failed && currentIndex >= 0 && stepIndex === currentIndex;
                    const pending = !done && !current && !failed;
                    row.className = [
                        'flex items-start gap-3 rounded-xl border p-4 transition-all duration-300',
                        current ? 'border-blue-300 bg-blue-50 shadow-sm' : '',
                        failed ? 'border-red-200 bg-red-50 shadow-sm' : '',
                        done ? 'border-green-100 bg-white' : '',
                        pending ? 'border-gray-200 bg-white opacity-70' : '',
                    ].filter(Boolean).join(' ');
                    const iconShell = row.querySelector('[data-step-icon-shell]');
                    if (iconShell) {
                        iconShell.className = [
                            'mt-0.5 flex h-6 w-6 items-center justify-center rounded-full',
                            current ? 'bg-blue-600 text-white' : '',
                            failed ? 'bg-red-500 text-white' : '',
                            done ? 'bg-green-500 text-white' : '',
                            pending ? 'bg-gray-100 text-gray-400' : '',
                        ].filter(Boolean).join(' ');
                        iconShell.innerHTML = `<i data-lucide="${done ? 'check' : (failed ? 'x' : (current ? 'loader-circle' : 'circle'))}" class="w-3.5 h-3.5 ${current ? 'animate-spin' : ''}"></i>`;
                    }
                });
                window.lucide?.createIcons?.();
                appendLogs(payload.logs);

                if (['completed', 'failed'].includes(payload.status) && !hasFinished) {
                    hasFinished = true;
                    stopPolling();
                    if (payload.status === 'completed' && payload.result_ready && !hasServerResult && !reloadRequested) {
                        reloadRequested = true;
                        window.setTimeout(() => window.location.reload(), 450);
                        return;
                    }
                    showRuntimeNotice(payload.status === 'completed'
                        ? '{{ __('admin.url_import.progress.result_ready') }}'
                        : '{{ __('admin.url_import.error.job_failed') }}');
                }
            };

            const poll = async () => {
                const response = await fetch(root.dataset.statusUrl, {headers: {'Accept': 'application/json'}});
                if (response.ok) {
                    const payload = await response.json();
                    renderStatus(payload);
                }
            };

            if (!hasFinished) {
                polling = window.setInterval(() => {
                    poll().catch(() => {});
                }, 1200);
            }

            const startJob = async () => {
                if (!needsAutostart || hasFinished) {
                    return;
                }
                if (!csrf) {
                    showRuntimeError('{{ __('admin.url_import.error.csrf_missing') }}');
                    return;
                }
                if (startInFlight) {
                    return;
                }
                startInFlight = true;

                try {
                    const response = await fetch(root.dataset.runUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) {
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            const payload = await response.json();
                            renderStatus(payload);
                            throw new Error(payload.error_message || payload.message || `HTTP ${response.status}`);
                        }
                        const body = await response.text();
                        throw new Error(body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || `HTTP ${response.status}`);
                    }
                    renderStatus(await response.json());
                } catch (error) {
                    startInFlight = false;
                    stopPolling();
                    showRuntimeError(error?.message || '{{ __('admin.url_import.error.run_failed') }}');
                }
            };

            startJob();
        })();
    </script>
@endsection
