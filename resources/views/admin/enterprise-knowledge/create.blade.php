@extends('admin.layouts.app')

@section('content')
    @php
        $atomStandards = [
            ['icon' => 'message-square-quote', 'title' => __('admin.enterprise_knowledge.atom_claim_title'), 'desc' => __('admin.enterprise_knowledge.atom_claim_desc')],
            ['icon' => 'fingerprint', 'title' => __('admin.enterprise_knowledge.atom_evidence_title'), 'desc' => __('admin.enterprise_knowledge.atom_evidence_desc')],
            ['icon' => 'calendar-clock', 'title' => __('admin.enterprise_knowledge.atom_context_title'), 'desc' => __('admin.enterprise_knowledge.atom_context_desc')],
            ['icon' => 'shield-alert', 'title' => __('admin.enterprise_knowledge.atom_risk_title'), 'desc' => __('admin.enterprise_knowledge.atom_risk_desc')],
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center gap-4">
            <a href="{{ route('admin.enterprise-knowledge.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.enterprise_knowledge.create_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.create_subtitle') }}</p>
            </div>
        </div>

        <section class="mb-6 rounded-lg border border-orange-100 bg-white p-5 shadow">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.atom_panel_title') }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ __('admin.enterprise_knowledge.atom_panel_desc') }}</p>
                </div>
                <span class="inline-flex w-fit items-center rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700">
                    <i data-lucide="blocks" class="mr-1.5 h-3.5 w-3.5"></i>
                    {{ __('admin.enterprise_knowledge.atom_panel_badge') }}
                </span>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
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

        <form method="POST" action="{{ route('admin.enterprise-knowledge.store') }}" enctype="multipart/form-data" id="enterprise-knowledge-form" class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            @csrf

            <div class="space-y-6">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.form_basic') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.form_basic_desc') }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-5 px-6 py-5 md:grid-cols-2">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.enterprise_knowledge.name') }}</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" placeholder="{{ __('admin.enterprise_knowledge.name_placeholder') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.enterprise_knowledge.name_hint') }}</p>
                            @error('name')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700">{{ __('admin.enterprise_knowledge.description') }}</label>
                            <textarea name="description" id="description" rows="3" placeholder="{{ __('admin.enterprise_knowledge.description_placeholder') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">{{ old('description') }}</textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.upload_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.upload_desc') }}</p>
                    </div>
                    <div class="space-y-5 px-6 py-5">
                        <label for="enterprise_files" id="enterprise-file-dropzone" class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center transition hover:border-blue-300 hover:bg-blue-50/40">
                            <i data-lucide="upload-cloud" class="h-8 w-8 text-gray-400"></i>
                            <span class="mt-3 text-sm font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.dropzone_title') }}</span>
                            <span class="mt-1 text-xs text-gray-500">{{ __('admin.enterprise_knowledge.dropzone_desc') }}</span>
                            <input id="enterprise_files" name="enterprise_files[]" type="file" multiple accept=".txt,.md,.markdown,.docx" class="sr-only">
                        </label>
                        <div id="enterprise-file-list" class="hidden rounded-md border border-gray-200 bg-white p-4 text-sm text-gray-600"></div>
                        @error('enterprise_files')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @error('enterprise_files.*')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.content_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.content_desc') }}</p>
                    </div>
                    <div class="px-6 py-5">
                        <textarea name="content" id="content" rows="14" placeholder="{{ __('admin.enterprise_knowledge.content_placeholder') }}" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">{{ old('content') }}</textarea>
                        @error('content')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </section>

                <div class="flex justify-end gap-3">
                    <a href="{{ route('admin.enterprise-knowledge.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        {{ __('admin.button.cancel') }}
                    </a>
                    <button type="submit" id="enterprise-submit-button" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.enterprise_knowledge.submit') }}
                    </button>
                </div>
            </div>

            <aside class="space-y-6">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.flow_title') }}</h2>
                    </div>
                    <div class="space-y-5 px-6 py-5">
                        @foreach ([
                            ['icon' => 'copy-plus', 'title' => __('admin.enterprise_knowledge.flow_collect'), 'desc' => __('admin.enterprise_knowledge.flow_collect_desc')],
                            ['icon' => 'wand-sparkles', 'title' => __('admin.enterprise_knowledge.flow_generate'), 'desc' => __('admin.enterprise_knowledge.flow_generate_desc')],
                            ['icon' => 'file-pen-line', 'title' => __('admin.enterprise_knowledge.flow_edit'), 'desc' => __('admin.enterprise_knowledge.flow_edit_desc')],
                            ['icon' => 'database-zap', 'title' => __('admin.enterprise_knowledge.flow_publish'), 'desc' => __('admin.enterprise_knowledge.flow_publish_desc')],
                        ] as $step)
                            <div class="flex gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-orange-50 text-orange-600">
                                    <i data-lucide="{{ $step['icon'] }}" class="h-5 w-5"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $step['title'] }}</div>
                                    <p class="mt-1 text-xs leading-5 text-gray-500">{{ $step['desc'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </aside>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('enterprise_files');
            const list = document.getElementById('enterprise-file-list');
            const dropzone = document.getElementById('enterprise-file-dropzone');
            const form = document.getElementById('enterprise-knowledge-form');
            const submitButton = document.getElementById('enterprise-submit-button');
            const labels = {
                dropFallback: @json(__('admin.enterprise_knowledge.drop_fallback')),
                processing: @json(__('admin.enterprise_knowledge.processing')),
                submitting: @json(__('admin.enterprise_knowledge.submitting')),
            };

            const escapeHtml = (value) => String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const renderFiles = () => {
                const files = Array.from(input.files || []);
                if (files.length === 0) {
                    list.classList.add('hidden');
                    list.innerHTML = '';
                    return;
                }

                list.classList.remove('hidden');
                list.innerHTML = files.map((file, index) => {
                    const size = (file.size / 1024 / 1024).toFixed(2);
                    return `<div class="flex items-center justify-between gap-3 py-1">
                        <span class="truncate">${index + 1}. ${escapeHtml(file.name)}</span>
                        <span class="shrink-0 text-xs text-gray-400">${size} MB</span>
                    </div>`;
                }).join('');
            };

            input?.addEventListener('change', renderFiles);
            dropzone?.addEventListener('dragover', (event) => {
                event.preventDefault();
                dropzone.classList.add('border-blue-400', 'bg-blue-50');
            });
            dropzone?.addEventListener('dragleave', () => {
                dropzone.classList.remove('border-blue-400', 'bg-blue-50');
            });
            dropzone?.addEventListener('drop', (event) => {
                event.preventDefault();
                dropzone.classList.remove('border-blue-400', 'bg-blue-50');
                try {
                    input.files = event.dataTransfer.files;
                    renderFiles();
                } catch (error) {
                    alert(labels.dropFallback);
                }
            });

            form?.addEventListener('submit', () => {
                if (! submitButton) {
                    return;
                }

                submitButton.disabled = true;
                submitButton.classList.add('opacity-70');
                submitButton.innerHTML = `<i data-lucide="loader-2" class="mr-2 h-4 w-4 animate-spin"></i>${escapeHtml(labels.submitting)}`;
                if (window.lucide) {
                    window.lucide.createIcons();
                }
            });
        });
    </script>
@endsection
