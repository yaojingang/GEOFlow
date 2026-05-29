@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_bases.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.subtitle') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.create_first') }}
                </a>
                <a href="{{ route('admin.knowledge-bases.create', ['mode' => 'upload']) }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.import_unified') }}
                </a>
            </div>
        </div>

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
                    <div class="text-right" style="width: 440px;">{{ __('admin.common.actions') }}</div>
                </div>
                <div class="divide-y divide-gray-200">
                    @foreach ($knowledgeBases as $item)
                        <div class="px-6 py-6">
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
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                            {{ __('admin.knowledge_bases.text_unit', ['count' => number_format((int) $item['word_count'])]) }}
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

                                <div class="flex flex-wrap items-start justify-start gap-2 lg:shrink-0 lg:justify-end lg:pl-8" style="width: 440px;">
                                    @if ($hasDefaultEmbeddingModel)
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
                                            >
                                                @csrf
                                                <button type="submit" class="inline-flex w-full items-center justify-center px-3 py-1.5 border border-emerald-200 text-xs font-medium rounded text-emerald-700 bg-emerald-50 hover:bg-emerald-100" data-refresh-submit-button>
                                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-1" data-refresh-submit-icon></i>
                                                    <span data-refresh-submit-label>{{ __('admin.knowledge_bases.refresh_chunks') }}</span>
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
                                        <button type="button" onclick="showEmbeddingConfigModal()" class="inline-flex items-center px-3 py-1.5 border border-amber-200 text-xs font-medium rounded text-amber-800 bg-amber-50 hover:bg-amber-100">
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
                                    <form method="POST" action="{{ route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $item['id']]) }}" onsubmit="return confirm(@js(__('admin.knowledge_bases.confirm_delete', ['name' => $item['name']])));" class="inline-block">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div id="embedding-config-modal" class="hidden fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/45"></div>
        <div class="relative flex min-h-screen items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('admin.knowledge_bases.vector_config_modal_title') }}</h3>
                </div>
                <div class="px-6 py-5">
                    <div class="text-sm leading-7 text-slate-600 whitespace-pre-line">{{ __('admin.knowledge_bases.vector_config_prompt') }}</div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button type="button" onclick="hideEmbeddingConfigModal()" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        {{ __('admin.button.cancel') }}
                    </button>
                    <a href="{{ route('admin.ai.configurator') }}" class="inline-flex items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                        {{ __('admin.knowledge_bases.vector_notice_configure_link') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="refresh-chunks-modal" class="hidden fixed inset-0 z-50" data-knowledge-refresh-modal>
        <div class="absolute inset-0 bg-slate-900/45" data-refresh-chunks-cancel></div>
        <div class="relative flex min-h-screen items-center justify-center p-4">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                <div class="border-b border-slate-100 px-6 py-5">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <i data-lucide="refresh-cw" class="h-5 w-5"></i>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-slate-900">{{ __('admin.knowledge_bases.refresh_confirm_title') }}</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('admin.knowledge_bases.refresh_confirm_intro') }}</p>
                        </div>
                    </div>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('admin.knowledge_bases.refresh_confirm_target') }}</div>
                        <div class="mt-1 text-sm font-semibold text-slate-900" data-refresh-modal-name>-</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                            <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-slate-200" data-refresh-modal-summary>-</span>
                            <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-slate-200" data-refresh-modal-words>-</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-3">
                            <div class="text-sm font-semibold text-emerald-800">{{ __('admin.knowledge_bases.refresh_confirm_rebuild') }}</div>
                            <p class="mt-1 text-xs leading-5 text-emerald-700">{{ __('admin.knowledge_bases.refresh_confirm_rebuild_desc') }}</p>
                        </div>
                        <div class="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3">
                            <div class="text-sm font-semibold text-blue-800">{{ __('admin.knowledge_bases.refresh_confirm_embedding') }}</div>
                            <p class="mt-1 text-xs leading-5 text-blue-700">{{ __('admin.knowledge_bases.refresh_confirm_embedding_desc') }}</p>
                        </div>
                        <div class="rounded-xl border border-purple-100 bg-purple-50 px-3 py-3">
                            <div class="text-sm font-semibold text-purple-800">{{ __('admin.knowledge_bases.refresh_confirm_write') }}</div>
                            <p class="mt-1 text-xs leading-5 text-purple-700">{{ __('admin.knowledge_bases.refresh_confirm_write_desc') }}</p>
                        </div>
                    </div>
                    <p class="text-sm leading-6 text-slate-600">{{ __('admin.knowledge_bases.refresh_confirm_body') }}</p>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-refresh-chunks-cancel>
                        {{ __('admin.button.cancel') }}
                    </button>
                    <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-refresh-chunks-confirm>
                        <i data-lucide="play" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.knowledge_bases.refresh_confirm_continue') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        let pendingRefreshChunksForm = null;
        let refreshChunksTimer = null;

        function showEmbeddingConfigModal() {
            const modal = document.getElementById('embedding-config-modal');
            if (modal) {
                modal.classList.remove('hidden');
            }
        }

        function hideEmbeddingConfigModal() {
            const modal = document.getElementById('embedding-config-modal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        function showRefreshChunksModal(form) {
            const modal = document.querySelector('[data-knowledge-refresh-modal]');
            if (!modal) {
                return true;
            }

            pendingRefreshChunksForm = form;
            const nameNode = modal.querySelector('[data-refresh-modal-name]');
            const summaryNode = modal.querySelector('[data-refresh-modal-summary]');
            const wordsNode = modal.querySelector('[data-refresh-modal-words]');

            if (nameNode) {
                nameNode.textContent = form.dataset.knowledgeName || '-';
            }
            if (summaryNode) {
                summaryNode.textContent = form.dataset.knowledgeSummary || '-';
            }
            if (wordsNode) {
                wordsNode.textContent = form.dataset.wordCount || '-';
            }

            modal.classList.remove('hidden');
            const confirmButton = modal.querySelector('[data-refresh-chunks-confirm]');
            if (confirmButton) {
                setTimeout(function () {
                    confirmButton.focus();
                }, 0);
            }

            return false;
        }

        function hideRefreshChunksModal() {
            const modal = document.querySelector('[data-knowledge-refresh-modal]');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

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
            document.querySelectorAll('[data-refresh-chunks-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    showRefreshChunksModal(form);
                });
            });

            document.querySelectorAll('[data-refresh-chunks-cancel]').forEach(function (button) {
                button.addEventListener('click', function () {
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                });
            });

            const refreshConfirmButton = document.querySelector('[data-refresh-chunks-confirm]');
            if (refreshConfirmButton) {
                refreshConfirmButton.addEventListener('click', function () {
                    if (!pendingRefreshChunksForm) {
                        hideRefreshChunksModal();
                        return;
                    }

                    const form = pendingRefreshChunksForm;
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                    startRefreshChunksProgress(form);
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pendingRefreshChunksForm) {
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                }
            });
        });

        window.addEventListener('click', function (event) {
            const embeddingConfigModal = document.getElementById('embedding-config-modal');

            if (event.target === embeddingConfigModal || (embeddingConfigModal && event.target === embeddingConfigModal.firstElementChild)) {
                hideEmbeddingConfigModal();
            }
        });
    </script>
@endpush
