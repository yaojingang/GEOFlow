@extends('admin.layouts.app')

@php
    $vditorLocaleMap = [
        'zh_CN' => 'zh_CN',
        'en' => 'en_US',
        'en_US' => 'en_US',
        'ja' => 'ja_JP',
        'ja_JP' => 'ja_JP',
        'ru' => 'ru_RU',
        'ru_RU' => 'ru_RU',
        'pt_BR' => 'pt_BR',
        'es' => 'es_ES',
        'es_ES' => 'es_ES',
    ];
    $vditorLang = $vditorLocaleMap[str_replace('-', '_', app()->getLocale())] ?? 'en_US';
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/vditor/dist/index.css') }}">
    <style>
        .enterprise-knowledge-workspace {
            max-width: none;
        }

        .enterprise-markdown-editor .vditor {
            border: 0;
            border-radius: 0 0 0.5rem 0.5rem;
            min-height: 720px;
        }

        .enterprise-markdown-editor .vditor-toolbar {
            background: #f9fafb;
            border-bottom-color: #e5e7eb;
            padding: 8px 10px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .enterprise-markdown-editor .vditor-reset,
        .enterprise-markdown-editor .vditor-ir pre.vditor-reset,
        .enterprise-markdown-editor .vditor-wysiwyg pre.vditor-reset,
        .enterprise-markdown-editor .vditor-sv .vditor-reset {
            color: #111827;
            font-size: 15px;
            line-height: 1.85;
        }

        .enterprise-markdown-editor .vditor-ir pre.vditor-reset,
        .enterprise-markdown-editor .vditor-wysiwyg pre.vditor-reset,
        .enterprise-markdown-editor .vditor-sv .vditor-reset,
        .enterprise-markdown-editor .vditor-preview {
            background: #fff;
        }

        .enterprise-markdown-editor .vditor-content {
            min-height: 720px;
        }

        .enterprise-markdown-editor .vditor-preview {
            border-left-color: #e5e7eb;
        }

        .enterprise-markdown-editor .vditor-textarea {
            min-height: 720px;
        }

        @media (max-width: 768px) {
            .enterprise-markdown-editor .vditor,
            .enterprise-markdown-editor .vditor-content {
                min-height: 520px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('vendor/vditor/dist/index.min.js') }}"></script>
@endpush

@section('content')
    @php
        $status = (string) ($project->status ?? 'draft');
        $progress = $project->draftGenerationProgress();
        $draftReady = trim((string) ($project->draft_content ?? '')) !== '';
        $isGenerating = in_array($status, ['queued', 'processing'], true) && ! $draftReady;
        $showProgressPanel = ! $draftReady && in_array($status, ['draft', 'queued', 'processing', 'failed'], true);
        $statusStyles = [
            'draft' => 'bg-gray-100 text-gray-700',
            'queued' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'processing' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'reviewing' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'published' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'failed' => 'bg-red-50 text-red-700 ring-red-200',
        ];
        $progressSteps = [
            ['key' => 'queued', 'icon' => 'list-checks'],
            ['key' => 'collecting', 'icon' => 'copy-plus'],
            ['key' => 'cleaning', 'icon' => 'wand-sparkles'],
            ['key' => 'structuring', 'icon' => 'blocks'],
            ['key' => 'validating', 'icon' => 'shield-check'],
            ['key' => 'writing', 'icon' => 'file-pen-line'],
            ['key' => 'completed', 'icon' => 'database-zap'],
            ['key' => 'failed', 'icon' => 'triangle-alert'],
        ];
        $atomStandards = [
            ['icon' => 'message-square-quote', 'title' => __('admin.enterprise_knowledge.atom_claim_title'), 'desc' => __('admin.enterprise_knowledge.atom_claim_desc')],
            ['icon' => 'fingerprint', 'title' => __('admin.enterprise_knowledge.atom_evidence_title'), 'desc' => __('admin.enterprise_knowledge.atom_evidence_desc')],
            ['icon' => 'calendar-clock', 'title' => __('admin.enterprise_knowledge.atom_context_title'), 'desc' => __('admin.enterprise_knowledge.atom_context_desc')],
            ['icon' => 'shield-alert', 'title' => __('admin.enterprise_knowledge.atom_risk_title'), 'desc' => __('admin.enterprise_knowledge.atom_risk_desc')],
        ];
        $progressStepKeys = array_column($progressSteps, 'key');
        $progressStep = $status === 'failed'
            ? 'failed'
            : (string) ($progress['step'] ?? ($draftReady ? 'completed' : $status));
        $currentStepIndex = array_search($progressStep, $progressStepKeys, true);
        $currentStepIndex = $currentStepIndex === false ? -1 : (int) $currentStepIndex;
        $progressValue = (int) ($progress['progress'] ?? ($draftReady ? 100 : ($isGenerating ? 8 : ($status === 'failed' ? 100 : 0))));
        $progressValue = max(0, min(100, $progressValue));
        $statusLabelKey = 'admin.enterprise_knowledge.status_'.$status;
        $statusLabel = \Illuminate\Support\Facades\Lang::has($statusLabelKey) ? __($statusLabelKey) : $status;
        $progressMessageKey = 'admin.enterprise_knowledge.progress_message.'.$status;
        $progressFallbackMessage = $status === 'failed'
            ? __('admin.enterprise_knowledge.progress_message.failed', ['message' => (string) ($project->error_message ?: __('admin.enterprise_knowledge.status_failed'))])
            : (\Illuminate\Support\Facades\Lang::has($progressMessageKey) ? __($progressMessageKey) : __('admin.enterprise_knowledge.progress_message.queued'));
        $progressMessage = (string) ($progress['message'] ?? $progressFallbackMessage);
        $progressStepLabelKey = 'admin.enterprise_knowledge.progress_step_'.$progressStep;
        $progressStepLabel = \Illuminate\Support\Facades\Lang::has($progressStepLabelKey) ? __($progressStepLabelKey) : $progressStep;
        $progressUpdated = (string) ($progress['updated_at'] ?? optional($project->updated_at)->toIso8601String());
        $enterpriseStatusUrl = \App\Support\AdminWeb::routePath('admin.enterprise-knowledge.status', ['projectId' => (int) $project->id]);
        $enterpriseAutosaveUrl = \App\Support\AdminWeb::routePath('admin.enterprise-knowledge.autosave', ['projectId' => (int) $project->id]);
        $enterpriseValidateUrl = \App\Support\AdminWeb::routePath('admin.enterprise-knowledge.validate', ['projectId' => (int) $project->id]);
        $enterpriseImageUploadUrl = \App\Support\AdminWeb::routePath('admin.enterprise-knowledge.editor.images.upload', ['projectId' => (int) $project->id]);
    @endphp

    <div class="enterprise-knowledge-workspace px-4 sm:px-0 xl:-mx-6 2xl:-mx-12">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.enterprise-knowledge.index') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $project->name }}</h1>
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusStyles[$status] ?? $statusStyles['draft'] }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">{{ $project->description ?: __('admin.enterprise_knowledge.no_description') }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 sm:justify-end">
                @if ($draftReady)
                    <a href="#validation-section" class="inline-flex items-center justify-center rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                        <i data-lucide="shield-check" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.enterprise_knowledge.validation_title') }}
                    </a>
                    <a href="#revision-section" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <i data-lucide="history" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.enterprise_knowledge.revisions') }}
                    </a>
                @endif
                <a href="{{ route('admin.enterprise-knowledge.create') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.enterprise_knowledge.new_project') }}
                </a>
            </div>
        </div>

        @if ($showProgressPanel)
            <section
                id="enterprise-progress-panel"
                data-poll="{{ $isGenerating ? '1' : '0' }}"
                data-status-url="{{ $enterpriseStatusUrl }}"
                class="mb-6 overflow-hidden rounded-lg border border-blue-100 bg-white shadow"
            >
                <div class="border-b border-gray-200 px-6 py-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.progress_title') }}</h2>
                                @if ($isGenerating)
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                        <span class="mr-1.5 h-2 w-2 rounded-full bg-blue-500"></span>
                                        {{ __('admin.enterprise_knowledge.processing') }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.enterprise_knowledge.progress_desc') }}</p>
                        </div>
                        <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 lg:min-w-[480px]">
                            <div class="rounded-md bg-gray-50 px-4 py-3">
                                <div class="text-xs font-medium text-gray-500">{{ __('admin.enterprise_knowledge.progress_node') }}</div>
                                <div id="enterprise-progress-node" class="mt-1 font-semibold text-gray-900">
                                    {{ $progressStepLabel }}
                                </div>
                            </div>
                            <div class="rounded-md bg-gray-50 px-4 py-3">
                                <div class="text-xs font-medium text-gray-500">{{ __('admin.enterprise_knowledge.progress_percent') }}</div>
                                <div id="enterprise-progress-percent" class="mt-1 font-semibold text-gray-900">{{ $progressValue }}%</div>
                            </div>
                            <div class="rounded-md bg-gray-50 px-4 py-3">
                                <div class="text-xs font-medium text-gray-500">{{ __('admin.enterprise_knowledge.progress_updated') }}</div>
                                <div id="enterprise-progress-updated" class="mt-1 font-semibold text-gray-900">{{ $progressUpdated ? \Illuminate\Support\Carbon::parse($progressUpdated)->format('Y-m-d H:i:s') : '-' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div id="enterprise-progress-bar" class="h-full rounded-full bg-blue-600 transition-all duration-500" style="width: {{ $progressValue }}%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-0 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($progressSteps as $index => $step)
                            @php
                                $isDone = $status !== 'failed' && ($draftReady || ($currentStepIndex >= 0 && $index < $currentStepIndex));
                                $isActive = $status !== 'failed' && $index === $currentStepIndex && ! $draftReady;
                                $isFailed = $status === 'failed' && $step['key'] === 'failed';
                                $cardClass = $isFailed
                                    ? 'border-red-200 bg-red-50'
                                    : ($isDone ? 'border-emerald-200 bg-emerald-50' : ($isActive ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50'));
                                $badgeClass = $isFailed
                                    ? 'bg-red-600 text-white'
                                    : ($isDone ? 'bg-emerald-600 text-white' : ($isActive ? 'bg-blue-600 text-white' : 'bg-white text-gray-400 ring-1 ring-gray-200'));
                            @endphp
                            <div data-progress-step="{{ $step['key'] }}" class="rounded-lg border p-4 {{ $cardClass }}">
                                <div class="flex items-center gap-3">
                                    <span data-progress-badge class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold {{ $badgeClass }}">
                                        {{ $index + 1 }}
                                    </span>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.progress_step_'.$step['key']) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">{{ __('admin.enterprise_knowledge.progress_step_desc_'.$step['key']) }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <aside class="border-t border-gray-200 p-6 lg:border-l lg:border-t-0">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.progress_tip_title') }}</h3>
                        <div id="enterprise-progress-message" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">
                            {{ $progressMessage }}
                        </div>
                        <div class="mt-4 space-y-3">
                            @foreach (__('admin.enterprise_knowledge.progress_tips') as $tip)
                                <div class="flex gap-2 text-sm leading-6 text-gray-600">
                                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500"></span>
                                    <span>{{ $tip }}</span>
                                </div>
                            @endforeach
                        </div>
                    </aside>
                </div>
            </section>
        @endif

        <div class="space-y-6">
            <section class="overflow-hidden rounded-lg border border-orange-100 bg-white shadow">
                <div class="border-b border-orange-100 bg-orange-50/60 px-6 py-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.atom_panel_title') }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ __('admin.enterprise_knowledge.atom_editor_desc') }}</p>
                </div>
                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($atomStandards as $standard)
                        <article class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-md bg-white text-orange-600 ring-1 ring-orange-100">
                                <i data-lucide="{{ $standard['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ $standard['title'] }}</h3>
                            <p class="mt-1 text-xs leading-5 text-gray-500">{{ $standard['desc'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-6 py-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.editor_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.editor_desc') }}</p>
                    </div>
                    @if ($draftReady)
                        <div id="autosave-status" class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                            {{ __('admin.enterprise_knowledge.autosave_idle') }}
                        </div>
                    @endif
                </div>
                <div class="space-y-5 px-6 py-5">
                    @if ($draftReady)
                        <textarea id="enterprise-draft-content" class="hidden">{{ old('content', (string) ($project->draft_content ?? '')) }}</textarea>
                        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                                    <i data-lucide="file-pen-line" class="mr-1.5 h-3.5 w-3.5"></i>
                                    {{ __('admin.enterprise_knowledge.editor_badge') }}
                                </div>
                                <div class="text-xs leading-5 text-gray-500">
                                    {{ __('admin.enterprise_knowledge.editor_mode_hint') }}
                                </div>
                            </div>
                            <div
                                id="enterprise-draft-editor"
                                class="enterprise-markdown-editor"
                                data-upload-url="{{ $enterpriseImageUploadUrl }}"
                            ></div>
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-gray-500">
                                {{ __('admin.enterprise_knowledge.editor_hint') }}
                            </div>
                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" id="save-now-button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.enterprise_knowledge.save_now') }}
                                </button>
                                <button type="button" id="validate-button" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                                    <i data-lucide="shield-check" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.enterprise_knowledge.validate') }}
                                </button>
                                <form method="POST" action="{{ route('admin.enterprise-knowledge.publish', ['projectId' => (int) $project->id]) }}" id="publish-form">
                                    @csrf
                                    <button type="button" id="publish-button" class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                        <i data-lucide="database-zap" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.enterprise_knowledge.publish') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
                            <i data-lucide="{{ $status === 'failed' ? 'triangle-alert' : 'loader-2' }}" class="mx-auto h-8 w-8 {{ $status === 'failed' ? 'text-red-500' : 'animate-spin text-blue-600' }}"></i>
                            <h3 class="mt-4 text-base font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.progress_waiting_editor') }}</h3>
                            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600">{{ __('admin.enterprise_knowledge.progress_waiting_editor_desc') }}</p>
                            @if ($status === 'failed' && $project->error_message)
                                <div class="mx-auto mt-4 max-w-xl rounded-md border border-red-100 bg-red-50 px-4 py-3 text-left text-sm leading-6 text-red-700">
                                    {{ $project->error_message }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            @if ($draftReady)
                <section id="validation-section" class="scroll-mt-24 overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.validation_title') }}</h2>
                    </div>
                    <div id="validation-list" class="space-y-3 px-6 py-5"></div>
                </section>
            @endif

            <section id="revision-section" class="scroll-mt-24 overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.revisions') }}</h2>
                </div>
                <div class="divide-y divide-gray-200">
                    @forelse ($project->revisions->sortByDesc('created_at') as $revision)
                        <div class="px-6 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $revision->summary }}</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ strtoupper((string) $revision->source) }} · {{ optional($revision->created_at)->format('Y-m-d H:i:s') }}
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('admin.enterprise-knowledge.revisions.restore', ['projectId' => (int) $project->id, 'revisionId' => (int) $revision->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                                        {{ __('admin.enterprise_knowledge.revision_restore_action') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.no_data') }}</div>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.sources') }}</h2>
                </div>
                <div class="divide-y divide-gray-200">
                    @foreach ($project->sources->sortBy('sort_order') as $source)
                        <div class="px-6 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-gray-900">{{ $source->original_name }}</div>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ strtoupper((string) $source->file_type) }}</span>
                            </div>
                            <p class="mt-2 line-clamp-2 text-sm leading-6 text-gray-500">{{ Str::limit(trim((string) $source->content), 180) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            @if ($project->publishedKnowledgeBase)
                <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $project->publishedKnowledgeBase->id]) }}" class="flex items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                    <i data-lucide="database" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.enterprise_knowledge.published_link') }}
                </a>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const labels = {
                validationPassed: @json(__('admin.enterprise_knowledge.validation_passed')),
                validationOverall: @json(__('admin.enterprise_knowledge.validation.overall')),
                autosaveIdle: @json(__('admin.enterprise_knowledge.autosave_idle')),
                autosaveSaving: @json(__('admin.enterprise_knowledge.autosave_saving')),
                autosaveSaved: @json(__('admin.enterprise_knowledge.autosave_saved', ['time' => '__TIME__'])),
                autosaveFailed: @json(__('admin.enterprise_knowledge.autosave_failed')),
                imageUploading: @json(__('admin.enterprise_knowledge.editor_uploading')),
                imageUploadSuccess: @json(__('admin.enterprise_knowledge.editor_upload_success')),
                imageUploadFailed: @json(__('admin.enterprise_knowledge.editor_upload_failed')),
            };
            const progressLabels = @json(collect($progressSteps)->mapWithKeys(fn ($step) => [$step['key'] => __('admin.enterprise_knowledge.progress_step_'.$step['key'])]));
            const stepOrder = @json($progressStepKeys);

            const escapeHtml = (value) => String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const formatDateTime = (value) => {
                if (! value) {
                    return '-';
                }
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) {
                    return value;
                }

                const pad = (number) => String(number).padStart(2, '0');
                return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
            };

            const progressPanel = document.getElementById('enterprise-progress-panel');
            const progressBar = document.getElementById('enterprise-progress-bar');
            const progressPercent = document.getElementById('enterprise-progress-percent');
            const progressNode = document.getElementById('enterprise-progress-node');
            const progressMessage = document.getElementById('enterprise-progress-message');
            const progressUpdated = document.getElementById('enterprise-progress-updated');
            const progressStepCards = Array.from(document.querySelectorAll('[data-progress-step]'));
            let progressTimer = null;

            const setProgressStepClasses = (step, status) => {
                const activeIndex = stepOrder.indexOf(step);
                progressStepCards.forEach((card, index) => {
                    const badge = card.querySelector('[data-progress-badge]');
                    const failed = status === 'failed' && card.dataset.progressStep === 'failed';
                    const done = status !== 'failed' && (status === 'reviewing' || status === 'published' || step === 'completed' || (activeIndex >= 0 && index < activeIndex));
                    const active = activeIndex === index && ! done && ! failed;

                    card.className = `rounded-lg border p-4 ${
                        failed ? 'border-red-200 bg-red-50' : (done ? 'border-emerald-200 bg-emerald-50' : (active ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50'))
                    }`;
                    if (badge) {
                        badge.className = `flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold ${
                            failed ? 'bg-red-600 text-white' : (done ? 'bg-emerald-600 text-white' : (active ? 'bg-blue-600 text-white' : 'bg-white text-gray-400 ring-1 ring-gray-200'))
                        }`;
                    }
                });
            };

            const applyProgress = (payload) => {
                const progress = payload.progress || {};
                const percent = Math.max(0, Math.min(100, Number.parseInt(progress.progress || 0, 10)));
                const step = String(progress.step || payload.status || 'queued');

                if (progressBar) {
                    progressBar.style.width = `${percent}%`;
                }
                if (progressPercent) {
                    progressPercent.textContent = `${percent}%`;
                }
                if (progressNode) {
                    progressNode.textContent = progressLabels[step] || payload.status_label || step;
                }
                if (progressMessage) {
                    progressMessage.textContent = progress.message || '';
                }
                if (progressUpdated) {
                    progressUpdated.textContent = formatDateTime(progress.updated_at);
                }
                setProgressStepClasses(step, payload.status || '');

                if (payload.reload) {
                    clearInterval(progressTimer);
                    progressTimer = null;
                    window.setTimeout(() => window.location.reload(), 900);
                }
            };

            if (progressPanel?.dataset.poll === '1' && progressPanel.dataset.statusUrl) {
                progressTimer = window.setInterval(async () => {
                    try {
                        const response = await fetch(progressPanel.dataset.statusUrl, {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (! response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }
                        applyProgress(await response.json());
                    } catch (error) {
                        clearInterval(progressTimer);
                        progressTimer = null;
                    }
                }, 2500);
            }

            const textarea = document.getElementById('enterprise-draft-content');
            const autosaveStatus = document.getElementById('autosave-status');
            const validationList = document.getElementById('validation-list');
            const saveButton = document.getElementById('save-now-button');
            const validateButton = document.getElementById('validate-button');
            const publishButton = document.getElementById('publish-button');
            const publishForm = document.getElementById('publish-form');
            const editorNode = document.getElementById('enterprise-draft-editor');
            const saveUrl = @json($enterpriseAutosaveUrl);
            const validateUrl = @json($enterpriseValidateUrl);
            const imageUploadUrl = editorNode?.dataset.uploadUrl || '';

            if (! textarea || ! autosaveStatus || ! validationList) {
                return;
            }

            let validationItems = @json($validationItems);
            let timer = null;
            let lastSavedContent = textarea.value;
            let editor = null;

            const renderValidation = () => {
                if (!Array.isArray(validationItems) || validationItems.length === 0) {
                    validationList.innerHTML = `<div class="rounded-md border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">${escapeHtml(labels.validationPassed)}</div>`;
                    return;
                }

                validationList.innerHTML = validationItems.map((item) => {
                    const level = item.level || 'info';
                    const styles = {
                        danger: 'border-red-100 bg-red-50 text-red-700',
                        warning: 'border-amber-100 bg-amber-50 text-amber-700',
                        info: 'border-blue-100 bg-blue-50 text-blue-700',
                    }[level] || 'border-gray-100 bg-gray-50 text-gray-700';
                    return `<div class="rounded-md border px-4 py-3 text-sm ${styles}">
                        <div class="font-semibold">${escapeHtml(item.section || labels.validationOverall)}</div>
                        <div class="mt-1 leading-6">${escapeHtml(item.message || '')}</div>
                    </div>`;
                }).join('');
            };

            const setStatus = (text, tone = 'idle') => {
                const styles = {
                    idle: 'bg-gray-100 text-gray-600',
                    saving: 'bg-blue-50 text-blue-700',
                    saved: 'bg-emerald-50 text-emerald-700',
                    failed: 'bg-red-50 text-red-700',
                };
                autosaveStatus.className = `inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ${styles[tone] || styles.idle}`;
                autosaveStatus.textContent = text;
            };

            const getEditorContent = () => {
                if (editor && typeof editor.getValue === 'function') {
                    return editor.getValue();
                }

                return textarea.value;
            };

            const syncEditorToTextarea = () => {
                const content = getEditorContent();
                textarea.value = content;

                return content;
            };

            const scheduleAutosave = () => {
                setStatus(labels.autosaveIdle, 'idle');
                clearTimeout(timer);
                timer = setTimeout(saveContent, 1500);
            };

            const uploadEnterpriseImages = async (files) => {
                const fileList = Array.from(files || []).filter(Boolean);
                if (fileList.length === 0 || ! imageUploadUrl) {
                    return '';
                }

                setStatus(labels.imageUploading, 'saving');

                const markdownLines = [];
                try {
                    for (const file of fileList) {
                        const formData = new FormData();
                        formData.append('image', file);

                        const response = await fetch(imageUploadUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: formData,
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            throw new Error(payload.message || labels.imageUploadFailed);
                        }

                        if (payload.image?.markdown) {
                            markdownLines.push(payload.image.markdown);
                        }
                    }

                    setStatus(labels.imageUploadSuccess, 'saved');

                    return markdownLines.join('\n\n');
                } catch (error) {
                    setStatus(error.message || labels.imageUploadFailed, 'failed');

                    return '';
                }
            };

            const initializeEditor = () => {
                if (! editorNode) {
                    textarea.className = 'block w-full rounded-md border-gray-300 font-mono text-sm leading-7 shadow-sm focus:border-blue-500 focus:ring-blue-500';
                    textarea.addEventListener('input', scheduleAutosave);
                    return;
                }

                if (typeof Vditor === 'undefined') {
                    editorNode.classList.add('hidden');
                    textarea.className = 'block w-full rounded-md border-gray-300 font-mono text-sm leading-7 shadow-sm focus:border-blue-500 focus:ring-blue-500';
                    textarea.rows = 28;
                    textarea.addEventListener('input', scheduleAutosave);
                    return;
                }

                editor = new Vditor('enterprise-draft-editor', {
                    value: textarea.value || '',
                    height: 720,
                    mode: 'wysiwyg',
                    cdn: @json(asset('vendor/vditor')),
                    lang: @json($vditorLang),
                    cache: {
                        enable: false,
                    },
                    preview: {
                        markdown: {
                            toc: true,
                        },
                        hljs: {
                            lineNumber: false,
                        },
                    },
                    toolbar: [
                        'emoji',
                        'headings',
                        'bold',
                        'italic',
                        'strike',
                        '|',
                        'line',
                        'quote',
                        'list',
                        'ordered-list',
                        'check',
                        '|',
                        'code',
                        'inline-code',
                        'table',
                        'link',
                        'upload',
                        '|',
                        'undo',
                        'redo',
                        'fullscreen',
                    ],
                    upload: {
                        accept: 'image/*',
                        multiple: true,
                        max: 10 * 1024 * 1024,
                        handler: uploadEnterpriseImages,
                    },
                    input(value) {
                        textarea.value = value;
                        scheduleAutosave();
                    },
                    after() {
                        textarea.value = getEditorContent();
                        if (window.lucide) {
                            window.lucide.createIcons();
                        }
                    },
                });
            };

            const saveContent = async () => {
                const content = syncEditorToTextarea();
                if (content === lastSavedContent) {
                    return true;
                }

                setStatus(labels.autosaveSaving, 'saving');
                try {
                    const response = await fetch(saveUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ content }),
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const payload = await response.json();
                    validationItems = payload.validation_items || [];
                    lastSavedContent = content;
                    setStatus(labels.autosaveSaved.replace('__TIME__', payload.saved_at || ''), 'saved');
                    renderValidation();
                    return true;
                } catch (error) {
                    setStatus(labels.autosaveFailed, 'failed');
                    return false;
                }
            };

            const validateContent = async () => {
                await saveContent();
                try {
                    const response = await fetch(validateUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ content: textarea.value }),
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const payload = await response.json();
                    validationItems = payload.validation_items || [];
                    renderValidation();
                } catch (error) {
                    setStatus(labels.autosaveFailed, 'failed');
                }
            };

            saveButton?.addEventListener('click', saveContent);
            validateButton?.addEventListener('click', validateContent);
            publishButton?.addEventListener('click', async () => {
                publishButton.disabled = true;
                publishButton.classList.add('opacity-70');
                const saved = await saveContent();
                if (saved && publishForm) {
                    publishForm.submit();
                    return;
                }
                publishButton.disabled = false;
                publishButton.classList.remove('opacity-70');
            });

            initializeEditor();
            renderValidation();
        });
    </script>
@endsection
