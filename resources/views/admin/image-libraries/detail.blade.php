@extends('admin.layouts.app')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Image> $images */
    $formatSize = static function (int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    };
    $urlLabel = __('admin.image_detail.url_label');
    if ($urlLabel === 'admin.image_detail.url_label') {
        $urlLabel = 'URL';
    }
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('admin.image-libraries.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                        <p class="mt-1 text-sm text-gray-600">{{ $library->description !== '' ? $library->description : __('admin.common.none_desc') }}</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button type="button" onclick="showEditModal()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.button.edit') }}
                    </button>
                    <button type="button" onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.upload') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="image" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.image_detail.total_images') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) $totalImages }}</dd>
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
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.usage') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.created_at') }}</dt>
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
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.updated_at') }}</dt>
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
                                placeholder="{{ __('admin.image_detail.search_placeholder') }}"
                                class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.search') }}
                        </button>
                        <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.clear') }}
                        </a>
                    </form>
                    <div class="flex space-x-2">
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.bulk_actions') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.image_detail.list_title') }}
                        <span class="text-sm text-gray-500">({{ __('admin.image_detail.total_images_count', ['count' => (int) $totalImages]) }})</span>
                    </h3>
                </div>
            </div>

            @if ($images->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="image" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.image_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.image_detail.empty_search') : __('admin.image_detail.empty_desc') }}</p>
                    @if ($search === '')
                        <button type="button" onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.upload') }}
                        </button>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ route('admin.image-libraries.images.delete', ['libraryId' => (int) $library->id]) }}" id="batch-form">
                        @csrf
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600" id="selected-count-wrap">{{ __('admin.image_detail.selected_count', ['count' => 0]) }}</span>
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                {{ __('admin.image_detail.delete_selected') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        @foreach ($images as $image)
                            @php
                                $imageUrl = \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($image->file_path ?? ''));
                            @endphp
                            <div class="image-item relative overflow-hidden rounded-lg border-2 border-transparent transition-all hover:border-purple-500 hover:scale-[1.02]" data-image-id="{{ (int) $image->id }}">
                                <input type="checkbox" form="batch-form" name="image_ids[]" value="{{ (int) $image->id }}" class="image-checkbox hidden absolute top-2 left-2 rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-300 focus:ring focus:ring-purple-200 focus:ring-opacity-50 z-10">
                                <img
                                    src="{{ $imageUrl }}"
                                    alt="{{ (string) ($image->original_name ?? '') }}"
                                    class="w-full aspect-square object-cover"
                                    onclick="showImageModal(@js($imageUrl), @js((string) ($image->original_name ?? '')), '{{ (int) ($image->width ?? 0) }}x{{ (int) ($image->height ?? 0) }}', @js($formatSize((int) ($image->file_size ?? 0))), @js($imageUrl))"
                                >
                                <div class="image-overlay absolute inset-0 bg-black/70 text-white flex flex-col justify-center items-center opacity-0 transition-opacity">
                                    <p class="text-xs text-center mb-2 px-2 break-all">{{ (string) ($image->original_name ?? '') }}</p>
                                    <p class="text-xs text-gray-300">{{ (int) ($image->width ?? 0) }}x{{ (int) ($image->height ?? 0) }}</p>
                                    <p class="text-xs text-gray-300">{{ $formatSize((int) ($image->file_size ?? 0)) }}</p>
                                </div>
                                <div class="border-t border-gray-100 bg-white p-2">
                                    <div class="text-[11px] font-medium text-gray-500">{{ $urlLabel }}</div>
                                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 block truncate text-xs text-blue-600 hover:text-blue-800" title="{{ $imageUrl }}">
                                        {{ $imageUrl }}
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($images->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.image_detail.pagination_summary', ['from' => $images->firstItem(), 'to' => $images->lastItem(), 'total' => $images->total()]) }}
                            </div>
                            <div>
                                {{ $images->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div id="upload-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.image_detail.modal_upload', ['name' => (string) $library->name]) }}</h3>
                <form method="POST" action="{{ route('admin.image-libraries.images.upload', ['libraryId' => (int) $library->id]) }}" enctype="multipart/form-data" id="upload-form">
                    @csrf
                    <div class="space-y-4">
                        <div class="upload-area cursor-pointer border-2 border-dashed border-gray-300 rounded-lg p-8 text-center transition-all" id="upload-area" role="button" tabindex="0" aria-controls="images" aria-label="{{ __('admin.image_detail.upload_hint') }}">
                            <input type="file" name="images[]" id="images" multiple accept="image/*" class="hidden">
                            <div class="upload-content">
                                <i data-lucide="upload-cloud" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                                <p class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.image_detail.upload_hint') }}</p>
                                <p class="text-sm text-gray-500 mb-4">{{ __('admin.image_detail.upload_formats') }}</p>
                                <button type="button" id="trigger-image-picker" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                    <i data-lucide="folder-open" class="w-4 h-4 mr-2"></i>
                                    {{ __('admin.image_detail.select_images') }}
                                </button>
                            </div>
                        </div>

                        <div id="file-list" class="hidden">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">{{ __('admin.image_detail.selected_files') }}</h4>
                            <div id="file-items" class="space-y-2"></div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideUploadModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" id="upload-btn" disabled class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 disabled:cursor-not-allowed">
                            <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i>
                            {{ __('admin.button.upload') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.image_detail.modal_edit') }}</h3>
                <form method="POST" action="{{ route('admin.image-libraries.detail.update', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    @method('PUT')
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.image_libraries.field_name') }}</label>
                            <input type="text" name="name" required value="{{ old('name', (string) $library->name) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.common.description') }}</label>
                            <textarea name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">{{ old('description', (string) ($library->description ?? '')) }}</textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700">
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="image-modal" class="hidden fixed inset-0 bg-black bg-opacity-75 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 w-4/5 max-w-4xl">
            <div class="bg-white rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 id="image-title" class="text-lg font-medium text-gray-900"></h3>
                    <button type="button" onclick="hideImageModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
                <div class="p-6 text-center">
                    <img id="image-preview" src="" alt="" class="max-w-full max-h-96 mx-auto rounded">
                    <div id="image-info" class="mt-4 text-sm text-gray-600"></div>
                    <div class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-left">
                        <div class="text-xs font-medium text-gray-500">{{ $urlLabel }}</div>
                        <a id="image-url" href="#" target="_blank" rel="noopener noreferrer" class="mt-1 block break-all text-sm text-blue-600 hover:text-blue-800"></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function showUploadModal() {
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
            document.getElementById('upload-form').reset();
            document.getElementById('file-list').classList.add('hidden');
            document.getElementById('upload-btn').disabled = true;
            document.getElementById('file-items').innerHTML = '';
        }

        function showEditModal() {
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function showImageModal(path, name, dimensions, size, url) {
            document.getElementById('image-title').textContent = name;
            document.getElementById('image-preview').src = path;
            document.getElementById('image-preview').alt = name;
            document.getElementById('image-info').textContent = @json(__('admin.image_detail.dimensions_label')) + ': ' + dimensions + ' | ' + @json(__('admin.image_detail.size_label')) + ': ' + size;
            const imageUrl = document.getElementById('image-url');
            if (imageUrl) {
                imageUrl.href = url;
                imageUrl.textContent = url;
            }
            document.getElementById('image-modal').classList.remove('hidden');
        }

        function hideImageModal() {
            document.getElementById('image-modal').classList.add('hidden');
        }

        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.image-checkbox');
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
                document.querySelectorAll('.image-item').forEach((item) => {
                    item.classList.remove('selected');
                });
                updateSelectedCount();
            }
        }

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.image-checkbox:checked').length;
            const text = @json(__('admin.image_detail.selected_count', ['count' => '{count}'])).replace('{count}', String(selected));
            const countWrap = document.getElementById('selected-count-wrap');
            if (countWrap) {
                countWrap.textContent = text;
            }
        }

        document.querySelectorAll('.image-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('change', function () {
                const imageItem = this.closest('.image-item');
                if (this.checked) {
                    imageItem?.classList.add('selected');
                } else {
                    imageItem?.classList.remove('selected');
                }
                updateSelectedCount();
            });
        });

        const batchForm = document.getElementById('batch-form');
        if (batchForm) {
            batchForm.addEventListener('submit', function (event) {
                const selected = document.querySelectorAll('.image-checkbox:checked').length;
                if (selected === 0) {
                    event.preventDefault();
                    alert(@json(__('admin.image_detail.error.select_delete')));
                    return;
                }
                const confirmed = confirm(@json(__('admin.image_detail.confirm_delete_selected_prefix')) + ' ' + selected + ' ' + @json(__('admin.image_detail.confirm_delete_selected_suffix')));
                if (!confirmed) {
                    event.preventDefault();
                }
            });
        }

        const uploadArea = document.getElementById('upload-area');
        const fileInput = document.getElementById('images');
        const fileList = document.getElementById('file-list');
        const fileItems = document.getElementById('file-items');
        const uploadBtn = document.getElementById('upload-btn');
        const uploadForm = document.getElementById('upload-form');
        const triggerImagePicker = document.getElementById('trigger-image-picker');

        function formatFileSize(bytes) {
            if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2) + ' MB';
            }
            if (bytes >= 1024) {
                return (bytes / 1024).toFixed(2) + ' KB';
            }
            return bytes + ' B';
        }

        function openFilePicker() {
            fileInput?.click();
        }

        function setSelectedFiles(files) {
            if (!fileItems || !fileList || !uploadBtn) {
                return;
            }

            fileItems.innerHTML = '';
            const validFiles = Array.from(files).filter((file) => file.type.startsWith('image/'));
            if (validFiles.length === 0) {
                fileList.classList.add('hidden');
                uploadBtn.disabled = true;
                return;
            }

            validFiles.forEach((file) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between p-2 bg-gray-50 rounded';
                fileItem.innerHTML = `
                    <span class="text-sm text-gray-700">${file.name}</span>
                    <span class="text-xs text-gray-500">${formatFileSize(file.size)}</span>
                `;
                fileItems.appendChild(fileItem);
            });

            fileList.classList.remove('hidden');
            uploadBtn.disabled = false;
        }

        triggerImagePicker?.addEventListener('click', function (event) {
            event.preventDefault();
            openFilePicker();
        });

        uploadArea?.addEventListener('click', function (event) {
            if (event.target.closest('#trigger-image-picker')) {
                return;
            }
            openFilePicker();
        });

        uploadArea?.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openFilePicker();
            }
        });

        uploadArea?.addEventListener('dragover', function (event) {
            event.preventDefault();
            this.classList.add('border-purple-500', 'bg-gray-100');
        });

        uploadArea?.addEventListener('dragleave', function (event) {
            event.preventDefault();
            this.classList.remove('border-purple-500', 'bg-gray-100');
        });

        uploadArea?.addEventListener('drop', function (event) {
            event.preventDefault();
            this.classList.remove('border-purple-500', 'bg-gray-100');
            const files = event.dataTransfer.files;
            const transfer = new DataTransfer();
            Array.from(files).forEach((file) => transfer.items.add(file));
            if (fileInput) {
                fileInput.files = transfer.files;
                setSelectedFiles(fileInput.files);
            }
        });

        fileInput?.addEventListener('change', function () {
            setSelectedFiles(this.files);
        });

        uploadForm?.addEventListener('submit', function (event) {
            const selectedFiles = fileInput?.files ? fileInput.files.length : 0;
            if (selectedFiles === 0) {
                event.preventDefault();
                alert(@json(__('admin.image_detail.error.select_images')));
                return;
            }

            if (uploadBtn) {
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 inline animate-spin"></i>' + @json(__('admin.image_detail.uploading'));
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        });

        window.onclick = function (event) {
            const uploadModal = document.getElementById('upload-modal');
            const editModal = document.getElementById('edit-modal');
            const imageModal = document.getElementById('image-modal');

            if (event.target === uploadModal) {
                hideUploadModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
            if (event.target === imageModal) {
                hideImageModal();
            }
        };
    </script>
@endpush
