@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin.knowledge_bases.heading') }}</h1>
                    <p>{{ __('admin.knowledge_bases.subtitle') }}</p>
                </div>
                <x-admin.v3.materials-subnav active="knowledge-bases" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.create_first') }}
                </a>
                <a href="{{ route('admin.knowledge-bases.create', ['mode' => 'upload']) }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.import_unified') }}
                </a>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_knowledge'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.total_words') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['total_words'] ?? 0)) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hash" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.markdown_count') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['markdown_count'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.word_count') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['word_count'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_bases.list_title') }}</h3>
            </div>
            @if (empty($knowledgeBases))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="brain" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.knowledge_bases.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.knowledge_bases.empty_desc') }}</p>
                    <div class="flex justify-center space-x-2">
                        <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.create_first') }}
                        </a>
                        <a href="{{ route('admin.knowledge-bases.create', ['mode' => 'upload']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.import_unified') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="flex items-center justify-between gap-6 px-6 py-3 border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <div>{{ __('admin.knowledge_bases.column_knowledge_base') }}</div>
                    <div class="hidden w-[440px] text-right lg:block">{{ __('admin.common.actions') }}</div>
                </div>
                <div class="divide-y divide-gray-200">
                    @foreach ($knowledgeBases as $item)
                        @php
                            $isSystemKnowledge = (bool) ($item['is_system'] ?? false);
                            $systemHealth = is_array($item['system_health'] ?? null) ? $item['system_health'] : null;
                        @endphp
                        <div class="px-6 py-6" data-system-knowledge="{{ $isSystemKnowledge ? 'true' : 'false' }}">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
                                <div class="min-w-0 lg:flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $item['id']]) }}" class="hover:text-orange-600">
                                                {{ $item['name'] }}
                                            </a>
                                        </h4>
                                        @php
                                            $type = (string) ($item['file_type'] ?? 'markdown');
                                            $typeBadgeClass = $type === 'markdown'
                                                ? 'bg-green-100 text-green-800'
                                                : ($type === 'word' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800');
                                            $typeText = $type === 'markdown'
                                                ? __('admin.status.markdown')
                                                : ($type === 'word' ? __('admin.status.word_document') : __('admin.status.text'));
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $typeBadgeClass }}">
                                            {{ $typeText }}
                                        </span>
                                        @if ($isSystemKnowledge)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 ring-1 ring-inset ring-orange-200">
                                                <i data-lucide="sparkles" class="h-3.5 w-3.5"></i>
                                                {{ __('admin.knowledge_bases.system_badge') }}
                                            </span>
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                                {{ __('admin.knowledge_bases.system_health.'.($systemHealth['status'] ?? 'fallback')) }}
                                            </span>
                                        @endif
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                            {{ __('admin.knowledge_bases.text_unit', ['count' => number_format((int) $item['word_count'])]) }}
                                        </span>
                                        @php
                                            $syncStatus = (string) ($item['chunk_sync_status'] ?? 'idle');
                                            $syncStatusClass = match ($syncStatus) {
                                                'pending', 'processing' => 'bg-amber-50 text-amber-700',
                                                'ready' => 'bg-emerald-50 text-emerald-700',
                                                'failed' => 'bg-red-50 text-red-700',
                                                default => 'bg-gray-50 text-gray-600',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $syncStatusClass }}">
                                            {{ __('admin.knowledge_bases.sync_status.'.$syncStatus) }}
                                        </span>
                                        @if ((int) ($item['chunk_count'] ?? 0) > 0)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                                {{ __('admin.knowledge_bases.vectorized_summary', [
                                                    'vectorized' => (int) ($item['vectorized_chunk_count'] ?? 0),
                                                    'chunks' => (int) ($item['chunk_count'] ?? 0),
                                                ]) }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($item['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $item['description'] }}</p>
                                    @endif
                                    @if ($isSystemKnowledge)
                                        <p class="mt-2 flex items-start gap-2 text-sm leading-6 text-orange-800">
                                            <i data-lucide="shield-check" class="mt-0.5 h-4 w-4 shrink-0"></i>
                                            <span>
                                                {{ __('admin.knowledge_bases.system_protected_hint') }}
                                                {{ __('admin.knowledge_bases.system_version', ['version' => (string) ($item['official_version'] ?? '-')]) }}
                                            </span>
                                        </p>
                                    @endif
                                    @if ($syncStatus === 'failed' && ($item['chunk_sync_error'] ?? '') !== '')
                                        <p class="mt-1 text-sm text-red-600">{{ $item['chunk_sync_error'] }}</p>
                                    @endif
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
                                        <span>
                                            {{ __('admin.knowledge_bases.created_at', ['value' => $item['created_at'] ? \Illuminate\Support\Carbon::parse($item['created_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        <span>
                                            {{ __('admin.knowledge_bases.updated_at', ['value' => $item['updated_at'] ? \Illuminate\Support\Carbon::parse($item['updated_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        @if ((int) ($item['usage_count'] ?? 0) > 0)
                                            <span>{{ __('admin.knowledge_bases.usage_count', ['count' => (int) $item['usage_count']]) }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex w-full flex-wrap items-start justify-start gap-2 lg:w-[440px] lg:shrink-0 lg:justify-end lg:pl-8">
                                    @if ($isSystemKnowledge || $hasDefaultEmbeddingModel)
                                        <div style="width: 148px;" data-refresh-chunks-action>
                                            <form
                                                method="POST"
                                                action="{{ route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $item['id']]) }}"
                                                class="inline-block"
                                                data-refresh-chunks-form
                                                data-knowledge-name="{{ $item['name'] }}"
                                                data-knowledge-summary="{{ __('admin.knowledge_bases.vectorized_summary', [
                                                    'vectorized' => (int) ($item['vectorized_chunk_count'] ?? 0),
                                                    'chunks' => (int) ($item['chunk_count'] ?? 0),
                                                ]) }}"
                                                data-word-count="{{ __('admin.knowledge_bases.text_unit', ['count' => number_format((int) $item['word_count'])]) }}"
                                                data-dialog-title="{{ __('admin.knowledge_bases.refresh_confirm_title') }}"
                                                data-dialog-intro="{{ __('admin.knowledge_bases.refresh_confirm_intro') }}"
                                                data-dialog-target-label="{{ __('admin.knowledge_bases.refresh_confirm_target') }}"
                                                data-dialog-guidance="{{ __('admin.knowledge_bases.refresh_confirm_rebuild') }}：{{ __('admin.knowledge_bases.refresh_confirm_rebuild_desc') }}&#10;{{ __('admin.knowledge_bases.refresh_confirm_embedding') }}：{{ __('admin.knowledge_bases.refresh_confirm_embedding_desc') }}&#10;{{ __('admin.knowledge_bases.refresh_confirm_write') }}：{{ __('admin.knowledge_bases.refresh_confirm_write_desc') }}&#10;{{ __('admin.knowledge_bases.refresh_confirm_body') }}"
                                                data-dialog-confirm-label="{{ __('admin.knowledge_bases.refresh_confirm_continue') }}"
                                                data-dialog-cancel-label="{{ __('admin.button.cancel') }}"
                                            >
                                                @csrf
                                                <button type="submit" class="inline-flex w-full items-center justify-center px-3 py-1.5 border border-emerald-200 text-xs font-medium rounded text-emerald-700 bg-emerald-50 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60" data-refresh-submit-button disabled aria-disabled="true">
                                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-1" data-refresh-submit-icon></i>
                                                    <span data-refresh-submit-label>{{ $isSystemKnowledge ? __('admin.knowledge_bases.system_refresh_index') : __('admin.knowledge_bases.refresh_chunks') }}</span>
                                                </button>
                                            </form>
                                            <div class="mt-2 hidden" data-refresh-progress>
                                                <div class="flex items-center justify-between text-[11px] font-medium text-emerald-700">
                                                    <span data-refresh-progress-label>{{ __('admin.knowledge_bases.refresh_progress_initial') }}</span>
                                                    <span data-refresh-progress-value>0%</span>
                                                </div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-emerald-100">
                                                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-500 ease-out" style="width: 8%;" data-refresh-progress-bar></div>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <button type="button" data-embedding-config-open class="inline-flex items-center px-3 py-1.5 border border-amber-200 text-xs font-medium rounded text-amber-800 bg-amber-50 hover:bg-amber-100">
                                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.knowledge_bases.refresh_chunks') }}
                                        </button>
                                    @endif
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $item['id']]) }}#chunk-preview" class="inline-flex items-center px-3 py-1.5 border border-blue-200 text-xs font-medium rounded text-blue-700 bg-blue-50 hover:bg-blue-100">
                                        <i data-lucide="rows-3" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.chunks') }}
                                    </a>
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $item['id']]) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    @unless ($isSystemKnowledge)
                                        <form method="POST" action="{{ route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $item['id']]) }}" class="inline-block" data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.knowledge_bases.confirm_delete', ['name' => $item['name']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700" data-admin-confirm-submit disabled aria-disabled="true">
                                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                                {{ __('admin.button.delete') }}
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        let refreshChunksTimer = null;

        function startRefreshChunksProgress(form) {
            const wrapper = form.closest('[data-refresh-chunks-action]');
            const button = form.querySelector('[data-refresh-submit-button]');
            const icon = form.querySelector('[data-refresh-submit-icon]');
            const buttonLabel = form.querySelector('[data-refresh-submit-label]');
            const progress = wrapper ? wrapper.querySelector('[data-refresh-progress]') : null;
            const progressLabel = wrapper ? wrapper.querySelector('[data-refresh-progress-label]') : null;
            const progressValue = wrapper ? wrapper.querySelector('[data-refresh-progress-value]') : null;
            const progressBar = wrapper ? wrapper.querySelector('[data-refresh-progress-bar]') : null;
            let percent = 12;

            if (button) {
                button.disabled = true;
                button.classList.add('cursor-wait', 'opacity-80');
            }
            if (icon) {
                icon.classList.add('animate-spin');
            }
            if (buttonLabel) {
                buttonLabel.textContent = @json(__('admin.knowledge_bases.refresh_progress_button'));
            }
            if (progress) {
                progress.classList.remove('hidden');
            }

            const renderProgress = function () {
                if (progressValue) {
                    progressValue.textContent = percent + '%';
                }
                if (progressBar) {
                    progressBar.style.width = percent + '%';
                }
                if (progressLabel) {
                    progressLabel.textContent = percent >= 70
                        ? @json(__('admin.knowledge_bases.refresh_progress_writing'))
                        : (percent >= 38
                            ? @json(__('admin.knowledge_bases.refresh_progress_embedding'))
                            : @json(__('admin.knowledge_bases.refresh_progress_initial')));
                }
            };

            renderProgress();
            refreshChunksTimer = window.setInterval(function () {
                percent = Math.min(92, percent + (percent < 50 ? 11 : 6));
                renderProgress();
                if (percent >= 92 && refreshChunksTimer) {
                    window.clearInterval(refreshChunksTimer);
                    refreshChunksTimer = null;
                }
            }, 420);

            setTimeout(function () {
                form.submit();
            }, 180);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const actionDialog = window.AdminActionDialog;
            if (! actionDialog?.confirm) return;
            const confirmingForms = new WeakSet();

            document.querySelectorAll('[data-refresh-chunks-form]').forEach(function (form) {
                const button = form.querySelector('[data-refresh-submit-button]');
                if (button) {
                    button.disabled = false;
                    button.removeAttribute('aria-disabled');
                }

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    if (confirmingForms.has(form)) return;
                    confirmingForms.add(form);
                    const target = [form.dataset.knowledgeName, form.dataset.knowledgeSummary, form.dataset.wordCount]
                        .filter(Boolean)
                        .join(' · ');
                    const confirmed = await actionDialog.confirm({
                        title: form.dataset.dialogTitle || '',
                        message: `${form.dataset.dialogIntro || ''}\n${form.dataset.dialogTargetLabel || ''}：${target}`,
                        guidance: form.dataset.dialogGuidance || '',
                        tone: 'success',
                        confirmLabel: form.dataset.dialogConfirmLabel || '',
                        cancelLabel: form.dataset.dialogCancelLabel || '',
                        opener: event.submitter || button,
                    });
                    confirmingForms.delete(form);
                    if (confirmed === true) startRefreshChunksProgress(form);
                });
            });

            document.querySelectorAll('[data-embedding-config-open]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const confirmed = await actionDialog.confirm({
                        title: @json(__('admin.knowledge_bases.vector_config_modal_title')),
                        message: @json(__('admin.knowledge_bases.vector_config_prompt')),
                        tone: 'warning',
                        confirmLabel: @json(__('admin.knowledge_bases.vector_notice_configure_link')),
                        cancelLabel: @json(__('admin.button.cancel')),
                        opener: button,
                    });
                    if (confirmed === true) window.location.assign(@json(route('admin.ai.configurator')));
                });
            });
        });
    </script>
@endpush
