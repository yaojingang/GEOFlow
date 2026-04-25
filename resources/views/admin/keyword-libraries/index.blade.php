@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.keyword_libraries.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
                </div>
            </div>
            <button type="button" onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.keyword_libraries.create') }}
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="folder" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.keyword_libraries.total') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_libraries'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="key" class="h-6 w-6 text-green-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.keyword_libraries.total_keywords') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_keywords'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.common.avg_per_library') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_keywords'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.keyword_libraries.list_title') }}</h3>
            </div>
            @if (empty($libraries))
                <div class="px-6 py-10 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.keyword_libraries.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.keyword_libraries.empty_desc') }}</p>
                    <a href="{{ route('admin.keyword-libraries.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.keyword_libraries.create') }}
                    </a>
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($libraries as $library)
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="hover:text-blue-600">
                                                {{ $library['name'] }}
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ __('admin.keyword_libraries.keyword_count', ['count' => (int) $library['actual_count']]) }}
                                        </span>
                                    </div>
                                    @if ($library['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $library['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>
                                            {{ __('admin.keyword_libraries.created_at', ['value' => $library['created_at'] ? \Illuminate\Support\Carbon::parse($library['created_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        <span>
                                            {{ __('admin.keyword_libraries.updated_at', ['value' => $library['updated_at'] ? \Illuminate\Support\Carbon::parse($library['updated_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button type="button" onclick="showImportModal({{ (int) $library['id'] }}, @js($library['name']))" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.import') }}
                                    </button>
                                    <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.keyword-libraries.delete', ['libraryId' => (int) $library['id']]) }}" onsubmit="return confirm(@js(__('admin.keyword_libraries.confirm_delete', ['name' => $library['name']])));" class="inline-block">
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
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_libraries.modal_create') }}</h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.store') }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_libraries.field_name') }}</label>
                            <input type="text" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_libraries.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_libraries.field_description') }}</label>
                            <textarea name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_libraries.placeholder_description') }}"></textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.button.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    {{ __('admin.keyword_libraries.modal_import') }}
                    <span id="import-library-name" class="text-blue-600"></span>
                </h3>
                <form method="POST" id="import-form">
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
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        function showImportModal(libraryId, libraryName) {
            const importForm = document.getElementById('import-form');
            importForm.action = `{{ route('admin.keyword-libraries.index') }}/${libraryId}/import`;
            document.getElementById('import-library-name').textContent = libraryName;
            document.getElementById('import-modal').classList.remove('hidden');
        }

        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        window.addEventListener('click', function (event) {
            const createModal = document.getElementById('create-modal');
            const importModal = document.getElementById('import-modal');

            if (event.target === createModal) {
                hideCreateModal();
            }

            if (event.target === importModal) {
                hideImportModal();
            }
        });
    </script>
@endpush

