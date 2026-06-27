@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.knowledge-bases.update', ['knowledgeBaseId' => (int) $knowledgeBaseId])
        : route('admin.knowledge-bases.store');
    $focusUpload = ! $isEdit && request()->query('mode') === 'upload';
    $fieldClass = 'h-11 w-full rounded-lg border border-gray-300 px-3 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-orange-500';
    $selectClass = 'admin-knowledge-select h-11 w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-3 pr-11 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-orange-500';
    $textareaClass = 'w-full rounded-lg border border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-orange-500';
    $sourceTypeOptions = [
        'document' => __('admin.knowledge_bases.source_type_document'),
        'website' => __('admin.knowledge_bases.source_type_website'),
        'business' => __('admin.knowledge_bases.source_type_business'),
        'faq' => __('admin.knowledge_bases.source_type_faq'),
        'other' => __('admin.knowledge_bases.source_type_other'),
    ];
    $riskLevelOptions = [
        'low' => __('admin.knowledge_bases.risk_level_low'),
        'medium' => __('admin.knowledge_bases.risk_level_medium'),
        'high' => __('admin.knowledge_bases.risk_level_high'),
    ];
    $reviewStatusOptions = [
        'unreviewed' => __('admin.knowledge_bases.review_status_unreviewed'),
        'reviewed' => __('admin.knowledge_bases.review_status_reviewed'),
    ];
@endphp

@push('styles')
    <style>
        .admin-knowledge-select {
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 20 20' fill='none' xmlns='http://www.w3.org/2000/svg'%3e%3cpath d='M5 7.5L10 12.5L15 7.5' stroke='%23111827' stroke-width='1.75' stroke-linecap='round' stroke-linejoin='round'/%3e%3c/svg%3e");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 1rem 1rem;
        }

        .admin-knowledge-select::-ms-expand {
            display: none;
        }

        .admin-knowledge-evidence-summary {
            list-style: none;
        }

        .admin-knowledge-evidence-summary::-webkit-details-marker {
            display: none;
        }
    </style>
@endpush

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.knowledge_detail.heading') : __('admin.knowledge_bases.modal_create') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $isEdit ? __('admin.knowledge_detail.subtitle') : __('admin.knowledge_bases.import_subtitle') }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <div class="font-semibold">{{ __('admin.knowledge_bases.import_error_title') }}</div>
                <p class="mt-1">{{ __('admin.knowledge_bases.import_error_desc') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ $formAction }}"
            @if (! $isEdit) enctype="multipart/form-data" data-knowledge-import-form data-upload-focus="{{ $focusUpload ? '1' : '0' }}" @endif
            class="space-y-6"
        >
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            @if ($isEdit)
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-6 space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_name') }}</label>
                            <input type="text" name="name" required value="{{ old('name', (string) ($knowledgeForm['name'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.field_name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_description') }}</label>
                            <textarea name="description" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_description') }}">{{ old('description', (string) ($knowledgeForm['description'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                            <select name="file_type" class="{{ $selectClass }}">
                                <option value="markdown" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                                <option value="word" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                                <option value="text" @selected(old('file_type', (string) ($knowledgeForm['file_type'] ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                            </select>
                        </div>
                        <div class="border-t border-gray-100 pt-5">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.knowledge_bases.evidence_metadata_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_bases.evidence_metadata_desc') }}</p>
                            <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_name') }}</label>
                                    <input type="text" name="source_name" value="{{ old('source_name', (string) ($knowledgeForm['source_name'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_source_name') }}">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_url') }}</label>
                                    <input type="text" name="source_url" value="{{ old('source_url', (string) ($knowledgeForm['source_url'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_source_url') }}">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_type') }}</label>
                                    <select name="source_type" class="{{ $selectClass }}">
                                        @foreach ($sourceTypeOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(old('source_type', (string) ($knowledgeForm['source_type'] ?? 'document')) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_business_line') }}</label>
                                    <input type="text" name="business_line" value="{{ old('business_line', (string) ($knowledgeForm['business_line'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_business_line') }}">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_effective_date') }}</label>
                                    <input type="date" name="effective_date" value="{{ old('effective_date', (string) ($knowledgeForm['effective_date'] ?? '')) }}" class="{{ $fieldClass }}">
                                </div>
                                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_risk_level') }}</label>
                                        <select name="risk_level" class="{{ $selectClass }}">
                                            @foreach ($riskLevelOptions as $value => $label)
                                                <option value="{{ $value }}" @selected(old('risk_level', (string) ($knowledgeForm['risk_level'] ?? 'medium')) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_review_status') }}</label>
                                        <select name="review_status" class="{{ $selectClass }}">
                                            @foreach ($reviewStatusOptions as $value => $label)
                                                <option value="{{ $value }}" @selected(old('review_status', (string) ($knowledgeForm['review_status'] ?? 'unreviewed')) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_content') }}</label>
                            <textarea name="content" rows="18" required class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_content') }}">{{ old('content', (string) ($knowledgeForm['content'] ?? '')) }}</textarea>
                        </div>

                        <div class="text-xs text-gray-500">
                            {{ __('admin.knowledge_detail.chunk_count') }}: {{ (int) ($chunkCount ?? 0) }}
                        </div>
                    </div>
                </div>
            @else
                <input type="hidden" name="file_type" value="markdown">
                <div class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" data-import-client-error>
                    <div class="font-semibold">{{ __('admin.knowledge_bases.import_error_title') }}</div>
                    <p class="mt-1" data-import-client-error-message>{{ __('admin.knowledge_bases.import_error_desc') }}</p>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="space-y-6">
                        <div class="bg-white shadow rounded-lg">
                            <div class="border-b border-gray-200 px-6 py-5">
                                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_bases.import_basic_title') }}</h2>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.import_basic_desc') }}</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 px-6 py-6 lg:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_name_import') }}</label>
                                    <input type="text" name="name" value="{{ old('name', (string) ($knowledgeForm['name'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_name_auto') }}" data-knowledge-name-input>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.knowledge_bases.name_auto_hint') }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_description') }}</label>
                                    <input type="text" name="description" value="{{ old('description', (string) ($knowledgeForm['description'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_description') }}" data-knowledge-description-input>
                                </div>
                                <details class="group rounded-lg border border-gray-200 bg-gray-50/60 lg:col-span-2">
                                    <summary class="admin-knowledge-evidence-summary flex cursor-pointer items-center justify-between gap-4 px-4 py-3">
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.knowledge_bases.evidence_metadata_title') }}</h3>
                                            <p class="mt-0.5 text-sm text-gray-500">{{ __('admin.knowledge_bases.evidence_metadata_collapsed_desc') }}</p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 transition group-open:text-orange-700 group-open:ring-orange-200">
                                            <span class="group-open:hidden">{{ __('admin.knowledge_bases.evidence_metadata_expand') }}</span>
                                            <span class="hidden group-open:inline">{{ __('admin.knowledge_bases.evidence_metadata_collapse') }}</span>
                                            <i data-lucide="chevron-down" class="h-4 w-4 transition-transform group-open:rotate-180"></i>
                                        </span>
                                    </summary>
                                    <div class="grid grid-cols-1 gap-5 border-t border-gray-200 bg-white px-4 py-4 lg:grid-cols-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_name') }}</label>
                                            <input type="text" name="source_name" value="{{ old('source_name', (string) ($knowledgeForm['source_name'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_source_name') }}">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_url') }}</label>
                                            <input type="text" name="source_url" value="{{ old('source_url', (string) ($knowledgeForm['source_url'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_source_url') }}">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_source_type') }}</label>
                                            <select name="source_type" class="{{ $selectClass }}">
                                                @foreach ($sourceTypeOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(old('source_type', (string) ($knowledgeForm['source_type'] ?? 'document')) === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_business_line') }}</label>
                                            <input type="text" name="business_line" value="{{ old('business_line', (string) ($knowledgeForm['business_line'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_business_line') }}">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_effective_date') }}</label>
                                            <input type="date" name="effective_date" value="{{ old('effective_date', (string) ($knowledgeForm['effective_date'] ?? '')) }}" class="{{ $fieldClass }}">
                                        </div>
                                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_risk_level') }}</label>
                                                <select name="risk_level" class="{{ $selectClass }}">
                                                    @foreach ($riskLevelOptions as $value => $label)
                                                        <option value="{{ $value }}" @selected(old('risk_level', (string) ($knowledgeForm['risk_level'] ?? 'medium')) === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_review_status') }}</label>
                                                <select name="review_status" class="{{ $selectClass }}">
                                                    @foreach ($reviewStatusOptions as $value => $label)
                                                        <option value="{{ $value }}" @selected(old('review_status', (string) ($knowledgeForm['review_status'] ?? 'unreviewed')) === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <div class="bg-white shadow rounded-lg">
                            <div class="border-b border-gray-200 px-6 py-5">
                                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_bases.import_sources_title') }}</h2>
                                <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.import_sources_desc') }}</p>
                            </div>
                            <div class="space-y-6 px-6 py-6">
                                <div>
                                    <div class="mb-3">
                                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.knowledge_bases.source_files_title') }}</div>
                                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_bases.source_files_desc') }}</p>
                                    </div>
                                    <div class="rounded-xl border-2 border-dashed border-orange-200 bg-orange-50/30 px-6 py-8 text-center transition hover:border-orange-300 hover:bg-orange-50" data-knowledge-upload-dropzone>
                                        <input type="file" id="knowledge-files-input" name="knowledge_files[]" accept=".txt,.md,.docx" multiple class="sr-only" data-knowledge-files-input>
                                        <label for="knowledge-files-input" class="cursor-pointer">
                                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-white text-orange-600 shadow-sm ring-1 ring-orange-100">
                                                <i data-lucide="upload-cloud" class="h-6 w-6"></i>
                                            </span>
                                            <span class="mt-4 block text-sm font-semibold text-gray-900">{{ __('admin.knowledge_bases.dropzone_title') }}</span>
                                            <span class="mt-1 block text-sm text-gray-500">{{ __('admin.knowledge_bases.dropzone_desc') }}</span>
                                            <span class="mt-3 inline-flex rounded-full bg-white px-3 py-1 text-xs font-medium text-orange-700 ring-1 ring-orange-200">{{ __('admin.knowledge_bases.upload_limits') }}</span>
                                        </label>
                                    </div>
                                    <div class="mt-4 hidden overflow-hidden rounded-lg border border-gray-200" data-knowledge-file-list></div>
                                </div>

                                <div>
                                    <div class="mb-3 flex items-center justify-between gap-4">
                                        <div>
                                            <label for="knowledge-content" class="block text-sm font-semibold text-gray-900">{{ __('admin.knowledge_bases.source_text_title') }}</label>
                                            <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_bases.source_text_desc') }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-orange-50 px-3 py-1 text-xs font-medium text-orange-700" data-content-counter>0</span>
                                    </div>
                                    <textarea id="knowledge-content" name="content" rows="12" class="{{ $textareaClass }} min-h-[260px]" placeholder="{{ __('admin.knowledge_bases.placeholder_content_import') }}">{{ old('content', (string) ($knowledgeForm['content'] ?? '')) }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <aside class="space-y-6">
                        <div class="bg-white shadow rounded-lg">
                            <div class="border-b border-gray-200 px-5 py-4">
                                <h3 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_bases.import_pipeline_title') }}</h3>
                            </div>
                            <div class="px-5 py-5">
                                <div class="space-y-4">
                                    @foreach ([
                                        ['icon' => 'copy', 'title' => __('admin.knowledge_bases.pipeline_collect'), 'desc' => __('admin.knowledge_bases.pipeline_collect_desc')],
                                        ['icon' => 'sparkles', 'title' => __('admin.knowledge_bases.pipeline_clean'), 'desc' => __('admin.knowledge_bases.pipeline_clean_desc')],
                                        ['icon' => 'scissors', 'title' => __('admin.knowledge_bases.pipeline_merge'), 'desc' => __('admin.knowledge_bases.pipeline_merge_desc')],
                                        ['icon' => 'network', 'title' => __('admin.knowledge_bases.pipeline_vector'), 'desc' => __('admin.knowledge_bases.pipeline_vector_desc')],
                                    ] as $step)
                                        <div class="flex gap-3">
                                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-600">
                                                <i data-lucide="{{ $step['icon'] }}" class="h-4 w-4"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">{{ $step['title'] }}</div>
                                                <p class="mt-0.5 text-xs leading-5 text-gray-500">{{ $step['desc'] }}</p>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="bg-white shadow rounded-lg">
                            <div class="border-b border-gray-200 px-5 py-4">
                                <h3 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_bases.import_rules_title') }}</h3>
                            </div>
                            <div class="space-y-3 px-5 py-5 text-sm leading-6 text-gray-600">
                                <p>{{ __('admin.knowledge_bases.import_rule_formats') }}</p>
                                <p>{{ __('admin.knowledge_bases.import_rule_merge') }}</p>
                                <p>{{ __('admin.knowledge_bases.import_rule_server') }}</p>
                            </div>
                        </div>
                    </aside>
                </div>

                <div class="hidden rounded-lg border border-blue-200 bg-blue-50 px-4 py-3" data-import-progress>
                    <div class="flex items-center justify-between text-sm font-medium text-blue-800">
                        <span data-import-progress-label>{{ __('admin.knowledge_bases.import_progress_uploading') }}</span>
                        <span data-import-progress-value>0%</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100">
                        <div class="h-full rounded-full bg-blue-600 transition-all duration-500" style="width: 8%;" data-import-progress-bar></div>
                    </div>
                </div>
            @endif

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    {{ __('admin.button.cancel') }}
                </a>
                @if ($isEdit)
                    <button type="submit" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 disabled:cursor-wait disabled:opacity-75" data-import-submit>
                        <i data-lucide="save" class="mr-2 h-4 w-4" data-import-submit-icon></i>
                        <span data-import-submit-label>{{ __('admin.knowledge_detail.save_changes') }}</span>
                    </button>
                @else
                    <button type="submit" name="import_action" value="save" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:cursor-wait disabled:opacity-75" data-import-submit data-import-action="save">
                        <i data-lucide="send" class="mr-2 h-4 w-4" data-import-submit-icon></i>
                        <span data-import-submit-label>{{ __('admin.knowledge_bases.import_submit_only') }}</span>
                    </button>
                    <button type="submit" name="import_action" value="save_and_chunk" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 disabled:cursor-wait disabled:opacity-75" data-import-submit data-import-action="save_and_chunk">
                        <i data-lucide="database-zap" class="mr-2 h-4 w-4" data-import-submit-icon></i>
                        <span data-import-submit-label>{{ __('admin.knowledge_bases.import_submit') }}</span>
                    </button>
                @endif
            </div>
        </form>
    </div>
@endsection

@if (! $isEdit)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.querySelector('[data-knowledge-import-form]');
                if (!form) {
                    return;
                }

                const fileInput = form.querySelector('[data-knowledge-files-input]');
                const dropzone = form.querySelector('[data-knowledge-upload-dropzone]');
                const fileList = form.querySelector('[data-knowledge-file-list]');
                const contentInput = document.getElementById('knowledge-content');
                const contentCounter = form.querySelector('[data-content-counter]');
                const submitButtons = Array.from(form.querySelectorAll('[data-import-submit]'));
                const progress = document.querySelector('[data-import-progress]');
                const progressLabel = document.querySelector('[data-import-progress-label]');
                const progressValue = document.querySelector('[data-import-progress-value]');
                const progressBar = document.querySelector('[data-import-progress-bar]');
                const clientError = form.querySelector('[data-import-client-error]');
                const clientErrorMessage = form.querySelector('[data-import-client-error-message]');
                const allowedExtensions = ['txt', 'md', 'docx'];
                const maxFiles = 10;
                const maxFileBytes = 50 * 1024 * 1024;
                let importProgressTimer = null;

                const escapeHtml = function (value) {
                    return String(value || '').replace(/[&<>"']/g, function (char) {
                        return {
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        }[char];
                    });
                };

                const formatBytes = function (bytes) {
                    if (bytes >= 1024 * 1024) {
                        return (bytes / 1024 / 1024).toFixed(1).replace(/\.0$/, '') + ' MB';
                    }

                    return Math.max(1, Math.ceil(bytes / 1024)) + ' KB';
                };

                const extensionOf = function (fileName) {
                    const parts = String(fileName || '').split('.');
                    return parts.length > 1 ? parts.pop().toLowerCase() : '';
                };

                const updateContentCounter = function () {
                    if (!contentInput || !contentCounter) {
                        return;
                    }

                    const length = Array.from(contentInput.value || '').length;
                    contentCounter.textContent = @json(__('admin.knowledge_bases.content_counter', ['count' => '__COUNT__'])).replace('__COUNT__', String(length));
                };

                const renderFileList = function () {
                    if (!fileInput || !fileList) {
                        return;
                    }

                    const files = Array.from(fileInput.files || []);
                    if (files.length === 0) {
                        fileList.classList.add('hidden');
                        fileList.innerHTML = '';
                        return;
                    }

                    fileList.classList.remove('hidden');
                    fileList.innerHTML = files.map(function (file, index) {
                        const extension = extensionOf(file.name);
                        const invalidType = !allowedExtensions.includes(extension);
                        const tooLarge = file.size > maxFileBytes;
                        const overLimit = files.length > maxFiles;
                        const invalid = invalidType || tooLarge || overLimit;
                        let status = @json(__('admin.knowledge_bases.file_status_ready'));
                        if (overLimit) {
                            status = @json(__('admin.knowledge_bases.file_status_over_limit'));
                        } else if (tooLarge) {
                            status = @json(__('admin.knowledge_bases.file_status_too_large'));
                        } else if (invalidType) {
                            status = @json(__('admin.knowledge_bases.file_status_invalid'));
                        }
                        const statusClass = invalid ? 'text-red-700 bg-red-50 ring-red-100' : 'text-emerald-700 bg-emerald-50 ring-emerald-100';

                        return [
                            '<div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-b-0">',
                            '<div class="min-w-0">',
                            '<div class="truncate text-sm font-medium text-gray-900">' + (index + 1) + '. ' + escapeHtml(file.name) + '</div>',
                            '<div class="mt-0.5 text-xs text-gray-500">' + escapeHtml(extension.toUpperCase()) + ' · ' + formatBytes(file.size) + '</div>',
                            '</div>',
                            '<span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ' + statusClass + '">' + status + '</span>',
                            '</div>'
                        ].join('');
                    }).join('');
                };

                const selectedFilesAreValid = function () {
                    const files = Array.from(fileInput ? fileInput.files || [] : []);
                    if (files.length > maxFiles) {
                        return false;
                    }

                    return files.every(function (file) {
                        return allowedExtensions.includes(extensionOf(file.name)) && file.size <= maxFileBytes;
                    });
                };

                const showImportError = function (message) {
                    if (clientErrorMessage) {
                        clientErrorMessage.textContent = message;
                    }
                    if (clientError) {
                        clientError.classList.remove('hidden');
                        clientError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                };

                const hideImportError = function () {
                    if (clientError) {
                        clientError.classList.add('hidden');
                    }
                };

                const startProgress = function (submitter) {
                    let percent = 12;
                    const shouldGenerateChunks = !submitter || submitter.dataset.importAction !== 'save';
                    const labels = [
                        @json(__('admin.knowledge_bases.import_progress_uploading')),
                        @json(__('admin.knowledge_bases.import_progress_cleaning')),
                        @json(__('admin.knowledge_bases.import_progress_merging')),
                    ];
                    if (shouldGenerateChunks) {
                        labels.push(@json(__('admin.knowledge_bases.import_progress_chunking')));
                    } else {
                        labels.push(@json(__('admin.knowledge_bases.import_progress_saving')));
                    }

                    submitButtons.forEach(function (button) {
                        button.disabled = true;
                    });
                    const submitLabel = submitter ? submitter.querySelector('[data-import-submit-label]') : null;
                    const submitIcon = submitter ? submitter.querySelector('[data-import-submit-icon]') : null;
                    if (submitLabel) {
                        submitLabel.textContent = shouldGenerateChunks
                            ? @json(__('admin.knowledge_bases.import_progress_button'))
                            : @json(__('admin.knowledge_bases.import_progress_save_button'));
                    }
                    if (submitIcon) {
                        submitIcon.classList.add('animate-spin');
                    }
                    if (progress) {
                        progress.classList.remove('hidden');
                    }

                    if (importProgressTimer) {
                        window.clearInterval(importProgressTimer);
                    }
                    importProgressTimer = window.setInterval(function () {
                        percent = Math.min(92, percent + (percent < 48 ? 14 : 7));
                        if (progressValue) {
                            progressValue.textContent = percent + '%';
                        }
                        if (progressBar) {
                            progressBar.style.width = percent + '%';
                        }
                        if (progressLabel) {
                            progressLabel.textContent = labels[Math.min(labels.length - 1, Math.floor(percent / 28))];
                        }
                        if (percent >= 92 && importProgressTimer) {
                            window.clearInterval(importProgressTimer);
                            importProgressTimer = null;
                        }
                    }, 480);
                };

                if (contentInput) {
                    contentInput.addEventListener('input', function () {
                        hideImportError();
                        updateContentCounter();
                    });
                    updateContentCounter();
                }

                if (fileInput) {
                    fileInput.addEventListener('change', function () {
                        hideImportError();
                        renderFileList();
                    });
                }

                if (dropzone && fileInput) {
                    ['dragenter', 'dragover'].forEach(function (eventName) {
                        dropzone.addEventListener(eventName, function (event) {
                            event.preventDefault();
                            dropzone.classList.add('border-orange-400', 'bg-orange-50');
                        });
                    });
                    ['dragleave', 'drop'].forEach(function (eventName) {
                        dropzone.addEventListener(eventName, function (event) {
                            event.preventDefault();
                            dropzone.classList.remove('border-orange-400', 'bg-orange-50');
                        });
                    });
                    dropzone.addEventListener('drop', function (event) {
                        if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                            try {
                                fileInput.files = event.dataTransfer.files;
                                hideImportError();
                                renderFileList();
                            } catch (error) {
                                showImportError(@json(__('admin.knowledge_bases.import_file_drop_fallback')));
                            }
                        }
                    });
                }

                if (form.dataset.uploadFocus === '1' && dropzone) {
                    dropzone.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                form.addEventListener('submit', function (event) {
                    const hasContent = contentInput && contentInput.value.trim() !== '';
                    const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;

                    if (!hasContent && !hasFiles) {
                        event.preventDefault();
                        showImportError(@json(__('admin.knowledge_bases.import_empty_alert')));
                        return;
                    }

                    if (!selectedFilesAreValid()) {
                        event.preventDefault();
                        renderFileList();
                        showImportError(@json(__('admin.knowledge_bases.import_file_invalid_alert')));
                        return;
                    }

                    hideImportError();
                    startProgress(event.submitter || form.querySelector('[data-import-action="save_and_chunk"]'));
                });
            });
        </script>
    @endpush
@endif
