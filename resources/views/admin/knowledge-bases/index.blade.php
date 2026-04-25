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
            <button type="button" onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                {{ __('admin.knowledge_bases.upload') }}
            </button>
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
                        <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.create_first') }}
                        </button>
                        <button type="button" onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.upload_doc') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($knowledgeBases as $item)
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
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
                                    </div>
                                    @if ($item['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $item['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
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

                                <div class="flex items-center space-x-2">
                                    @if (! $hasDefaultEmbeddingModel)
                                        <button type="button" onclick="showEmbeddingConfigModal()" class="inline-flex items-center px-3 py-1.5 border border-amber-200 text-xs font-medium rounded text-amber-800 bg-amber-50 hover:bg-amber-100">
                                            <i data-lucide="cpu" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.knowledge_bases.vector_notice_configure_link') }}
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

    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.knowledge_bases.modal_create') }}</h3>
                <form method="POST" action="{{ route('admin.knowledge-bases.store') }}">
                    @csrf
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_name') }}</label>
                                <input type="text" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm" placeholder="{{ __('admin.knowledge_bases.placeholder_name') }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                                <select name="file_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm">
                                    <option value="markdown">{{ __('admin.status.markdown') }}</option>
                                    <option value="text">{{ __('admin.status.text') }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_description') }}</label>
                            <textarea name="description" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm" placeholder="{{ __('admin.knowledge_bases.placeholder_description') }}"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_content') }}</label>
                            <textarea name="content" rows="15" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm font-mono" placeholder="{{ __('admin.knowledge_bases.placeholder_content') }}"></textarea>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700">
                            {{ __('admin.knowledge_bases.create_first') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="upload-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.knowledge_bases.modal_upload') }}</h3>
                <form method="POST" action="{{ route('admin.knowledge-bases.upload') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.common.name') }}</label>
                            <input type="text" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm" placeholder="{{ __('admin.knowledge_bases.placeholder_name_optional') }}">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_description') }}</label>
                            <textarea name="description" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm" placeholder="{{ __('admin.knowledge_bases.placeholder_upload_description') }}"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_bases.field_file') }}</label>
                            <div class="mt-1">
                                <input type="file" id="knowledge-file-input" name="knowledge_file" required accept=".txt,.md,.docx" class="sr-only">
                                <label for="knowledge-file-input" class="flex items-center gap-3 rounded-md border border-gray-300 px-4 py-3 text-sm text-gray-600 cursor-pointer hover:border-orange-300 hover:bg-orange-50/40">
                                    <span class="inline-flex items-center rounded-full bg-orange-50 px-4 py-2 font-semibold text-orange-700">
                                        {{ __('admin.knowledge_bases.file_choose') }}
                                    </span>
                                    <span id="knowledge-file-name" class="min-w-0 truncate text-gray-500">
                                        {{ __('admin.knowledge_bases.file_none_selected') }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="text-sm text-gray-500">
                            <p class="mb-2">{{ __('admin.knowledge_bases.format_help') }}</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>{{ __('admin.knowledge_bases.format_txt') }}</li>
                                <li>{{ __('admin.knowledge_bases.format_md') }}</li>
                                <li>{{ __('admin.knowledge_bases.format_docx') }}</li>
                                <li>{{ __('admin.knowledge_bases.format_doc') }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideUploadModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i>
                            {{ __('admin.knowledge_bases.upload_doc') }}
                        </button>
                    </div>
                </form>
            </div>
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
@endsection

@push('scripts')
    <script>
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        function showUploadModal() {
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
        }

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

        document.addEventListener('DOMContentLoaded', function () {
            const fileInput = document.getElementById('knowledge-file-input');
            const fileName = document.getElementById('knowledge-file-name');
            if (fileInput && fileName) {
                fileInput.addEventListener('change', function () {
                    fileName.textContent = this.files && this.files.length > 0
                        ? this.files[0].name
                        : @json(__('admin.knowledge_bases.file_none_selected'));
                });
            }
        });

        window.addEventListener('click', function (event) {
            const createModal = document.getElementById('create-modal');
            const uploadModal = document.getElementById('upload-modal');
            const embeddingConfigModal = document.getElementById('embedding-config-modal');

            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === uploadModal) {
                hideUploadModal();
            }
            if (event.target === embeddingConfigModal || (embeddingConfigModal && event.target === embeddingConfigModal.firstElementChild)) {
                hideEmbeddingConfigModal();
            }
        });
    </script>
@endpush

