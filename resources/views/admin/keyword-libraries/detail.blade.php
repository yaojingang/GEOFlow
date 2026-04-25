@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                        <p class="mt-1 text-sm text-gray-600">{{ $library->description !== '' ? $library->description : __('admin.keyword_detail.no_description') }}</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button type="button" onclick="showEditModal()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.keyword_detail.edit_info') }}
                    </button>
                    <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.keyword_detail.add_keyword') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.total_keywords') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $keywords->total() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.usage_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.created_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.updated_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->updated_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <form method="GET" class="flex items-center space-x-4">
                        <div class="flex-1">
                            <input type="text" name="search" value="{{ $search }}"
                                placeholder="{{ __('admin.keyword_detail.search_placeholder') }}"
                                class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.search') }}
                        </button>
                        <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.clear') }}
                        </a>
                    </form>
                    <div class="flex space-x-2">
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.keyword_detail.batch_actions') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.keyword_detail.list_title') }}
                        <span class="text-sm text-gray-500">{{ __('admin.keyword_detail.list_total', ['count' => $keywords->total()]) }}</span>
                    </h3>
                </div>
            </div>

            @if ($keywords->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="search" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.keyword_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.keyword_detail.empty_search') : __('admin.keyword_detail.empty_desc') }}</p>
                    @if ($search === '')
                        <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.keyword_detail.add_keyword') }}
                        </button>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" id="batch-form">
                        @csrf
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600" id="selected-keyword-count">{{ __('admin.keyword_detail.selected_count', ['count' => 0]) }}</span>
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                {{ __('admin.keyword_detail.delete_selected') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        @foreach ($keywords as $keyword)
                            <div class="group flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <input type="checkbox" form="batch-form" name="keyword_ids[]" value="{{ (int) $keyword->id }}" class="keyword-checkbox hidden rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="text-sm text-gray-900 break-all">{{ $keyword->keyword }}</span>
                                </div>
                                <button type="button" onclick="deleteKeyword({{ (int) $keyword->id }}, @js($keyword->keyword))" class="text-red-600 hover:text-red-800 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($keywords->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.keyword_detail.pagination', ['start' => $keywords->firstItem(), 'end' => $keywords->lastItem(), 'total' => $keywords->total()]) }}
                            </div>
                            <div>
                                {{ $keywords->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" id="single-delete-form" class="hidden">
        @csrf
        <input type="hidden" name="keyword_ids[]" id="single-delete-keyword-id" value="">
    </form>

    <div id="add-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_detail.modal_add') }}</h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_keyword') }}</label>
                            <input type="text" name="keyword" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_detail.placeholder_keyword') }}">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.button.add') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_detail.modal_edit') }}</h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.detail.update', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    @method('PUT')
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_name') }}</label>
                            <input type="text" name="name" required value="{{ old('name', (string) $library->name) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_description') }}</label>
                            <textarea name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">{{ old('description', (string) ($library->description ?? '')) }}</textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-between space-x-3">
                        <button type="button" onclick="showImportModal()" class="px-4 py-2 border border-blue-200 rounded-md text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100">
                            {{ __('admin.button.import') }}
                        </button>
                        <div class="space-x-3">
                            <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                {{ __('admin.button.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_libraries.modal_import') }} <span class="text-blue-600">{{ $library->name }}</span></h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.import', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_libraries.field_keywords') }}</label>
                            <textarea name="keywords_text" rows="10" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_libraries.placeholder_keywords') }}"></textarea>
                        </div>
                        <div class="text-sm text-gray-500">
                            <p class="mb-2">{{ __('admin.keyword_libraries.format_title') }}</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>{{ __('admin.keyword_libraries.format_line') }}</li>
                                <li>{{ __('admin.keyword_libraries.format_comma') }}</li>
                                <li>{{ __('admin.keyword_libraries.format_dedupe') }}</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideImportModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.keyword_libraries.import_button') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function showAddModal() {
            document.getElementById('add-modal').classList.remove('hidden');
        }

        function hideAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        function showEditModal() {
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function showImportModal() {
            document.getElementById('import-modal').classList.remove('hidden');
        }

        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.keyword-checkbox');
            const isHidden = batchActions.classList.contains('hidden');

            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach((checkbox) => checkbox.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach((checkbox) => {
                    checkbox.classList.add('hidden');
                    checkbox.checked = false;
                });
                updateSelectedCount();
            }
        }

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
            const text = @json(__('admin.keyword_detail.selected_count', ['count' => '{count}'])).replace('{count}', String(selected));
            const counter = document.getElementById('selected-keyword-count');
            if (counter) {
                counter.textContent = text;
            }
        }

        function deleteKeyword(keywordId, keywordName) {
            const confirmed = confirm(@json(__('admin.keyword_detail.confirm_delete_keyword', ['name' => '{name}'])).replace('{name}', keywordName));
            if (!confirmed) {
                return;
            }

            document.getElementById('single-delete-keyword-id').value = String(keywordId);
            document.getElementById('single-delete-form').submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.keyword-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', updateSelectedCount);
            });

            const batchForm = document.getElementById('batch-form');
            if (batchForm) {
                batchForm.addEventListener('submit', function (event) {
                    const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
                    if (selected <= 0) {
                        event.preventDefault();
                        alert(@json(__('admin.keyword_detail.error.select_required')));
                        return;
                    }

                    const confirmed = confirm(@json(__('admin.keyword_detail.confirm_delete_selected', ['count' => '{count}'])).replace('{count}', String(selected)));
                    if (!confirmed) {
                        event.preventDefault();
                    }
                });
            }
        });

        window.onclick = function (event) {
            const addModal = document.getElementById('add-modal');
            const editModal = document.getElementById('edit-modal');
            const importModal = document.getElementById('import-modal');

            if (event.target === addModal) {
                hideAddModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
            if (event.target === importModal) {
                hideImportModal();
            }
        };
    </script>
@endpush
